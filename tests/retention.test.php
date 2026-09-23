<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

test('sign-in records past the horizon are deleted, recent ones are kept', function () {
    global $wpdb;
    lwb_test_set_now('2026-06-01 00:00:00');
    update_option('lwb_retention_years', '2');

    $wpdb->insert('wp_lwb_sessions', ['user_email' => 'ann@example.test', 'login_date' => '2023-01-01 00:00:00']);
    $wpdb->insert('wp_lwb_sessions', ['user_email' => 'ann@example.test', 'login_date' => '2025-01-01 00:00:00']);

    lwb_daily_maintenance();

    assert_eq(1, $wpdb->count('lwb_sessions'));
    assert_eq('2025-01-01 00:00:00', $wpdb->get_var("SELECT login_date FROM wp_lwb_sessions"));
});

test('enrollments are never touched: they are the record of a purchase', function () {
    global $wpdb;
    lwb_test_set_now('2026-06-01 00:00:00');
    update_option('lwb_retention_years', '1');
    $wpdb->insert('wp_lwb_enrollments', ['user_email' => 'ann@example.test', 'course_id' => 7,
                                         'enrollment_date' => '2015-01-01 00:00:00', 'is_active' => 1]);

    lwb_daily_maintenance();

    assert_eq(1, $wpdb->count('lwb_enrollments'), 'a ten year old enrollment still grants access');
});

test('deletion runs in batches and stops as soon as there is nothing left', function () {
    global $wpdb;
    lwb_test_set_now('2026-06-01 00:00:00');
    for ($i = 0; $i < 7; $i++) {
        $wpdb->insert('wp_lwb_sessions', ['user_email' => "u$i@example.test", 'login_date' => '2019-01-01 00:00:00']);
    }
    $wpdb->queries = [];
    lwb_daily_maintenance();

    assert_eq(0, $wpdb->count('lwb_sessions'));
    $deletes = array_filter($wpdb->queries, fn($q) => stripos($q, 'DELETE FROM wp_lwb_sessions') === 0);
    assert_eq(2, count($deletes), 'one batch that deletes, one that finds nothing and breaks');
});

test('the retention window is floored at one year', function () {
    global $wpdb;
    lwb_test_set_now('2026-06-01 00:00:00');
    update_option('lwb_retention_years', '0');
    $wpdb->insert('wp_lwb_sessions', ['user_email' => 'ann@example.test', 'login_date' => '2025-09-01 00:00:00']);

    lwb_daily_maintenance();

    assert_eq(1, $wpdb->count('lwb_sessions'), 'nine months old: kept');
});
