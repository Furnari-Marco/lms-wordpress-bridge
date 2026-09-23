<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

function lwb_t_hook(string $type, array $object, array $params = [], array $headers = []): WP_REST_Response {
    return lwb_handle_webhook(new WP_REST_Request($params, $headers, ['type' => $type, 'object' => $object]));
}

function lwb_t_enroll(string $email, int $course, array $extra = []): void {
    lwb_t_hook('Enrollment.created', array_merge([
        'user' => ['email' => $email, 'name' => 'Ann', 'id' => 501, 'src' => 'google', 'created_at' => '2025-12-01T09:00:00Z'],
        'course' => ['id' => $course, 'name' => "Course $course"],
        'enrolled_at' => '2026-01-05T10:00:00Z',
    ], $extra));
}

test('enrollment.created inserts a row and a person', function () {
    global $wpdb;
    assert_eq(200, lwb_t_hook('Enrollment.created', ['user' => ['email' => 'ann@example.test', 'name' => 'Ann', 'id' => 501], 'course' => ['id' => 7, 'name' => 'Course 7'], 'enrolled_at' => '2026-01-05T10:00:00Z'])->status);
    $row = $wpdb->get_row("SELECT * FROM wp_lwb_enrollments");
    assert_eq('ann@example.test', $row->user_email);
    assert_eq(7, $row->course_id);
    assert_eq('Course 7', $row->course_name);
    assert_eq('2026-01-05 10:00:00', $row->enrollment_date);
    assert_eq(1, $row->is_active);
    assert_eq(501, $row->lms_user_id, 'the LMS id is kept so support can cross-reference');
});

test('re-enrollment is a selective upsert: the sale and the progress survive', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_hook('Sale.created', ['user' => ['email' => 'ann@example.test'], 'course' => ['id' => 7], 'amount' => 199, 'currency' => 'eur']);
    $wpdb->query("UPDATE wp_lwb_enrollments SET percent_complete = 40");
    lwb_t_hook('Enrollment.disabled', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7]);
    assert_eq(0, $wpdb->get_var("SELECT is_active FROM wp_lwb_enrollments"));

    lwb_t_enroll('ann@example.test', 7);
    assert_eq(1, $wpdb->count('lwb_enrollments'), 'still one row');
    $row = $wpdb->get_row("SELECT * FROM wp_lwb_enrollments");
    assert_eq(1, $row->is_active);
    assert_eq(199, $row->sale_price);
    assert_eq('EUR', $row->sale_currency);
    assert_eq(40, $row->percent_complete);
});

test('legacy event names are accepted', function () {
    global $wpdb;
    lwb_t_hook('New Enrollment', ['user' => ['email' => 'ann@example.test'], 'course' => ['id' => 7]]);
    lwb_t_hook('Lesson Completed', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7, 'percent_complete' => 55]);
    lwb_t_hook('Enrollment Completed', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7]);
    $row = $wpdb->get_row("SELECT * FROM wp_lwb_enrollments");
    assert_eq(100, $row->percent_complete);
    assert_true((bool) $row->completed_date);
});

test('sale with a course id touches only that enrollment', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_enroll('ann@example.test', 8);
    lwb_t_hook('Sale.created', ['user' => ['email' => 'ann@example.test'], 'course_id' => 8, 'amount' => 49.5, 'currency' => 'USD', 'created_at' => '2026-02-01T00:00:00Z']);
    assert_same(null, $wpdb->get_var("SELECT sale_price FROM wp_lwb_enrollments WHERE course_id = 7"));
    assert_eq(49.5, $wpdb->get_var("SELECT sale_price FROM wp_lwb_enrollments WHERE course_id = 8"));
});

test('sale without a course id fills only rows without a price, never overwrites', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_enroll('ann@example.test', 8);
    lwb_t_hook('Sale.created', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7, 'amount' => 100]);
    lwb_t_hook('Sale.created', ['email' => 'ann@example.test', 'amount' => 20]);
    assert_eq(100, $wpdb->get_var("SELECT sale_price FROM wp_lwb_enrollments WHERE course_id = 7"), 'earlier sale kept');
    assert_eq(20, $wpdb->get_var("SELECT sale_price FROM wp_lwb_enrollments WHERE course_id = 8"));
});

test('refund with a course id deactivates only that enrollment', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_enroll('ann@example.test', 8);
    lwb_t_hook('Transaction.refunded', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7]);
    $r7 = $wpdb->get_row("SELECT * FROM wp_lwb_enrollments WHERE course_id = 7");
    $r8 = $wpdb->get_row("SELECT * FROM wp_lwb_enrollments WHERE course_id = 8");
    assert_eq([0, 1], [$r7->is_active, $r7->refunded]);
    assert_true((bool) $r7->refund_date);
    assert_eq([1, 0], [$r8->is_active, $r8->refunded]);
});

test('refund without a course id hits only the most recent paid purchase', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_enroll('ann@example.test', 8);
    lwb_t_enroll('ann@example.test', 9);   // never paid
    lwb_t_hook('Sale.created', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7, 'amount' => 10, 'created_at' => '2026-01-01T00:00:00Z']);
    lwb_t_hook('Sale.created', ['user' => ['email' => 'ann@example.test'], 'course_id' => 8, 'amount' => 20, 'created_at' => '2026-02-01T00:00:00Z']);
    lwb_t_hook('Transaction.refunded', ['email' => 'ann@example.test']);
    assert_eq(1, $wpdb->get_var("SELECT refunded FROM wp_lwb_enrollments WHERE course_id = 8"), 'latest purchase refunded');
    assert_eq(0, $wpdb->get_var("SELECT refunded FROM wp_lwb_enrollments WHERE course_id = 7"));
    assert_eq(0, $wpdb->get_var("SELECT refunded FROM wp_lwb_enrollments WHERE course_id = 9"));
    assert_eq(1, $wpdb->get_var("SELECT is_active FROM wp_lwb_enrollments WHERE course_id = 9"));
});

test('user.created stores registration data before any enrollment, once', function () {
    global $wpdb;
    lwb_t_hook('User.created', ['email' => 'new@example.test', 'name' => 'New', 'id' => 900, 'created_at' => '2026-03-01T12:00:00Z', 'src' => 'ads']);
    lwb_t_hook('User.created', ['email' => 'new@example.test', 'name' => 'New', 'id' => 900]);
    assert_eq(1, $wpdb->count('lwb_enrollments', "user_email = 'new@example.test' AND course_id IS NULL"));
    assert_eq('2026-03-01 12:00:00', $wpdb->get_var("SELECT registration_date FROM wp_lwb_enrollments"));
    assert_eq(900, $wpdb->get_var("SELECT lms_user_id FROM wp_lwb_enrollments WHERE user_email = 'new@example.test'"));
});

test('user.updated renames every enrollment of the learner', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_enroll('ann@example.test', 8);
    lwb_t_hook('User.updated', ['email' => 'ann@example.test', 'name' => 'Ann Renamed']);
    assert_eq(2, $wpdb->count('lwb_enrollments', "user_name = 'Ann Renamed'"));
});

test('subscription.cancelled deactivates the enrollment', function () {
    global $wpdb;
    lwb_t_enroll('ann@example.test', 7);
    lwb_t_hook('Subscription.cancelled', ['user' => ['email' => 'ann@example.test'], 'course_id' => 7]);
    assert_eq(0, $wpdb->get_var("SELECT is_active FROM wp_lwb_enrollments"));
});

test('unknown events and malformed payloads are acknowledged with 200', function () {
    global $wpdb;
    assert_eq(200, lwb_t_hook('Something.else', [])->status);
    assert_eq(200, lwb_t_hook('Enrollment.created', ['user' => ['email' => 'bad'], 'course' => []])->status);
    assert_eq(200, lwb_handle_webhook(new WP_REST_Request([], [], ['type' => 'Enrollment.created', 'object' => 'not-an-array']))->status);
    assert_eq(0, $wpdb->count('lwb_enrollments'));
});

test('webhook key is optional until enforcement is switched on', function () {
    update_option('lwb_webhook_key', 'hook-key');
    assert_eq(200, lwb_t_hook('Something', [])->status, 'enforcement off: accepted without key');

    update_option('lwb_webhook_enforce', '1');
    assert_eq(403, lwb_t_hook('Something', [])->status);
    assert_eq(403, lwb_t_hook('Something', [], ['key' => 'wrong'])->status);
    assert_eq(200, lwb_t_hook('Something', [], ['key' => 'hook-key'])->status, 'query key');
    assert_eq(200, lwb_t_hook('Something', [], [], ['X-LMS-Bridge-Webhook-Key' => 'hook-key'])->status, 'header key');
});

test('enforcement with an empty key rejects everything (fails closed)', function () {
    update_option('lwb_webhook_enforce', '1');
    update_option('lwb_webhook_key', '');
    assert_eq(403, lwb_t_hook('Something', [], ['key' => ''])->status);
});
