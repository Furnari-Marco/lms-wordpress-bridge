<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
declare(strict_types=1);

function lwb_t_access_setup(): void {
    global $wpdb;
    update_option('lwb_sso_secret', 'shared-secret');
    $wpdb->insert('wp_lwb_enrollments', ['user_email' => 'ann@example.test', 'user_name' => 'Ann', 'lms_user_id' => 501, 'course_id' => 7, 'course_name' => 'Course 7', 'enrollment_date' => '2026-01-05 10:00:00', 'is_active' => 1, 'percent_complete' => 30]);
    $wpdb->insert('wp_lwb_enrollments', ['user_email' => 'ann@example.test', 'course_id' => 8, 'is_active' => 0]);
    $wpdb->insert('wp_lwb_enrollments', ['user_email' => 'ann@example.test', 'course_id' => 9, 'is_active' => 1, 'refunded' => 1]);
}

function lwb_t_check(string $email, int $course, string $key = 'shared-secret'): WP_REST_Response {
    return lwb_check_course_access(new WP_REST_Request(['email' => $email, 'course_id' => $course], ['X-LMS-Bridge-Key' => $key]));
}

test('check-access requires the shared secret', function () {
    lwb_t_access_setup();
    assert_eq(403, lwb_t_check('ann@example.test', 7, 'wrong')->status);
});

test('check-access returns the enrollment for an active, non-refunded course', function () {
    lwb_t_access_setup();
    $r = lwb_t_check('ann@example.test', 7);
    assert_eq(200, $r->status);
    assert_true($r->data['has_access']);
    assert_eq('Course 7', $r->data['course_name']);
    assert_eq(30, $r->data['percent_complete']);
});

test('check-access denies inactive, refunded and unknown enrollments', function () {
    lwb_t_access_setup();
    foreach ([8, 9, 10] as $course) {
        $r = lwb_t_check('ann@example.test', $course);
        assert_eq(200, $r->status);
        assert_false($r->data['has_access'], "course $course");
    }
    assert_false(lwb_t_check('bob@example.test', 7)->data['has_access']);
});

test('check-access validates parameters', function () {
    lwb_t_access_setup();
    assert_eq(400, lwb_t_check('ann@example.test', 0)->status);
    assert_eq(400, lwb_t_check('', 7)->status);
});

test('the email is matched case-insensitively', function () {
    lwb_t_access_setup();
    assert_true(lwb_t_check('ANN@Example.test', 7)->data['has_access']);
});

// ---------------------------------------------------------------------------
// Caching
// ---------------------------------------------------------------------------

test('a repeated check is answered from the cache, not the database', function () {
    global $wpdb;
    lwb_t_access_setup();

    lwb_entitlement('ann@example.test', 7);
    $after_first = count($wpdb->queries);
    lwb_entitlement('ann@example.test', 7);
    lwb_entitlement('ann@example.test', 7);

    assert_eq($after_first, count($wpdb->queries), 'no further queries');
    assert_eq(2, lwb_test_cache_counts()['hits']);
});

test('a learner without the course is cached too', function () {
    global $wpdb;
    lwb_t_access_setup();

    assert_same(null, lwb_entitlement('ann@example.test', 999));
    $after_first = count($wpdb->queries);
    assert_same(null, lwb_entitlement('ann@example.test', 999));
    assert_eq($after_first, count($wpdb->queries), 'a miss must not be re-queried every time');
});

test('a webhook clears the cached answer it invalidates', function () {
    lwb_t_access_setup();
    assert_true((bool) lwb_entitlement('ann@example.test', 7));

    lwb_handle_webhook(new WP_REST_Request([], [], [
        'type'   => 'Transaction.refunded',
        'object' => ['user' => ['email' => 'ann@example.test'], 'course_id' => 7],
    ]));

    assert_same(null, lwb_entitlement('ann@example.test', 7), 'the refund is visible immediately');
});

test('flushing one learner leaves the others cached', function () {
    global $wpdb;
    lwb_t_access_setup();
    $wpdb->insert('wp_lwb_enrollments', ['user_email' => 'bob@example.test', 'course_id' => 7, 'is_active' => 1]);

    lwb_entitlement('ann@example.test', 7);
    lwb_entitlement('bob@example.test', 7);
    lwb_flush_entitlement('ann@example.test');

    $before = count($wpdb->queries);
    lwb_entitlement('bob@example.test', 7);
    assert_eq($before, count($wpdb->queries), "bob's answer survived");
});

test('the template helper follows the logged-in user', function () {
    lwb_t_access_setup();
    assert_false(lwb_current_user_has_course(7), 'signed out: never entitled');

    $user = lwb_test_add_user('ann@example.test');
    wp_set_current_user($user->ID);
    assert_true(lwb_current_user_has_course(7));
    assert_false(lwb_current_user_has_course(8), 'inactive enrollment');
});
