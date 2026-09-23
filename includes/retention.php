<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Daily retention job.
 *
 * SSO sessions carry an IP address and a user agent, which makes them personal
 * data with no business reason to exist forever. They are deleted past the
 * configured horizon, in bounded batches: one unbounded DELETE against a table
 * with years of rows is how a cron job takes a site down at three in the
 * morning, and the operator finds out from the customer.
 *
 * Enrollments are never purged here. They are the record of what somebody
 * bought, and deleting them would silently revoke access.
 */

if (!defined('ABSPATH')) exit;

/** Rows per statement, and statements per run. The rest waits for tomorrow. */
const LWB_RETENTION_BATCH   = 20000;
const LWB_RETENTION_BATCHES = 5;

function lwb_daily_maintenance() {
    global $wpdb;

    $years  = max(1, intval(lwb_opt('lwb_retention_years', '2')));
    $cutoff = gmdate('Y-m-d H:i:s', current_time('timestamp') - $years * YEAR_IN_SECONDS);
    $table  = $wpdb->prefix . 'lwb_sessions';

    // "DELETE ... LIMIT" is a MySQL extension. Selecting the ids through a
    // derived table is standard SQL, which keeps the statement valid on the
    // engine the test-suite runs against as well as in production.
    for ($i = 0; $i < LWB_RETENTION_BATCHES; $i++) {
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table WHERE id IN (SELECT id FROM (SELECT id FROM $table WHERE login_date < %s LIMIT %d) old)",
            $cutoff, LWB_RETENTION_BATCH
        ));
        if (!$deleted) break;   // nothing left to remove; stop early
    }
}
