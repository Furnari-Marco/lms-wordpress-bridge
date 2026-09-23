<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

function lwb_t_enrol(string $email, int $course, string $date, array $extra = []): void {
    global $wpdb;
    $wpdb->insert('wp_lwb_enrollments', array_merge([
        'user_email' => $email, 'course_id' => $course, 'enrollment_date' => $date, 'is_active' => 1,
    ], $extra));
}

test('the headline numbers add up', function () {
    global $wpdb;
    lwb_test_set_now('2026-02-01 00:00:00');
    lwb_t_enrol('ann@example.test', 1, '2026-01-01 00:00:00', ['sale_price' => 10]);
    lwb_t_enrol('ann@example.test', 2, '2026-01-02 00:00:00', ['sale_price' => 20, 'is_active' => 0, 'refunded' => 1]);
    lwb_t_enrol('bob@example.test', 1, '2026-01-03 00:00:00');
    $wpdb->insert('wp_lwb_sessions', ['user_email' => 'ann@example.test', 'login_date' => '2026-01-20 00:00:00']);

    $s = lwb_dashboard_stats();
    assert_eq(2, $s['learners']);
    assert_eq(2, $s['active']);
    assert_eq(3, $s['enrollments']);
    assert_eq(1, $s['refunded']);
    assert_eq(10.0, $s['revenue'], 'a refunded sale is not revenue');
    assert_eq(1, $s['logins_30d']);
    assert_eq('2026-01-03 00:00:00', $s['last_event']);
});

test('a webhook that has gone quiet is measured in hours', function () {
    lwb_test_set_now('2026-02-01 00:00:00');
    assert_same(null, lwb_hours_since_last_event(['last_event' => null]), 'nothing ever received');
    assert_eq(24, round(lwb_hours_since_last_event(['last_event' => '2026-01-31 00:00:00'])));
});

test('the dashboard warns when nothing has ever arrived', function () {
    ob_start(); lwb_page_dashboard(); $html = ob_get_clean();
    assert_contains('No enrollment has ever been received', $html);
});

test('the dashboard warns when the feed has gone stale', function () {
    lwb_test_set_now('2026-02-01 00:00:00');
    lwb_t_enrol('ann@example.test', 1, '2026-01-01 00:00:00');
    ob_start(); lwb_page_dashboard(); $html = ob_get_clean();
    assert_contains('31 days ago', $html);
});

test('a healthy feed produces no warning', function () {
    lwb_test_set_now('2026-02-01 00:00:00');
    lwb_t_enrol('ann@example.test', 1, '2026-01-31 12:00:00');
    ob_start(); lwb_page_dashboard(); $html = ob_get_clean();
    assert_eq(false, strpos($html, 'notice-warning'), 'no warning banner');
    assert_contains('ann@example.test', $html);
});
