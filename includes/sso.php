<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Single sign-on from the LMS into WordPress.
 *
 * Flow:
 *   1. The LMS back end calls GET /generate-sso-link with the shared secret and
 *      receives a signed, short-lived URL for one learner.
 *   2. The learner's browser follows that URL to GET /auth, which verifies the
 *      signature, establishes the WordPress session and redirects.
 *
 * Threat model, and what answers each item:
 *
 *   Tampering with any parameter, the redirect target included
 *       → the HMAC covers all of them, not only the identity
 *   Replaying a captured link
 *       → single-use token, plus a five minute TTL
 *   Guessing or brute-forcing a token
 *       → 128 bits from a CSPRNG, and a per-IP rate limit on /auth
 *   Open redirect, which is the phishing primitive this endpoint would
 *   otherwise hand out
 *       → the target is validated when the link is signed AND when it is used
 *   Escalation if the shared secret ever leaks
 *       → SSO refuses to authenticate any account that can edit or administer
 *
 * The residual risk is the secret itself. `generate-sso-link` must be called
 * server to server. A front end that calls it from the learner's browser puts
 * the secret into public JavaScript, and no amount of signing survives that:
 * anyone could then mint a link for any email address. The endpoint cannot tell
 * the difference, so this is stated here, in the settings screen and in the
 * rollout notes instead of being defended in code that cannot defend it.
 */

if (!defined('ABSPATH')) exit;

/** Short enough that a link in a log file or a shared screenshot is already dead. */
const LWB_SSO_TTL = 300;

/** Requests to /auth allowed per IP per five minutes, against token guessing. */
const LWB_SSO_RATE_LIMIT = 30;

function lwb_sso_signature($email, $timestamp, $token, $redirect_to, $secret) {
    return hash_hmac('sha256', $email . '|' . $timestamp . '|' . $token . '|' . $redirect_to, $secret);
}

/** GET /generate-sso-link: server to server, protected by the shared secret. */
function lwb_generate_sso_link(WP_REST_Request $request) {
    if (!lwb_valid_api_key($request)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 403);
    }

    $email = (string) $request->get_param('email');
    if (!$email || !is_email($email)) {
        return new WP_REST_Response(['error' => 'invalid_email'], 400);
    }

    // Validate the redirect now, so an invalid target is never signed at all.
    $redirect_to = wp_validate_redirect((string) $request->get_param('redirect_to'), home_url('/'));

    $timestamp = time();
    $token     = bin2hex(random_bytes(16));
    $sig       = lwb_sso_signature($email, $timestamp, $token, $redirect_to, lwb_opt('lwb_sso_secret'));
    $endpoint  = lwb_opt('lwb_sso_endpoint') ?: home_url('/wp-json/' . LWB_REST_NS . '/auth');

    $sso_url = add_query_arg([
        'token'       => $token,
        'email'       => rawurlencode($email),
        'ts'          => $timestamp,
        'sig'         => $sig,
        'redirect_to' => rawurlencode($redirect_to),
    ], $endpoint);

    return new WP_REST_Response(['sso_url' => $sso_url, 'expires_in' => LWB_SSO_TTL], 200);
}

/**
 * GET /auth. The learner's browser lands here.
 *
 * Every failure redirects home with a reason code rather than rendering an
 * error. The person following the link is a customer who clicked a button, not
 * an API client, and a JSON error page is a support ticket.
 */
function lwb_handle_sso_login(WP_REST_Request $request) {
    $secret = lwb_opt('lwb_sso_secret');
    if (!$secret) lwb_sso_fail('not_configured');

    if (!lwb_rate_limit_ok('sso_' . lwb_get_client_ip(), LWB_SSO_RATE_LIMIT)) {
        lwb_sso_fail('rate_limited');
    }

    $token       = (string) $request->get_param('token');
    $email       = (string) $request->get_param('email');
    $timestamp   = (string) $request->get_param('ts');
    $sig         = (string) $request->get_param('sig');
    $redirect_to = (string) $request->get_param('redirect_to');

    if (!$token || !$email || !$timestamp || !$sig)   lwb_sso_fail('missing_params');
    if ((time() - intval($timestamp)) > LWB_SSO_TTL)  lwb_sso_fail('token_expired');
    if (get_transient('lwb_sso_used_' . md5($token))) lwb_sso_fail('token_used');

    $expected = lwb_sso_signature($email, $timestamp, $token, $redirect_to, $secret);
    if (!hash_equals($expected, $sig)) lwb_sso_fail('invalid_sig');

    $user = get_user_by('email', $email);
    if (!$user) {
        $user_id = wp_create_user($email, wp_generate_password(24), $email);
        if (is_wp_error($user_id)) lwb_sso_fail('user_create');
        $user = get_user_by('id', $user_id);
        $user->set_role(apply_filters('lwb_sso_new_user_role', 'subscriber', $email));
    }

    // A leaked secret must never become an editor or administrator session.
    // Those accounts sign in through WordPress, where the site's own policy
    // applies: two-factor, SSO, IP restrictions.
    if (user_can($user, 'edit_posts') || user_can($user, 'manage_options')) {
        lwb_sso_fail('privileged_account');
    }

    // Marked spent for longer than the TTL, so a late replay is rejected as
    // "already used" rather than falling through to the expiry branch.
    set_transient('lwb_sso_used_' . md5($token), 1, LWB_SSO_TTL * 2);

    wp_clear_auth_cookie();
    wp_set_current_user($user->ID);
    wp_set_auth_cookie($user->ID, true);

    lwb_save_session($email, $request);

    /**
     * Fires once a learner has been signed in through SSO.
     * Host sites use this to grant membership roles or to record the event.
     */
    do_action('lwb_sso_logged_in', $user, $email);

    // wp_safe_redirect only follows hosts in the whitelist (WordPress and the
    // LMS), so a signature produced elsewhere still cannot send a learner
    // off-site with a session cookie freshly attached.
    wp_safe_redirect($redirect_to ?: home_url('/'));
    exit;
}

function lwb_sso_fail($reason) {
    wp_safe_redirect(home_url('/?sso_error=' . $reason));
    exit;
}

/** Store the SSO login with the marketing attribution that came with it. */
function lwb_save_session($email, WP_REST_Request $request) {
    global $wpdb;
    $wpdb->insert($wpdb->prefix . 'lwb_sessions', [
        'user_email'   => sanitize_email($email),
        'login_date'   => current_time('mysql'),
        'ip_address'   => lwb_get_client_ip(),
        'utm_source'   => sanitize_text_field((string) $request->get_param('utm_source')),
        'utm_medium'   => sanitize_text_field((string) $request->get_param('utm_medium')),
        'utm_campaign' => sanitize_text_field((string) $request->get_param('utm_campaign')),
        'utm_content'  => sanitize_text_field((string) $request->get_param('utm_content')),
        'referrer'     => mb_substr((string) ($_SERVER['HTTP_REFERER'] ?? ''), 0, 500),
        'user_agent'   => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
    ]);
}
