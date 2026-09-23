<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Shared helpers: options, host whitelist, client IP, rate limiting, secret
 * hygiene, CORS.
 */

if (!defined('ABSPATH')) exit;

/**
 * Fixed-window rate limit, backed by transients.
 *
 * Transients are used rather than a table because they land in the object cache
 * when one is available (so the check costs nothing on a host like VIP) and
 * degrade to the options table when it is not. The window is fixed rather than
 * sliding: at a boundary a caller can briefly exceed the nominal rate, which is
 * acceptable for endpoints where the limit exists to make brute force slow
 * rather than to meter usage precisely.
 */
function lwb_rate_limit_ok($bucket, $limit, $window = 300) {
    $key   = 'lwb_rl_' . md5((string) $bucket);
    $count = (int) get_transient($key);
    if ($count >= $limit) return false;
    set_transient($key, $count + 1, $window);
    return true;
}

/**
 * Read a plugin option. No secret is ever hardcoded: everything lives in
 * wp_options and is managed from the Settings page.
 */
function lwb_opt($key, $default = '') {
    $v = get_option($key, $default);
    return is_string($v) ? trim($v) : $v;
}

/**
 * Hostnames of the LMS school, with and without "www.".
 * Used both for CORS and for the SSO redirect whitelist.
 */
function lwb_lms_hosts() {
    $hosts  = [];
    $school = parse_url(lwb_opt('lwb_lms_url'), PHP_URL_HOST);
    if ($school) {
        $hosts[] = $school;
        $hosts[] = (strpos($school, 'www.') === 0) ? substr($school, 4) : 'www.' . $school;
    }
    return array_values(array_unique(apply_filters('lwb_lms_hosts', $hosts)));
}

/**
 * Secrets known to be compromised (e.g. found in an old repository or a
 * leaked backup) are registered by SHA-256 hash, never in clear text.
 * The Settings page and admin notices warn when one is still in use.
 *
 * Operators extend the list with:
 *   add_filter('lwb_compromised_secret_hashes', fn($h) => array_merge($h, ['<sha256>']));
 */
function lwb_secret_is_compromised($value) {
    if (!$value) return false;
    $hashes = apply_filters('lwb_compromised_secret_hashes', []);
    return in_array(hash('sha256', $value), $hashes, true);
}

/**
 * Best-effort client IP behind a CDN / reverse proxy. Order matters: the
 * CDN header is set by infrastructure we control, X-Forwarded-For can be
 * appended by anyone. The result is only ever used for logging and
 * rate limiting, never for authorization on its own.
 */
function lwb_get_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return 'N/A';
}

/** IPs are stored as packed binary (4 or 16 bytes) to keep the events table small. */
function lwb_ip_bin($ip) {
    $b = @inet_pton($ip);
    return $b === false ? null : $b;
}

function lwb_ip_str($bin) {
    if ($bin === null || $bin === '') return 'N/A';
    $s = @inet_ntop($bin);
    return $s === false ? 'N/A' : $s;
}

/**
 * Shared-secret check for server-to-server endpoints. The key may travel
 * in a header (preferred), a query parameter or the JSON body; comparison
 * is constant-time.
 */
function lwb_valid_api_key(WP_REST_Request $request) {
    $secret = lwb_opt('lwb_sso_secret');
    if (!$secret) return false;
    $key = (string) ($request->get_header('x-lms-bridge-key') ?: $request->get_param('api_key'));
    if (!$key) {
        $p   = $request->get_json_params();
        $key = (string) ($p['api_key'] ?? '');
    }
    return $key !== '' && hash_equals($secret, $key);
}

/**
 * Emit CORS headers only for the LMS origin over HTTPS.
 * Returns true when the origin was accepted (used to answer preflights).
 */
function lwb_send_cors_headers() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host   = $origin ? parse_url($origin, PHP_URL_HOST) : '';
    if (!$host || !in_array($host, lwb_lms_hosts(), true) || strpos($origin, 'https://') !== 0) {
        return false;
    }
    if (!headers_sent()) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-LMS-Bridge-Key');
    }
    return true;
}
