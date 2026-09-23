<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Admin: Dashboard.
 *
 * The numbers an operator checks when they suspect the sync has stopped:
 * how much is mirrored, and when the last event actually arrived. A webhook
 * that silently stops is the failure mode of this kind of integration, and it
 * is invisible until somebody cannot open a course they paid for.
 */

if (!defined('ABSPATH')) exit;

function lwb_dashboard_stats() {
    global $wpdb;
    $t_e = $wpdb->prefix . 'lwb_enrollments';
    $t_s = $wpdb->prefix . 'lwb_sessions';
    $since_30d = gmdate('Y-m-d H:i:s', current_time('timestamp') - 30 * DAY_IN_SECONDS);

    return [
        'learners'     => (int) $wpdb->get_var("SELECT COUNT(DISTINCT user_email) FROM $t_e"),
        'active'       => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_e WHERE is_active = 1 AND course_id IS NOT NULL"),
        'enrollments'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_e WHERE course_id IS NOT NULL"),
        'refunded'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM $t_e WHERE refunded = 1"),
        'revenue'      => (float) $wpdb->get_var("SELECT SUM(sale_price) FROM $t_e WHERE sale_price IS NOT NULL AND refunded = 0"),
        'logins_30d'   => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t_s WHERE login_date >= %s", $since_30d)),
        'last_event'   => $wpdb->get_var("SELECT MAX(enrollment_date) FROM $t_e"),
        'last_login'   => $wpdb->get_var("SELECT MAX(login_date) FROM $t_s"),
    ];
}

/**
 * Hours since the last webhook wrote anything, or null when nothing ever has.
 * Surfaced as a warning because "the integration is quietly dead" has no other
 * symptom until a customer complains.
 */
function lwb_hours_since_last_event($stats) {
    if (empty($stats['last_event'])) return null;
    return (current_time('timestamp') - strtotime($stats['last_event'])) / HOUR_IN_SECONDS;
}

function lwb_page_dashboard() {
    global $wpdb;
    $s      = lwb_dashboard_stats();
    $recent = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}lwb_enrollments WHERE course_id IS NOT NULL ORDER BY enrollment_date DESC LIMIT 10");
    ?>
    <div class="wrap lwb-wrap">
        <h1>LMS Bridge</h1>

        <?php $stale = lwb_hours_since_last_event($s); ?>
        <?php if ($stale === null): ?>
            <div class="notice notice-warning"><p>No enrollment has ever been received. Check that the webhook URL in <a href="admin.php?page=lms-bridge-settings">Settings</a> is configured on the LMS.</p></div>
        <?php elseif ($stale > 48): ?>
            <div class="notice notice-warning"><p>The last event arrived <strong><?php echo intval($stale / 24); ?> days ago</strong>. If the school is still selling, the webhook has probably stopped being delivered.</p></div>
        <?php endif; ?>

        <div class="lwb-stats">
            <div class="lwb-stat"><h3>Learners</h3><div class="lwb-num"><?php echo number_format($s['learners']); ?></div></div>
            <div class="lwb-stat green"><h3>Active enrollments</h3><div class="lwb-num"><?php echo number_format($s['active']); ?></div></div>
            <div class="lwb-stat"><h3>Total enrollments</h3><div class="lwb-num"><?php echo number_format($s['enrollments']); ?></div></div>
            <div class="lwb-stat red"><h3>Refunds</h3><div class="lwb-num"><?php echo number_format($s['refunded']); ?></div></div>
            <div class="lwb-stat green"><h3>Revenue</h3><div class="lwb-num"><?php echo number_format($s['revenue'], 2); ?></div></div>
            <div class="lwb-stat"><h3>SSO logins (30 days)</h3><div class="lwb-num"><?php echo number_format($s['logins_30d']); ?></div></div>
        </div>

        <p class="lwb-muted">
            Last event received: <strong><?php echo $s['last_event'] ? esc_html($s['last_event']) : 'never'; ?></strong>.
            Last SSO login: <strong><?php echo $s['last_login'] ? esc_html($s['last_login']) : 'never'; ?></strong>.
        </p>

        <h2>Latest enrollments</h2>
        <div class="lwb-table">
            <table>
                <thead><tr><th>Email</th><th>Name</th><th>Course</th><th>Enrolled</th><th>Status</th><th>%</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $r): ?>
                    <tr>
                        <td><?php echo esc_html($r->user_email); ?></td>
                        <td><?php echo esc_html($r->user_name); ?></td>
                        <td><?php echo esc_html($r->course_name); ?></td>
                        <td><?php echo esc_html($r->enrollment_date); ?></td>
                        <td><?php if ($r->refunded): ?><span class="lwb-badge refunded">Refunded</span>
                            <?php elseif ($r->is_active): ?><span class="lwb-badge active">Active</span>
                            <?php else: ?><span class="lwb-badge inactive">Inactive</span><?php endif; ?></td>
                        <td><?php echo intval($r->percent_complete); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
