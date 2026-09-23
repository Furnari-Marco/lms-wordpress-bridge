<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Admin: menu, notices and shared styles.
 * Two pages only: Dashboard and Settings.
 */

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/dashboard.php';
require_once __DIR__ . '/settings.php';

add_action('admin_menu', function () {
    add_menu_page('LMS Bridge', 'LMS Bridge', 'manage_options', 'lms-bridge', 'lwb_page_dashboard', 'dashicons-networking', 30);
    add_submenu_page('lms-bridge', 'Dashboard', 'Dashboard', 'manage_options', 'lms-bridge',          'lwb_page_dashboard');
    add_submenu_page('lms-bridge', 'Settings',  'Settings',  'manage_options', 'lms-bridge-settings', 'lwb_page_settings');
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $secret = lwb_opt('lwb_sso_secret');
    if (!$secret) {
        echo '<div class="notice notice-warning"><p><strong>LMS Bridge:</strong> the shared secret is not set. Single sign-on and entitlement checks stay disabled until you configure it in <a href="admin.php?page=lms-bridge-settings">Settings</a>.</p></div>';
    } elseif (lwb_secret_is_compromised($secret)) {
        echo '<div class="notice notice-error"><p><strong>LMS Bridge:</strong> the shared secret in use is registered as <strong>compromised</strong>. Generate a new one and update it in <a href="admin.php?page=lms-bridge-settings">Settings</a>.</p></div>';
    }
});

add_action('admin_head', function () {
    if (!isset($_GET['page']) || strpos($_GET['page'], 'lms-bridge') !== 0) return;
    echo '<style>
    .lwb-wrap { max-width: 1200px; }
    .lwb-stats { display: flex; gap: 16px; flex-wrap: wrap; margin: 20px 0; }
    .lwb-stat { background: #fff; border: 1px solid #ddd; border-top: 4px solid #2271b1; border-radius: 4px; padding: 20px 24px; min-width: 160px; flex: 1; }
    .lwb-stat h3 { margin: 0 0 8px; font-size: 13px; color: #666; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
    .lwb-stat .lwb-num { font-size: 32px; font-weight: 700; color: #2271b1; line-height: 1; }
    .lwb-stat.red { border-top-color: #dc3232; } .lwb-stat.red .lwb-num { color: #dc3232; }
    .lwb-stat.green { border-top-color: #46b450; } .lwb-stat.green .lwb-num { color: #46b450; }
    .lwb-table { background: #fff; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto; }
    .lwb-table table { border-collapse: collapse; width: 100%; font-size: 13px; }
    .lwb-table th { background: #f8f8f8; padding: 10px 12px; text-align: left; border-bottom: 2px solid #ddd; white-space: nowrap; }
    .lwb-table td { padding: 9px 12px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
    .lwb-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; }
    .lwb-badge.active { background: #eaf7ea; color: #46b450; }
    .lwb-badge.inactive { background: #fef2f2; color: #dc3232; }
    .lwb-badge.refunded { background: #fff3cd; color: #856404; }
    .lwb-badge.bot { background: #fef2f2; color: #dc3232; }
    .lwb-section { background: #fff; border: 1px solid #ddd; border-radius: 4px; padding: 16px 20px; margin-bottom: 20px; }
    .lwb-section h2 { margin-top: 0; }
    .lwb-muted { color: #888; }
    </style>';
});

/** "AABBCC,DDEE…" (HEX of packed IPs from GROUP_CONCAT) → list of readable IPs. */
function lwb_hex_ips($csv) {
    $out = [];
    foreach (array_filter(explode(',', (string) $csv)) as $hex) {
        $bin = @hex2bin(trim($hex));
        if ($bin === false) continue;
        $ip = @inet_ntop($bin);
        if ($ip !== false) $out[] = $ip;
    }
    return $out;
}

function lwb_render_ip_list($hex_csv, $max = 12) {
    $ips   = lwb_hex_ips($hex_csv);
    $shown = array_slice($ips, 0, $max);
    $out   = [];
    foreach ($shown as $ip) {
        $out[] = '<a href="' . esc_url('https://ipinfo.io/' . $ip) . '" target="_blank" rel="noopener">' . esc_html($ip) . '</a>';
    }
    $extra = count($ips) - count($shown);
    return implode('<br>', $out) . ($extra > 0 ? '<br><span class="lwb-muted">+' . $extra . ' more</span>' : '');
}

/** User agent → short label ("Chrome · Windows"). Good enough for a table cell, not a parser. */
function lwb_ua_label($ua) {
    $ua = (string) $ua;
    $os = 'unknown OS';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false) $os = 'iPhone';
    elseif (stripos($ua, 'iPad') !== false) $os = 'iPad';
    elseif (stripos($ua, 'Macintosh') !== false || stripos($ua, 'Mac OS') !== false) $os = 'Mac';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    $br = 'unknown browser';
    if (stripos($ua, 'Edg/') !== false || stripos($ua, 'Edge/') !== false) $br = 'Edge';
    elseif (stripos($ua, 'OPR/') !== false || stripos($ua, 'Opera') !== false) $br = 'Opera';
    elseif (stripos($ua, 'Firefox/') !== false) $br = 'Firefox';
    elseif (stripos($ua, 'Chrome/') !== false || stripos($ua, 'CriOS/') !== false) $br = 'Chrome';
    elseif (stripos($ua, 'Safari/') !== false) $br = 'Safari';
    elseif (preg_match('/python|curl|wget|yt-dlp/i', $ua)) $br = 'Script/robot';
    return $br . ' · ' . $os;
}

function lwb_fmt_duration($seconds) {
    $seconds = intval($seconds);
    if ($seconds < 60) return $seconds . 's';
    if ($seconds < 3600) return intdiv($seconds, 60) . ' min';
    return intdiv($seconds, 3600) . ' h ' . intdiv($seconds % 3600, 60) . ' min';
}
