<?php
// Copyright (c) 2026 Marco Furnari. All rights reserved.
// See LICENSE at the repository root.
/**
 * Admin: Settings.
 *
 * Each field carries the consequence of getting it wrong, not a restatement of
 * its label. The person configuring this is usually the site owner, once, under
 * time pressure, and they will not read the documentation.
 */

if (!defined('ABSPATH')) exit;

function lwb_save_settings(array $post) {
    foreach (['lwb_lms_url', 'lwb_sso_secret', 'lwb_sso_endpoint', 'lwb_webhook_key'] as $k) {
        update_option($k, sanitize_text_field(wp_unslash($post[$k] ?? '')), false);
    }
    update_option('lwb_webhook_enforce', isset($post['lwb_webhook_enforce']) ? '1' : '0', false);
    update_option('lwb_retention_years', max(1, intval($post['lwb_retention_years'] ?? 2)), false);
}

function lwb_page_settings() {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['lwb_settings_nonce'])) {
        if (wp_verify_nonce($_POST['lwb_settings_nonce'], 'lwb_save_settings')) {
            lwb_save_settings($_POST);
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }
    }

    $webhook_key = lwb_opt('lwb_webhook_key');
    $rest_base   = home_url('/wp-json/' . LWB_REST_NS);
    ?>
    <div class="wrap lwb-wrap">
        <h1>LMS Bridge: Settings</h1>
        <form method="post">
            <?php wp_nonce_field('lwb_save_settings', 'lwb_settings_nonce'); ?>

            <h2>LMS</h2>
            <table class="form-table">
                <tr><th><label>LMS school URL</label></th>
                    <td><input type="text" name="lwb_lms_url" value="<?php echo esc_attr(lwb_opt('lwb_lms_url')); ?>" class="regular-text" placeholder="https://school.example.com">
                    <p class="description">This one field also decides which origins may call the API and which hosts an SSO link may redirect to. Leaving it empty disables both.</p></td></tr>
            </table>

            <h2>Shared secret and SSO</h2>
            <table class="form-table">
                <tr><th><label>Shared secret</label></th>
                    <td><input type="password" name="lwb_sso_secret" value="<?php echo esc_attr(lwb_opt('lwb_sso_secret')); ?>" class="regular-text" autocomplete="off">
                    <?php if (lwb_secret_is_compromised(lwb_opt('lwb_sso_secret'))): ?>
                        <p class="description" style="color:#dc3232;">This secret is registered as compromised. Generate a new one and update the LMS.</p>
                    <?php endif; ?>
                    <p class="description"><strong>Use it only from the LMS back end.</strong> Anyone holding this secret can mint a sign-in link for any email address, so a front end that calls the API from the browser hands out accounts.</p></td></tr>
                <tr><th><label>SSO endpoint</label></th>
                    <td><input type="text" name="lwb_sso_endpoint" value="<?php echo esc_attr(lwb_opt('lwb_sso_endpoint', $rest_base . '/auth')); ?>" class="regular-text">
                    <p class="description">Change it only if the site sits behind a proxy that rewrites the REST path.</p></td></tr>
            </table>

            <h2>Webhook</h2>
            <table class="form-table">
                <tr><th><label>Webhook key</label></th>
                    <td><input type="text" name="lwb_webhook_key" value="<?php echo esc_attr($webhook_key); ?>" class="regular-text" autocomplete="off"></td></tr>
                <tr><th>URL to configure on the LMS</th>
                    <td><code><?php echo esc_html($rest_base . '/sync?key=' . $webhook_key); ?></code></td></tr>
                <tr><th>Require webhook key</th>
                    <td><label><input type="checkbox" name="lwb_webhook_enforce" <?php checked(lwb_opt('lwb_webhook_enforce'), '1'); ?>> Reject deliveries that do not carry a valid key</label>
                    <p class="description"><strong>Order matters.</strong> Put the URL above into the LMS first, confirm an event arrives, and only then tick this. Ticking it first rejects every delivery until the LMS is updated, and the events lost in between are not replayed.</p></td></tr>
            </table>

            <h2>Data retention</h2>
            <table class="form-table">
                <tr><th><label>Keep sign-in records for (years)</label></th>
                    <td><input type="number" name="lwb_retention_years" min="1" max="20" value="<?php echo esc_attr(lwb_opt('lwb_retention_years', '2')); ?>" style="width:80px;">
                    <p class="description">Sign-in records hold an IP address and a browser string, so they are personal data and should not be kept indefinitely. A daily job deletes older ones. Enrollments are never deleted: they are the record of what somebody bought.</p></td></tr>
            </table>

            <h2>Endpoints</h2>
            <table class="form-table">
                <?php foreach ([
                    'auth'              => 'SSO login (the learner’s browser)',
                    'generate-sso-link' => 'Generate SSO link (LMS back end)',
                    'sync'              => 'Webhook (LMS server)',
                    'check-access'      => 'Entitlement check (LMS back end)',
                ] as $path => $label): ?>
                <tr><th><?php echo esc_html($label); ?></th><td><code><?php echo esc_html($rest_base . '/' . $path); ?></code></td></tr>
                <?php endforeach; ?>
            </table>

            <?php submit_button('Save settings'); ?>
        </form>
    </div>
    <?php
}
