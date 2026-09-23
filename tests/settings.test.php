<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

test('settings are saved with sanitisation and floors', function () {
    lwb_save_settings([
        'lwb_lms_url'         => ' https://school.example ',
        'lwb_sso_secret'      => 'new<b>secret</b>',
        'lwb_webhook_key'     => 'k',
        'lwb_webhook_enforce' => 'on',
        'lwb_retention_years' => '0',
    ]);
    assert_eq('https://school.example', lwb_opt('lwb_lms_url'));
    assert_eq('newsecret', lwb_opt('lwb_sso_secret'));
    assert_eq('1', lwb_opt('lwb_webhook_enforce'));
    assert_eq(1, lwb_opt('lwb_retention_years'), 'floored at one year');
});

test('an unchecked box is saved as off, not left at its previous value', function () {
    update_option('lwb_webhook_enforce', '1');
    lwb_save_settings(['lwb_lms_url' => 'https://school.example']);   // the checkbox is simply absent
    assert_eq('0', lwb_opt('lwb_webhook_enforce'), 'unticking must actually untick');
});

test('settings page renders and refuses a POST without a valid nonce', function () {
    update_option('lwb_webhook_key', 'hook-key');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['lwb_settings_nonce' => 'bad', 'lwb_sso_secret' => 'injected'];
    ob_start(); lwb_page_settings(); $html = ob_get_clean();
    assert_eq('', lwb_opt('lwb_sso_secret'), 'not saved');
    assert_contains('/wp-json/lms-bridge/v1/sync?key=hook-key', $html);

    $_POST['lwb_settings_nonce'] = 'nonce';
    ob_start(); lwb_page_settings(); $html = ob_get_clean();
    assert_eq('injected', lwb_opt('lwb_sso_secret'));
    assert_contains('Settings saved', $html);
});

test('settings page warns about a compromised secret', function () {
    update_option('lwb_sso_secret', 'old');
    add_filter('lwb_compromised_secret_hashes', fn($h) => [hash('sha256', 'old')]);
    ob_start(); lwb_page_settings(); $html = ob_get_clean();
    assert_contains('registered as compromised', $html);
    lwb_test_clear_filter('lwb_compromised_secret_hashes');
});
