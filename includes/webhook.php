<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * POST /sync: inbound webhooks from the LMS.
 *
 * The LMS owns the truth; this handler keeps a local mirror of enrollments and
 * sales, so WordPress can answer "does this learner have access to course X?"
 * without calling the LMS API on every request.
 *
 * Two properties matter more than the individual handlers:
 *
 *   Idempotence  every handler is an upsert keyed on (email, course_id).
 *                Webhook platforms re-deliver; the same event arriving twice
 *                has to converge, not duplicate.
 *   Tolerance    an event this version does not know is still acknowledged.
 *
 * Event names cover the two formats the platform has used over time: the
 * dotted "Enrollment.created" and the older "New Enrollment".
 */

if (!defined('ABSPATH')) exit;

/**
 * Staged enforcement: the key check is a switch, off by default. This lets
 * an operator (1) install the plugin, (2) update the webhook URL on the LMS
 * side to include ?key=..., (3) flip the switch, with no window in which
 * legitimate webhooks are rejected.
 */
function lwb_webhook_authorized(WP_REST_Request $request) {
    if (lwb_opt('lwb_webhook_enforce') !== '1') return true;
    $expected = lwb_opt('lwb_webhook_key');
    $key = (string) ($request->get_param('key') ?: $request->get_header('x-lms-bridge-webhook-key'));
    return $expected && $key !== '' && hash_equals($expected, $key);
}

function lwb_webhook_course_id(array $obj) {
    return intval($obj['course_id'] ?? $obj['course']['id'] ?? 0);
}

function lwb_webhook_email(array $obj) {
    return sanitize_email($obj['user']['email'] ?? $obj['email'] ?? '');
}

function lwb_webhook_date($value) {
    return $value ? date('Y-m-d H:i:s', strtotime($value)) : current_time('mysql');
}

function lwb_handle_webhook(WP_REST_Request $request) {
    global $wpdb;
    if (!lwb_webhook_authorized($request)) {
        return new WP_REST_Response(['error' => 'unauthorized'], 403);
    }

    $params = $request->get_json_params();
    $type   = $params['type'] ?? $params['object_type'] ?? '';
    $obj    = is_array($params['object'] ?? null) ? $params['object'] : [];
    $table  = $wpdb->prefix . 'lwb_enrollments';

    // Whatever this event turns out to be, any cached entitlement for the
    // learner it names is now suspect. Cleared at the end, after the write.
    $touched = lwb_webhook_email($obj) ?: sanitize_email($obj['email'] ?? '');

    switch ($type) {
        case 'Enrollment.created': case 'New Enrollment': case 'Enrollment':
            $email     = lwb_webhook_email($obj);
            $course_id = lwb_webhook_course_id($obj);
            if (!$email || !$course_id) break;

            // Selective upsert rather than REPLACE: a re-enrollment must not
            // wipe the sale price or the progress already recorded.
            $fields = [
                'user_name'       => sanitize_text_field($obj['user']['name'] ?? ''),
                'lms_user_id'     => intval($obj['user']['id'] ?? 0),
                'course_name'     => sanitize_text_field($obj['course']['name'] ?? ''),
                'enrollment_date' => lwb_webhook_date($obj['enrolled_at'] ?? null),
                'is_active'       => 1,
                'src'             => sanitize_text_field($obj['user']['src'] ?? ''),
                'affiliate_code'  => sanitize_text_field($obj['user']['signed_up_affiliate_code'] ?? ''),
                'signup_ip'       => sanitize_text_field($obj['user']['last_sign_in_ip'] ?? ''),
            ];
            if (isset($obj['user']['created_at'])) {
                $fields['registration_date'] = lwb_webhook_date($obj['user']['created_at']);
            }
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_email = %s AND course_id = %d", $email, $course_id));
            if ($exists) {
                $wpdb->update($table, $fields, ['id' => $exists]);
            } else {
                $wpdb->insert($table, array_merge($fields, ['user_email' => $email, 'course_id' => $course_id]));
            }
            break;

        case 'Enrollment.disabled': case 'New Unenrollment':
            $wpdb->update($table, ['is_active' => 0], [
                'user_email' => lwb_webhook_email($obj),
                'course_id'  => lwb_webhook_course_id($obj),
            ]);
            break;

        case 'Enrollment.completed': case 'Enrollment Completed':
            $wpdb->update($table, ['percent_complete' => 100, 'completed_date' => current_time('mysql')], [
                'user_email' => lwb_webhook_email($obj),
                'course_id'  => lwb_webhook_course_id($obj),
            ]);
            break;

        case 'LectureProgress.created': case 'Lesson Completed':
            $wpdb->update($table, ['percent_complete' => intval($obj['percent_complete'] ?? 0)], [
                'user_email' => lwb_webhook_email($obj),
                'course_id'  => lwb_webhook_course_id($obj),
            ]);
            break;

        case 'User.created': case 'New User':
            $email = sanitize_email($obj['email'] ?? '');
            if (!$email) break;
            // A user without a course is stored with course_id NULL, so the
            // registration date and acquisition source survive until the first enrollment.
            $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE user_email = %s AND course_id IS NULL LIMIT 1", $email));
            if (!$exists) {
                $wpdb->insert($table, [
                    'user_email'        => $email,
                    'user_name'         => sanitize_text_field($obj['name'] ?? ''),
                    'lms_user_id'       => intval($obj['id'] ?? 0),
                    'registration_date' => lwb_webhook_date($obj['created_at'] ?? null),
                    'src'               => sanitize_text_field($obj['src'] ?? ''),
                    'signup_ip'         => sanitize_text_field($obj['last_sign_in_ip'] ?? ''),
                ]);
            }
            break;

        case 'User.updated': case 'User Profile Updated':
            $email = sanitize_email($obj['email'] ?? '');
            if (!$email) break;
            $wpdb->update($table, ['user_name' => sanitize_text_field($obj['name'] ?? '')], ['user_email' => $email]);
            break;

        case 'Sale.created': case 'New Sale': case 'Transaction.created': case 'New Transaction':
            $email = lwb_webhook_email($obj);
            if (!$email) break;
            $price     = floatval($obj['amount'] ?? $obj['price'] ?? 0);
            $currency  = strtoupper(substr((string) ($obj['currency'] ?? 'EUR'), 0, 10));
            $sale_date = lwb_webhook_date($obj['created_at'] ?? null);
            $course_id = lwb_webhook_course_id($obj);
            if ($course_id) {
                // Scoped to the course: a sale must never be attributed to every enrollment of the learner.
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET sale_price = %f, sale_currency = %s, sale_date = %s WHERE user_email = %s AND course_id = %d",
                    $price, $currency, $sale_date, $email, $course_id
                ));
            } else {
                // Course unknown: fill only rows that have no price yet, never overwrite earlier sales.
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET sale_price = %f, sale_currency = %s, sale_date = %s WHERE user_email = %s AND sale_price IS NULL",
                    $price, $currency, $sale_date, $email
                ));
            }
            break;

        case 'Transaction.refunded': case 'Transaction Refunded':
            $email = lwb_webhook_email($obj);
            if (!$email) break;
            $course_id = lwb_webhook_course_id($obj);
            if ($course_id) {
                $wpdb->update($table, ['is_active' => 0, 'refunded' => 1, 'refund_date' => current_time('mysql')],
                    ['user_email' => $email, 'course_id' => $course_id]);
            } else {
                // Course unknown: refund only the most recent paid purchase. The
                // derived table keeps this portable (MySQL forbids selecting from
                // the table being updated unless it is wrapped).
                $wpdb->query($wpdb->prepare(
                    "UPDATE $table SET is_active = 0, refunded = 1, refund_date = %s
                     WHERE id = (SELECT id FROM (
                         SELECT id FROM $table
                         WHERE user_email = %s AND sale_price IS NOT NULL AND refunded = 0
                         ORDER BY sale_date DESC LIMIT 1) latest)",
                    current_time('mysql'), $email
                ));
            }
            break;

        case 'Subscription.cancelled': case 'Subscription Cancelled':
            $email = lwb_webhook_email($obj);
            if (!$email) break;
            $wpdb->update($table, ['is_active' => 0], ['user_email' => $email, 'course_id' => lwb_webhook_course_id($obj)]);
            break;
    }

    if ($touched) {
        lwb_flush_entitlement($touched);
    }

    /**
     * Fires after an LMS event has been applied to the mirror.
     * Host sites use this to sync a membership plugin or to notify a channel.
     */
    do_action('lwb_webhook_processed', $type, $obj);

    // Unknown event types are acknowledged too. A 4xx tells the LMS the delivery
    // failed, and it will keep retrying an event this site will never understand;
    // the retries then crowd out the deliveries that matter.
    return new WP_REST_Response('OK', 200);
}
