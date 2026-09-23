<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

test('lms hosts include www and bare variants', function () {
    update_option('lwb_lms_url', 'https://www.school.example');
    $hosts = lwb_lms_hosts();
    assert_true(in_array('school.example', $hosts, true));
    assert_true(in_array('www.school.example', $hosts, true));
    assert_eq(2, count($hosts));
});

test('lms hosts is empty when unconfigured', function () {
    assert_eq([], lwb_lms_hosts());
});

test('client ip prefers CDN header, then X-Forwarded-For first hop, then REMOTE_ADDR', function () {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1';
    assert_eq('198.51.100.7', lwb_get_client_ip());
    $_SERVER['HTTP_CF_CONNECTING_IP'] = '2001:db8::1';
    assert_eq('2001:db8::1', lwb_get_client_ip());
    unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    assert_eq('203.0.113.10', lwb_get_client_ip());
});

test('client ip ignores garbage headers', function () {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
    assert_eq('203.0.113.10', lwb_get_client_ip());
});

test('ip round-trips through packed binary for v4 and v6', function () {
    foreach (['10.0.0.1', '192.168.1.1', '2001:db8::1'] as $ip) {
        assert_eq($ip, lwb_ip_str(lwb_ip_bin($ip)));
    }
    assert_same(null, lwb_ip_bin('N/A'));
    assert_eq('N/A', lwb_ip_str(null));
});

test('compromised secret is detected through the filter, by hash only', function () {
    assert_false(lwb_secret_is_compromised('leaked-secret'));
    add_filter('lwb_compromised_secret_hashes', fn($h) => array_merge($h, [hash('sha256', 'leaked-secret')]));
    assert_true(lwb_secret_is_compromised('leaked-secret'));
    assert_false(lwb_secret_is_compromised('fresh-secret'));
    assert_false(lwb_secret_is_compromised(''));
    lwb_test_clear_filter('lwb_compromised_secret_hashes');
});

test('api key is accepted from header, query or json body, constant-time', function () {
    update_option('lwb_sso_secret', 's3cret');
    assert_true(lwb_valid_api_key(new WP_REST_Request([], ['X-LMS-Bridge-Key' => 's3cret'])));
    assert_true(lwb_valid_api_key(new WP_REST_Request(['api_key' => 's3cret'])));
    assert_true(lwb_valid_api_key(new WP_REST_Request([], [], ['api_key' => 's3cret'])));
    assert_false(lwb_valid_api_key(new WP_REST_Request(['api_key' => 'wrong'])));
    assert_false(lwb_valid_api_key(new WP_REST_Request()));
});

test('api key is never valid when no secret is configured', function () {
    assert_false(lwb_valid_api_key(new WP_REST_Request(['api_key' => ''])));
});

test('CORS accepts only the LMS origin over https', function () {
    update_option('lwb_lms_url', 'https://school.example');
    $_SERVER['HTTP_ORIGIN'] = 'https://school.example';
    assert_true(lwb_send_cors_headers());
    $_SERVER['HTTP_ORIGIN'] = 'https://www.school.example';
    assert_true(lwb_send_cors_headers());
    $_SERVER['HTTP_ORIGIN'] = 'http://school.example';
    assert_false(lwb_send_cors_headers(), 'plain http rejected');
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
    assert_false(lwb_send_cors_headers());
    $_SERVER['HTTP_ORIGIN'] = 'https://school.example.evil.example';
    assert_false(lwb_send_cors_headers(), 'suffix attack rejected');
});

test('every REST route is registered under the namespace', function () {
    do_action('rest_api_init');
    foreach (['/auth', '/generate-sso-link', '/sync', '/check-access'] as $r) {
        assert_true(isset($GLOBALS['lwb_test']['routes'][LWB_REST_NS . $r]), "route $r");
    }
});

test('the rate limiter counts per bucket and reopens after the window', function () {
    lwb_test_set_now('2026-01-01 10:00:00');
    for ($i = 0; $i < 5; $i++) assert_true(lwb_rate_limit_ok('a', 5), "request $i");
    assert_false(lwb_rate_limit_ok('a', 5), 'sixth request blocked');
    assert_true(lwb_rate_limit_ok('b', 5), 'a different bucket is unaffected');

    lwb_test_set_now('2026-01-01 10:05:01');
    assert_true(lwb_rate_limit_ok('a', 5), 'window expired');
});

test('activation creates a webhook key and schedules the daily job', function () {
    lwb_activate();
    assert_eq(48, strlen(lwb_opt('lwb_webhook_key')));
    assert_true((bool) wp_next_scheduled('lwb_daily_maintenance'));
    assert_eq(LWB_VERSION, get_option('lwb_db_version'));
});

test('upgrade runs only when the stored version differs', function () {
    update_option('lwb_db_version', '0.9.0');
    lwb_maybe_upgrade();
    assert_eq(LWB_VERSION, get_option('lwb_db_version'));
});
