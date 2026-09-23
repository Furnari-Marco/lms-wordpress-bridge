<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

function lwb_t_sso_setup(): void {
    update_option('lwb_sso_secret', 'shared-secret');
    update_option('lwb_lms_url', 'https://school.example');
}

/** Generate a link through the real endpoint and return its query parameters. */
function lwb_t_sso_link(string $email, string $redirect = ''): array {
    $r = lwb_generate_sso_link(new WP_REST_Request(['email' => $email, 'redirect_to' => $redirect], ['X-LMS-Bridge-Key' => 'shared-secret']));
    assert_eq(200, $r->status, 'generate-sso-link');
    parse_str(parse_url($r->data['sso_url'], PHP_URL_QUERY), $q);
    return $q;
}

function lwb_t_sso_login(array $q): string {
    return lwb_test_redirect(fn() => lwb_handle_sso_login(new WP_REST_Request($q)));
}

test('generate-sso-link requires the shared secret', function () {
    lwb_t_sso_setup();
    assert_eq(403, lwb_generate_sso_link(new WP_REST_Request(['email' => 'a@b.test']))->status);
    assert_eq(403, lwb_generate_sso_link(new WP_REST_Request(['email' => 'a@b.test', 'api_key' => 'nope']))->status);
});

test('generate-sso-link validates the email', function () {
    lwb_t_sso_setup();
    assert_eq(400, lwb_generate_sso_link(new WP_REST_Request(['email' => 'nope', 'api_key' => 'shared-secret']))->status);
});

test('generate-sso-link signs email, timestamp, token and redirect', function () {
    lwb_t_sso_setup();
    $q = lwb_t_sso_link('ann@example.test', 'https://school.example/courses/1');
    assert_eq('ann@example.test', $q['email']);
    assert_eq('https://school.example/courses/1', $q['redirect_to']);
    assert_eq(32, strlen($q['token']));
    assert_eq(lwb_sso_signature($q['email'], $q['ts'], $q['token'], $q['redirect_to'], 'shared-secret'), $q['sig']);
});

test('generate-sso-link refuses to sign a redirect to a foreign host', function () {
    lwb_t_sso_setup();
    $q = lwb_t_sso_link('ann@example.test', 'https://evil.example/phish');
    assert_eq('https://wp.example.test/', $q['redirect_to'], 'falls back to home');
});

test('full round trip: link → login → new subscriber, cookie set, session and event stored', function () {
    global $wpdb;
    lwb_t_sso_setup();
    $q = lwb_t_sso_link('ann@example.test', 'https://school.example/courses/1');
    $q['utm_source'] = 'newsletter';
    $location = lwb_t_sso_login($q);

    assert_eq('https://school.example/courses/1', $location);
    $user = get_user_by('email', 'ann@example.test');
    assert_true((bool) $user, 'user created');
    assert_eq(['subscriber'], $user->roles);
    assert_eq($user->ID, $GLOBALS['lwb_test']['auth_cookie_for']);
    assert_eq(1, $wpdb->count('lwb_sessions', "utm_source = 'newsletter'"));
});

test('existing user is logged in without being recreated', function () {
    lwb_t_sso_setup();
    $u = lwb_test_add_user('ann@example.test');
    lwb_t_sso_login(lwb_t_sso_link('ann@example.test'));
    assert_eq(1, count($GLOBALS['lwb_test']['users']));
    assert_eq($u->ID, $GLOBALS['lwb_test']['auth_cookie_for']);
});

test('a token can be used only once', function () {
    lwb_t_sso_setup();
    $q = lwb_t_sso_link('ann@example.test');
    lwb_t_sso_login($q);
    $GLOBALS['lwb_test']['auth_cookie_for'] = null;
    assert_contains('sso_error=token_used', lwb_t_sso_login($q));
    assert_same(null, $GLOBALS['lwb_test']['auth_cookie_for'], 'no second login');
});

test('replay is still rejected after the TTL has passed', function () {
    lwb_t_sso_setup();
    lwb_test_set_now('2026-01-01 10:00:00');
    $q = lwb_t_sso_link('ann@example.test');
    lwb_t_sso_login($q);
    lwb_test_set_now('2026-01-01 10:06:00');
    // Either "used" (marker still alive) or "expired", but never a login.
    $loc = lwb_t_sso_login($q);
    assert_true(strpos($loc, 'sso_error=') !== false, $loc);
});

test('expired link is rejected', function () {
    lwb_t_sso_setup();
    $q = lwb_t_sso_link('ann@example.test');
    $q['ts'] = (string) (time() - LWB_SSO_TTL - 1);
    $q['sig'] = lwb_sso_signature($q['email'], $q['ts'], $q['token'], $q['redirect_to'], 'shared-secret');
    assert_contains('sso_error=token_expired', lwb_t_sso_login($q));
});

test('tampering with any signed field invalidates the link', function () {
    lwb_t_sso_setup();
    foreach (['email' => 'bob@example.test', 'redirect_to' => 'https://school.example/other', 'token' => str_repeat('0', 32), 'sig' => str_repeat('0', 64)] as $field => $value) {
        $q = lwb_t_sso_link('ann@example.test', 'https://school.example/courses/1');
        $q[$field] = $value;
        assert_contains('sso_error=invalid_sig', lwb_t_sso_login($q), "tampered $field");
    }
});

test('open redirect is closed even with a valid signature', function () {
    lwb_t_sso_setup();
    // Sign a foreign redirect directly (bypassing generate-sso-link) to prove /auth checks it too.
    $q = ['email' => 'ann@example.test', 'ts' => (string) time(), 'token' => bin2hex(random_bytes(16)), 'redirect_to' => 'https://evil.example/'];
    $q['sig'] = lwb_sso_signature($q['email'], $q['ts'], $q['token'], $q['redirect_to'], 'shared-secret');
    assert_eq('https://wp.example.test/', lwb_t_sso_login($q));
});

test('privileged accounts can never be logged in through SSO', function () {
    lwb_t_sso_setup();
    lwb_test_add_user('admin@example.test', ['administrator']);
    lwb_test_add_user('editor@example.test', ['editor']);
    foreach (['admin@example.test', 'editor@example.test'] as $email) {
        assert_contains('sso_error=privileged_account', lwb_t_sso_login(lwb_t_sso_link($email)));
        assert_same(null, $GLOBALS['lwb_test']['auth_cookie_for']);
    }
});

test('missing parameters and unconfigured secret fail closed', function () {
    lwb_t_sso_setup();
    assert_contains('sso_error=missing_params', lwb_t_sso_login(['email' => 'a@b.test']));
    update_option('lwb_sso_secret', '');
    assert_contains('sso_error=not_configured', lwb_t_sso_login(['email' => 'a@b.test']));
});

test('brute forcing tokens from one IP is throttled', function () {
    lwb_t_sso_setup();
    lwb_test_set_now('2026-01-01 10:00:00');

    // A wrong signature is cheap to try, so the endpoint has to make it slow.
    $guess = ['email' => 'ann@example.test', 'ts' => (string) time(),
              'token' => str_repeat('0', 32), 'sig' => str_repeat('0', 64), 'redirect_to' => ''];
    for ($i = 0; $i < LWB_SSO_RATE_LIMIT; $i++) {
        assert_contains('sso_error=invalid_sig', lwb_t_sso_login($guess), "attempt $i");
    }
    assert_contains('sso_error=rate_limited', lwb_t_sso_login($guess));

    // A learner behind a different address is unaffected.
    $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
    assert_eq('https://wp.example.test/', lwb_t_sso_login(lwb_t_sso_link('ann@example.test')));
});

test('the rate limit does not lock a legitimate learner out for ever', function () {
    lwb_t_sso_setup();
    lwb_test_set_now('2026-01-01 10:00:00');
    $guess = ['email' => 'a@b.test', 'ts' => (string) time(),
              'token' => str_repeat('0', 32), 'sig' => str_repeat('0', 64), 'redirect_to' => ''];
    for ($i = 0; $i <= LWB_SSO_RATE_LIMIT; $i++) lwb_t_sso_login($guess);

    lwb_test_set_now('2026-01-01 10:05:01');
    assert_eq('https://wp.example.test/', lwb_t_sso_login(lwb_t_sso_link('ann@example.test')));
});

test('a new user gets the role the site chooses', function () {
    lwb_t_sso_setup();
    add_filter('lwb_sso_new_user_role', fn($role) => 'customer');
    lwb_t_sso_login(lwb_t_sso_link('ann@example.test'));
    assert_eq(['customer'], get_user_by('email', 'ann@example.test')->roles);
    lwb_test_clear_filter('lwb_sso_new_user_role');
});

test('a successful login fires the hook a host site can extend', function () {
    lwb_t_sso_setup();
    $seen = [];
    add_action('lwb_sso_logged_in', function ($user, $email) use (&$seen) { $seen[] = $email; });
    lwb_t_sso_login(lwb_t_sso_link('ann@example.test'));
    assert_eq(['ann@example.test'], $seen);
    lwb_test_clear_filter('lwb_sso_logged_in');
});

test('the generated link advertises its own lifetime', function () {
    lwb_t_sso_setup();
    $r = lwb_generate_sso_link(new WP_REST_Request(['email' => 'ann@example.test'], ['X-LMS-Bridge-Key' => 'shared-secret']));
    assert_eq(LWB_SSO_TTL, $r->data['expires_in']);
});
