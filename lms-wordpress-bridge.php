<?php
/**
 * Plugin Name: LMS ↔ WordPress Bridge
 * Description: Shares identity and entitlements between a hosted LMS and WordPress: enrollment and sales sync over authenticated webhooks, single sign-on over signed single-use links, and local entitlement checks.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Marco Furnari
 * License: All Rights Reserved
 * Text Domain: lms-wordpress-bridge
 */
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.

if (!defined('ABSPATH')) exit;

define('LWB_VERSION', '1.0.0');
define('LWB_DIR', __DIR__);
define('LWB_REST_NS', 'lms-bridge/v1');

// Each include owns one concern; the bootstrap only wires hooks and routes.
require_once LWB_DIR . '/includes/helpers.php';
require_once LWB_DIR . '/includes/schema.php';
require_once LWB_DIR . '/includes/sso.php';
require_once LWB_DIR . '/includes/webhook.php';
require_once LWB_DIR . '/includes/access.php';
require_once LWB_DIR . '/includes/retention.php';

if (is_admin()) {
    require_once LWB_DIR . '/includes/admin/menu.php';
}

// ------------------------------------------------------------
// Lifecycle
// ------------------------------------------------------------
register_activation_hook(__FILE__, 'lwb_activate');
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('lwb_daily_maintenance');
});

// A plugin updated by uploading a new zip never runs the activation hook,
// so schema changes are applied on first load by comparing the stored version.
add_action('plugins_loaded', 'lwb_maybe_upgrade');

// ------------------------------------------------------------
// REST routes
//
// Every endpoint authorizes inside its own callback rather than through
// `permission_callback`, because the callers are third-party servers and
// browsers following a signed link, never logged-in WordPress users. A
// `permission_callback` that returned false would produce a 401 challenge
// where these clients expect a 403 or a redirect.
// ------------------------------------------------------------
add_action('rest_api_init', function () {
    $routes = [
        ['/auth',              'GET',  'lwb_handle_sso_login'],
        ['/generate-sso-link', 'GET',  'lwb_generate_sso_link'],
        ['/sync',              'POST', 'lwb_handle_webhook'],
        ['/check-access',      'GET',  'lwb_check_course_access'],
    ];
    foreach ($routes as [$path, $method, $callback]) {
        register_rest_route(LWB_REST_NS, $path, [
            'methods'             => $method,
            'callback'            => $callback,
            'permission_callback' => '__return_true',
        ]);
    }
});

// CORS: WordPress' default CORS filter is replaced with one that only answers
// to the configured LMS origin, and preflight requests are short-circuited
// before WordPress starts routing.
add_action('rest_api_init', function () {
    remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
    add_filter('rest_pre_serve_request', function ($value) {
        lwb_send_cors_headers();
        return $value;
    });
}, 15);

add_action('init', function () {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS' && lwb_send_cors_headers()) {
        header('Access-Control-Max-Age: 86400');
        status_header(200);
        exit;
    }
});

// SSO redirects may only land on WordPress itself or on the LMS.
add_filter('allowed_redirect_hosts', function ($hosts) {
    return array_unique(array_merge($hosts, lwb_lms_hosts()));
});

add_action('lwb_daily_maintenance', 'lwb_daily_maintenance');
