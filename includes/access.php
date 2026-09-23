<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Entitlements: "does this learner have access to this course?"
 *
 * Answered from the local mirror, never by calling the LMS API. The LMS owns
 * the truth, but asking it on every page view would put a third-party network
 * call on the critical path of the site, and an outage there would become an
 * outage here. Webhooks keep the mirror current; this file only reads it.
 *
 * The answer is cached in the object cache and invalidated by the webhook that
 * changes it, so a membership check costs one cache read on a warm site and one
 * indexed query on a cold one.
 */

if (!defined('ABSPATH')) exit;

const LWB_CACHE_GROUP = 'lwb_entitlements';
const LWB_CACHE_TTL   = 3600;

// On multisite the mirror is per-site, so the group is deliberately not global.
add_action('init', function () {
    if (function_exists('wp_cache_add_non_persistent_groups') && !wp_using_ext_object_cache()) {
        // Without a persistent object cache, transient-backed caching across
        // requests would write to the options table on every miss. In-request
        // caching only is the safer default there.
        wp_cache_add_non_persistent_groups([LWB_CACHE_GROUP]);
    }
});

/**
 * The entitlement row for one learner and course, or null.
 *
 * Returns the row rather than a boolean: callers want the course name and the
 * progress too, and a second query for them would defeat the cache.
 */
function lwb_entitlement($email, $course_id) {
    global $wpdb;

    $email     = strtolower(sanitize_email($email));
    $course_id = intval($course_id);
    if (!$email || !$course_id) return null;

    $key    = lwb_entitlement_cache_key($email, $course_id);
    $cached = wp_cache_get($key, LWB_CACHE_GROUP);
    if ($cached !== false) {
        // A miss is cached as the string 'none' so that repeated checks for a
        // course somebody has not bought do not each hit the database.
        return $cached === 'none' ? null : $cached;
    }

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT user_name, course_name, enrollment_date, percent_complete
         FROM {$wpdb->prefix}lwb_enrollments
         WHERE user_email = %s AND course_id = %d AND is_active = 1 AND refunded = 0",
        $email, $course_id
    ));

    wp_cache_set($key, $row ?: 'none', LWB_CACHE_GROUP, LWB_CACHE_TTL);
    return $row;
}

function lwb_entitlement_cache_key($email, $course_id) {
    return 'ent_' . md5(strtolower((string) $email)) . '_' . intval($course_id);
}

/**
 * Drop the cached answer for a learner.
 *
 * Called by the webhook handler after any write. With a course id it clears one
 * key; without one the learner's rows are looked up and cleared individually,
 * because flushing the whole group would discard every other learner's answer
 * over a single refund.
 */
function lwb_flush_entitlement($email, $course_id = 0) {
    global $wpdb;

    $email = strtolower(sanitize_email($email));
    if (!$email) return;

    if ($course_id) {
        wp_cache_delete(lwb_entitlement_cache_key($email, $course_id), LWB_CACHE_GROUP);
        return;
    }

    $course_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT course_id FROM {$wpdb->prefix}lwb_enrollments WHERE user_email = %s AND course_id IS NOT NULL",
        $email
    ));
    foreach ($course_ids as $id) {
        wp_cache_delete(lwb_entitlement_cache_key($email, $id), LWB_CACHE_GROUP);
    }
}

/** GET /check-access: server to server, protected by the shared secret. */
function lwb_check_course_access(WP_REST_Request $request) {
    if (!lwb_valid_api_key($request)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 403);
    }

    $email     = sanitize_email($request->get_param('email'));
    $course_id = intval($request->get_param('course_id'));
    if (!$email || !$course_id) {
        return new WP_REST_Response(['error' => 'missing_params'], 400);
    }

    $row = lwb_entitlement($email, $course_id);
    if (!$row) {
        return new WP_REST_Response(['has_access' => false], 200);
    }

    return new WP_REST_Response([
        'has_access'       => true,
        'course_name'      => $row->course_name,
        'enrollment_date'  => $row->enrollment_date,
        'user_name'        => $row->user_name,
        'percent_complete' => (int) $row->percent_complete,
    ], 200);
}

/**
 * Template helper: does the current visitor have access to a course?
 *
 * The site uses this to gate content. It reads the email from the WordPress
 * user, so a signed-out visitor is simply not entitled.
 */
function lwb_current_user_has_course($course_id) {
    $user = wp_get_current_user();
    if (!$user || !$user->ID) return false;
    return (bool) lwb_entitlement($user->user_email, $course_id);
}
