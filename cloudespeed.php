<?php
/**
 * Plugin Name: Cloud E Speed — Intelligent FastCGI & Nginx Cache Accelerator
 * Plugin URI: https://github.com/devceerdhg/cloudespeed-wp-plugin
 * Description: Real-time automated cache invalidation engine for Cloud E Panel. Purges Nginx FastCGI microcache on post, product, inventory, and Elementor updates with zero latency.
 * Version: 1.1.0
 * Author: Cloud E Tech
 * Author URI: https://cloudetech.org/
 * License: GPLv2 or later
 * Text Domain: cloudespeed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLOUDESPEED_VERSION', '1.1.0');
define('CLOUDESPEED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLOUDESPEED_PLUGIN_URL', plugin_dir_url(__FILE__));

class CloudESpeedPlugin {
    private static $instance = null;
    private static $purged_this_request = false;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_bar_menu', [$this, 'register_admin_bar_menu'], 100);
        add_action('admin_post_cloudespeed_purge_all', [$this, 'handle_manual_purge_all']);
        add_action('admin_post_cloudespeed_purge_current', [$this, 'handle_manual_purge_current']);
        add_action('wp_ajax_cloudespeed_test_connection', [$this, 'ajax_test_connection']);

        // Register automatic invalidation hooks if configured
        if ($this->is_configured()) {
            $this->register_invalidation_hooks();
        }
    }

    public function is_configured() {
        $api_key = get_option('cloudespeed_api_key', '');
        $api_url = get_option('cloudespeed_api_url', '');
        return !empty($api_key) && !empty($api_url);
    }

    private function register_invalidation_hooks() {
        $opt_posts = get_option('cloudespeed_purge_on_post', '1');
        $opt_woo   = get_option('cloudespeed_purge_on_woo', '1');
        $opt_menus = get_option('cloudespeed_purge_on_menu', '1');

        if ($opt_posts === '1') {
            add_action('save_post', [$this, 'on_post_save'], 10, 2);
            add_action('deleted_post', [$this, 'on_post_delete'], 10, 2);
            add_action('trashed_post', [$this, 'on_post_delete'], 10, 2);
            add_action('comment_post', [$this, 'on_comment_change']);
            add_action('edit_comment', [$this, 'on_comment_change']);
            add_action('delete_comment', [$this, 'on_comment_change']);
            // Elementor editor save
            add_action('elementor/editor/after_save', [$this, 'on_elementor_save'], 10, 2);
        }

        if ($opt_woo === '1') {
            add_action('woocommerce_update_product', [$this, 'on_woo_product_update']);
            add_action('woocommerce_delete_product', [$this, 'on_woo_product_update']);
            add_action('woocommerce_reduce_order_stock', [$this, 'on_woo_stock_change']);
            add_action('woocommerce_restore_order_stock', [$this, 'on_woo_stock_change']);
            add_action('woocommerce_product_set_stock_status', [$this, 'on_woo_stock_change']);
        }

        if ($opt_menus === '1') {
            add_action('wp_update_nav_menu', [$this, 'on_menu_update']);
            add_action('customize_save_after', [$this, 'on_customizer_save']);
            add_action('switch_theme', [$this, 'on_theme_switch']);
        }
    }

    public function register_admin_menu() {
        add_options_page(
            'Cloud E Speed Settings',
            '⚡ Cloud E Speed',
            'manage_options',
            'cloudespeed-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('cloudespeed_settings', 'cloudespeed_api_url', 'esc_url_raw');
        register_setting('cloudespeed_settings', 'cloudespeed_api_key', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_purge_on_post', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_purge_on_woo', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_purge_on_menu', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_flush_object_cache', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_ssl_verify', 'sanitize_text_field');
    }

    public function register_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $purge_all_url = wp_nonce_url(admin_url('admin-post.php?action=cloudespeed_purge_all'), 'cloudespeed_purge_all');

        $wp_admin_bar->add_node([
            'id'    => 'cloudespeed-root',
            'title' => '<span style="color:#06B6D4;font-weight:bold;">⚡ Cloud E Speed</span>',
            'href'  => admin_url('options-general.php?page=cloudespeed-settings'),
        ]);

        $wp_admin_bar->add_node([
            'id'     => 'cloudespeed-purge-all',
            'parent' => 'cloudespeed-root',
            'title'  => '🚀 Purge Entire FastCGI Cache',
            'href'   => $purge_all_url,
        ]);

        if (!is_admin()) {
            global $wp;
            $current_url = home_url(add_query_arg([], $wp->request));
            $purge_curr_url = wp_nonce_url(admin_url('admin-post.php?action=cloudespeed_purge_current&target=' . urlencode($current_url)), 'cloudespeed_purge_current');

            $wp_admin_bar->add_node([
                'id'     => 'cloudespeed-purge-current',
                'parent' => 'cloudespeed-root',
                'title'  => '🎯 Purge This Page Cache',
                'href'   => $purge_curr_url,
            ]);
        }

        $wp_admin_bar->add_node([
            'id'     => 'cloudespeed-settings-link',
            'parent' => 'cloudespeed-root',
            'title'  => '⚙️ Cache Configuration',
            'href'   => admin_url('options-general.php?page=cloudespeed-settings'),
        ]);
    }

    public function purge_cache($url_path = '') {
        if (self::$purged_this_request) {
            return true;
        }

        $api_key = get_option('cloudespeed_api_key', '');
        $api_url = get_option('cloudespeed_api_url', '');

        if (empty($api_key) || empty($api_url)) {
            return false;
        }

        // Determine target endpoint
        $endpoint = rtrim($api_url, '/');
        $body     = ['timestamp' => time()];

        if (!empty($url_path)) {
            $parsed = parse_url($url_path, PHP_URL_PATH);
            if ($parsed) {
                $body['url'] = $parsed;
            }
        }

        $ssl_verify = get_option('cloudespeed_ssl_verify', '0') === '1';

        $args = [
            'method'    => 'POST',
            'timeout'   => 5,
            'blocking'  => false,
            'sslverify' => $ssl_verify,
            'headers'   => [
                'X-CloudESpeed-Key' => $api_key,
                'Content-Type'      => 'application/json',
                'User-Agent'        => 'CloudESpeed-WordPress/' . CLOUDESPEED_VERSION,
            ],
            'body'      => json_encode($body),
        ];

        wp_remote_post($endpoint, $args);
        self::$purged_this_request = true;

        // Optionally flush internal WP object cache / transients
        if (get_option('cloudespeed_flush_object_cache', '1') === '1') {
            wp_cache_flush();
        }

        // Log recent event
        $this->log_purge_event(empty($url_path) ? 'Full Cache Purge' : 'URL: ' . $url_path);

        return true;
    }

    private function log_purge_event($description) {
        $logs = get_transient('cloudespeed_purge_logs');
        if (!is_array($logs)) {
            $logs = [];
        }
        array_unshift($logs, [
            'time' => current_time('mysql'),
            'type' => $description,
        ]);
        $logs = array_slice($logs, 0, 10);
        set_transient('cloudespeed_purge_logs', $logs, DAY_IN_SECONDS * 7);
    }

    // Invalidation Handlers
    public function on_post_save($post_id, $post) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if ($post && in_array($post->post_status, ['publish', 'trash', 'future'], true)) {
            $permalink = get_permalink($post_id);
            $this->purge_cache($permalink ? $permalink : '');
        }
    }

    public function on_post_delete($post_id) {
        $this->purge_cache();
    }

    public function on_comment_change() {
        $this->purge_cache();
    }

    public function on_elementor_save($post_id, $editor_data) {
        $this->purge_cache(get_permalink($post_id));
    }

    public function on_woo_product_update($product_id) {
        $permalink = get_permalink($product_id);
        $this->purge_cache($permalink ? $permalink : '');
    }

    public function on_woo_stock_change() {
        $this->purge_cache();
    }

    public function on_menu_update() {
        $this->purge_cache();
    }

    public function on_customizer_save() {
        $this->purge_cache();
    }

    public function on_theme_switch() {
        $this->purge_cache();
    }

    public function handle_manual_purge_all() {
        if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_purge_all')) {
            wp_die('Unauthorized action.');
        }
        $this->purge_cache();
        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('options-general.php?page=cloudespeed-settings');
        }
        wp_redirect(add_query_arg('cloudespeed_notice', 'purged_all', $redirect));
        exit;
    }

    public function handle_manual_purge_current() {
        if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_purge_current')) {
            wp_die('Unauthorized action.');
        }
        $target = isset($_GET['target']) ? esc_url_raw(wp_unslash($_GET['target'])) : '';
        $this->purge_cache($target);
        $redirect = $target ? $target : home_url('/');
        wp_redirect(add_query_arg('cloudespeed_notice', 'purged_url', $redirect));
        exit;
    }

    public function ajax_test_connection() {
        check_ajax_referer('cloudespeed_test_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $api_url = isset($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error(['message' => 'Please enter both the API Endpoint URL and X-CloudESpeed-Key.']);
        }

        // Call status endpoint
        $status_url = preg_replace('#/api/ext/v1/cache/.*$#', '/api/ext/v1/cache/status', $api_url);
        if ($status_url === $api_url) {
            $status_url = rtrim($api_url, '/') . '/status';
        }

        $ssl_verify = get_option('cloudespeed_ssl_verify', '0') === '1';

        $res = wp_remote_get($status_url, [
            'timeout'   => 8,
            'sslverify' => $ssl_verify,
            'headers'   => [
                'X-CloudESpeed-Key' => $api_key,
                'User-Agent'        => 'CloudESpeed-WordPress/' . CLOUDESPEED_VERSION,
            ],
        ]);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => 'Connection failed: ' . $res->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);

        if ($code === 200) {
            $status_txt = 'Connected successfully to Cloud E Panel!';
            if (isset($data['cache_enabled'])) {
                $status_txt .= ' (FastCGI Cache: ' . ($data['cache_enabled'] ? 'Active' : 'Disabled') . ')';
            }
            wp_send_json_success(['message' => $status_txt, 'data' => $data]);
        } elseif ($code === 401) {
            wp_send_json_error(['message' => 'Invalid API Key (Unauthorized: 401). Please verify your X-CloudESpeed-Key.']);
        } else {
            wp_send_json_error(['message' => "Server responded with HTTP {$code}: " . ($data['message'] ?? $body)]);
        }
    }

    public function render_settings_page() {
        $notice = isset($_GET['cloudespeed_notice']) ? sanitize_text_field($_GET['cloudespeed_notice']) : '';
        $logs   = get_transient('cloudespeed_purge_logs') ?: [];
        ?>
        <div class="wrap" style="max-width: 900px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, sans-serif;">
            
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; margin-bottom: 20px;">
                <div>
                    <h1 style="display: flex; align-items: center; gap: 10px; font-size: 26px; font-weight: 800; color: #0F172A; margin: 0;">
                        <span style="color: #06B6D4;">⚡</span> Cloud E Speed Acceleration
                    </h1>
                    <p style="color: #64748B; margin: 5px 0 0 0; font-size: 14px;">Real-time Nginx FastCGI microcache automation & invalidation engine.</p>
                </div>
                <div>
                    <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=cloudespeed_purge_all'), 'cloudespeed_purge_all'); ?>" class="button button-primary" style="background: linear-gradient(135deg, #06B6D4, #3B82F6); border: none; font-weight: 700; height: 38px; line-height: 36px; padding: 0 18px; border-radius: 8px; box-shadow: 0 4px 14px rgba(6,182,212,0.3);">
                        🚀 Purge All FastCGI Cache
                    </a>
                </div>
            </div>

            <?php if ($notice === 'purged_all'): ?>
                <div class="notice notice-success is-dismissible" style="border-left-color: #06B6D4;">
                    <p><strong>Success!</strong> Sent full cache invalidation request to Cloud E Panel.</p>
                </div>
            <?php elseif ($notice === 'purged_url'): ?>
                <div class="notice notice-success is-dismissible" style="border-left-color: #10B981;">
                    <p><strong>Success!</strong> Purged targeted URL cache successfully.</p>
                </div>
            <?php endif; ?>

            <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 24px; margin-bottom: 24px;">
                <h2 style="font-size: 16px; font-weight: 700; color: #1E293B; margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #F1F5F9;">
                    🔌 Cloud E Panel Connection
                </h2>
                
                <form method="post" action="options.php">
                    <?php settings_fields('cloudespeed_settings'); ?>
                    
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">Webhook Purge URL</th>
                            <td>
                                <input type="url" name="cloudespeed_api_url" id="cloudespeed_api_url" value="<?php echo esc_attr(get_option('cloudespeed_api_url')); ?>" class="regular-text" style="width: 100%; max-width: 550px; font-family: monospace;" placeholder="https://server.yourdomain.com:8443/api/ext/v1/cache/purge" required />
                                <p class="description" style="color: #64748B;">Found in Cloud E Panel &gt; <strong>Cloud E Speed (⚡)</strong> &gt; <em>API &amp; Plugin Guide</em>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">X-CloudESpeed-Key</th>
                            <td>
                                <input type="password" name="cloudespeed_api_key" id="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" class="regular-text" style="width: 100%; max-width: 550px; font-family: monospace;" placeholder="ces_live_..." required />
                                <p class="description" style="color: #64748B;">The secret domain API key assigned to this virtual host in Cloud E Panel.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">SSL Certificate Verification</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cloudespeed_ssl_verify" value="1" <?php checked(get_option('cloudespeed_ssl_verify', '0'), '1'); ?> />
                                    Verify SSL certificate (Leave unchecked if your panel uses a self-signed cert on port 8443)
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 15px; display: flex; align-items: center; gap: 12px;">
                        <?php submit_button('Save Settings', 'primary', 'submit', false, ['style' => 'background:#0F172A; border-color:#0F172A; font-weight:600; border-radius:6px;']); ?>
                        <button type="button" id="btn-test-connection" class="button button-secondary" style="font-weight: 600; border-radius: 6px;">
                            🔍 Test API Connection
                        </button>
                        <span id="test-connection-status" style="font-size: 13px; font-weight: 600;"></span>
                    </div>
                </form>
            </div>

            <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 24px; margin-bottom: 24px;">
                <h2 style="font-size: 16px; font-weight: 700; color: #1E293B; margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #F1F5F9;">
                    ⚡ Automatic Invalidation Triggers
                </h2>

                <form method="post" action="options.php">
                    <?php settings_fields('cloudespeed_settings'); ?>
                    <input type="hidden" name="cloudespeed_api_url" value="<?php echo esc_attr(get_option('cloudespeed_api_url')); ?>" />
                    <input type="hidden" name="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" />
                    <input type="hidden" name="cloudespeed_ssl_verify" value="<?php echo esc_attr(get_option('cloudespeed_ssl_verify', '0')); ?>" />

                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">Posts &amp; Pages</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cloudespeed_purge_on_post" value="1" <?php checked(get_option('cloudespeed_purge_on_post', '1'), '1'); ?> />
                                    Automatically purge cache on post, page, custom post type, or Elementor edit
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">WooCommerce Store</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cloudespeed_purge_on_woo" value="1" <?php checked(get_option('cloudespeed_purge_on_woo', '1'), '1'); ?> />
                                    Automatically purge cache on product creation, updates, and order inventory/stock changes
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">Menus &amp; Theme</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cloudespeed_purge_on_menu" value="1" <?php checked(get_option('cloudespeed_purge_on_menu', '1'), '1'); ?> />
                                    Automatically purge cache when updating navigation menus, widgets, or Customizer settings
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: #334155;">WP Object Cache</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="cloudespeed_flush_object_cache" value="1" <?php checked(get_option('cloudespeed_flush_object_cache', '1'), '1'); ?> />
                                    Flush internal WordPress transients / object cache simultaneously during purges
                                </label>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button('Update Triggers', 'secondary', 'submit_triggers', false, ['style' => 'border-radius:6px; font-weight:600;']); ?>
                </form>
            </div>

            <div style="background: #FFFFFF; border: 1px solid #E2E8F0; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); padding: 24px;">
                <h2 style="font-size: 16px; font-weight: 700; color: #1E293B; margin-top: 0; padding-bottom: 12px; border-bottom: 1px solid #F1F5F9;">
                    🕒 Recent Invalidation Log
                </h2>
                <?php if (empty($logs)): ?>
                    <p style="color: #94A3B8; font-size: 13px; margin: 10px 0 0 0;">No purge events recorded yet. Invalidation events will appear here.</p>
                <?php else: ?>
                    <table class="widefat striped" style="margin-top: 10px; border-radius: 6px; overflow: hidden; border: 1px solid #F1F5F9;">
                        <thead>
                            <tr>
                                <th style="font-weight: 700; width: 180px;">Timestamp</th>
                                <th style="font-weight: 700;">Action / Target</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="font-family: monospace; font-size: 12px; color: #64748B;"><?php echo esc_html($log['time']); ?></td>
                                    <td style="font-weight: 600; color: #1E293B;"><?php echo esc_html($log['type']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#btn-test-connection').on('click', function() {
                var $btn = $(this);
                var $status = $('#test-connection-status');
                var apiUrl = $('#cloudespeed_api_url').val();
                var apiKey = $('#cloudespeed_api_key').val();

                $btn.prop('disabled', true).text('Testing...');
                $status.html('<span style="color:#3B82F6;">Connecting to Cloud E Panel...</span>');

                $.post(ajaxurl, {
                    action: 'cloudespeed_test_connection',
                    nonce: '<?php echo wp_create_nonce('cloudespeed_test_nonce'); ?>',
                    api_url: apiUrl,
                    api_key: apiKey
                }, function(res) {
                    $btn.prop('disabled', false).text('🔍 Test API Connection');
                    if (res.success) {
                        $status.html('<span style="color:#10B981;">✓ ' + res.data.message + '</span>');
                    } else {
                        $status.html('<span style="color:#EF4444;">✕ ' + (res.data ? res.data.message : 'Error') + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('🔍 Test API Connection');
                    $status.html('<span style="color:#EF4444;">✕ Server request timed out or failed.</span>');
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize singleton
CloudESpeedPlugin::get_instance();
