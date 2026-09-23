<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Database schema, activation and versioned upgrades.
 *
 * Two tables:
 *   enrollments  one row per (email, course). The LMS owns the truth; this is a mirror.
 *   sessions     SSO logins, with the marketing attribution that came with them.
 *
 * Custom tables rather than posts and post meta: the mirror is read by
 * (email, course) on every entitlement check and written by webhooks that
 * arrive in bursts. Modelling it as a custom post type would turn one indexed
 * lookup into a meta join, and a full re-sync into thousands of post writes
 * with all the hooks that implies.
 */

if (!defined('ABSPATH')) exit;

function lwb_activate() {
    lwb_install_tables();

    if (!lwb_opt('lwb_webhook_key')) {
        update_option('lwb_webhook_key', bin2hex(random_bytes(24)), false);
    }
    if (!wp_next_scheduled('lwb_daily_maintenance')) {
        wp_schedule_event(time() + 3600, 'daily', 'lwb_daily_maintenance');
    }
    update_option('lwb_db_version', LWB_VERSION, true);
}

/**
 * Apply pending schema changes.
 *
 * Updating a plugin by uploading a zip never fires the activation hook, so the
 * version is compared on load instead. The option is autoloaded, which makes
 * the check on every request an array lookup rather than a query; `dbDelta` is
 * only reached when the versions actually differ.
 */
function lwb_maybe_upgrade() {
    if (get_option('lwb_db_version') !== LWB_VERSION) {
        lwb_install_tables();
        update_option('lwb_db_version', LWB_VERSION, true);
    }
}

function lwb_install_tables() {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $cc = $wpdb->get_charset_collate();

    /*
     * Indexes follow the read paths rather than the writes:
     *   user_course  the entitlement check, and the key the webhook upserts on
     *   user_email   the customer view in the admin, and a refund with no course id
     *   course_id    per-course reporting
     */
    dbDelta("CREATE TABLE {$wpdb->prefix}lwb_enrollments (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_email varchar(100) NOT NULL,
        user_name varchar(255),
        lms_user_id bigint(20),
        course_id bigint(20),
        course_name varchar(255),
        enrollment_date datetime DEFAULT NULL,
        completed_date datetime DEFAULT NULL,
        registration_date datetime DEFAULT NULL,
        is_active tinyint(1) DEFAULT 1,
        percent_complete int(3) DEFAULT 0,
        src varchar(255),
        affiliate_code varchar(100),
        signup_ip varchar(45),
        sale_price decimal(10,2) DEFAULT NULL,
        sale_currency varchar(10),
        sale_date datetime DEFAULT NULL,
        refunded tinyint(1) DEFAULT 0,
        refund_date datetime DEFAULT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_course (user_email,course_id),
        KEY user_email (user_email),
        KEY course_id (course_id)
    ) $cc;");

    dbDelta("CREATE TABLE {$wpdb->prefix}lwb_sessions (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_email varchar(100) NOT NULL,
        login_date datetime DEFAULT NULL,
        ip_address varchar(45),
        utm_source varchar(100),
        utm_medium varchar(100),
        utm_campaign varchar(100),
        utm_content varchar(100),
        referrer varchar(500),
        user_agent varchar(500),
        PRIMARY KEY  (id),
        KEY user_email (user_email),
        KEY login_date (login_date)
    ) $cc;");
}
