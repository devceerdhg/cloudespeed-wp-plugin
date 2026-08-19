<?php
/**
 * Plugin Name: Cloud E Speed — Intelligent FastCGI & Nginx Cache Accelerator
 * Plugin URI: https://github.com/devceerdhg/cloudespeed-wp-plugin
 * Description: Real-time automated cache invalidation engine for Cloud E Panel. Includes a full interactive WordPress Dashboard, Nginx FastCGI microcache controller, URL-targeted purging, Development Mode switcher, and WooCommerce turbo sync.
 * Version: 2.0.0
 * Author: Cloud E Tech
 * Author URI: https://cloudetech.org/
 * License: GPLv2 or later
 * Text Domain: cloudespeed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLOUDESPEED_VERSION', '2.0.0');
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

        // AJAX handlers for Dashboard actions
        add_action('wp_ajax_cloudespeed_ajax_purge_all', [$this, 'ajax_purge_all']);
        add_action('wp_ajax_cloudespeed_ajax_purge_url', [$this, 'ajax_purge_url']);
        add_action('wp_ajax_cloudespeed_ajax_toggle_devmode', [$this, 'ajax_toggle_devmode']);
        add_action('wp_ajax_cloudespeed_ajax_flush_object_cache', [$this, 'ajax_flush_object_cache']);
        add_action('wp_ajax_cloudespeed_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_cloudespeed_get_status', [$this, 'ajax_get_status']);

        // Legacy action handlers for nonces
        add_action('admin_post_cloudespeed_purge_all', [$this, 'handle_manual_purge_all']);
        add_action('admin_post_cloudespeed_purge_current', [$this, 'handle_manual_purge_current']);

        // Automated invalidation hooks
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
        // Top-Level Cloud E Speed Dashboard Page
        add_menu_page(
            'Cloud E Speed Dashboard',
            'Cloud E Speed ⚡',
            'manage_options',
            'cloudespeed-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-performance',
            3
        );

        add_submenu_page(
            'cloudespeed-dashboard',
            'Cloud E Speed — Overview Dashboard',
            '⚡ Dashboard',
            'manage_options',
            'cloudespeed-dashboard',
            [$this, 'render_dashboard_page']
        );

        add_submenu_page(
            'cloudespeed-dashboard',
            'Cloud E Speed — Invalidation Triggers',
            '⚙️ Invalidation Rules',
            'manage_options',
            'cloudespeed-triggers',
            [$this, 'render_triggers_page']
        );

        add_submenu_page(
            'cloudespeed-dashboard',
            'Cloud E Speed — API & Server Settings',
            '🔌 API Configuration',
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
            'href'  => admin_url('admin.php?page=cloudespeed-dashboard'),
        ]);

        $wp_admin_bar->add_node([
            'id'     => 'cloudespeed-purge-all',
            'parent' => 'cloudespeed-root',
            'title'  => '🚀 Purge All FastCGI Cache',
            'href'   => $purge_all_url,
        ]);

        if (!is_admin()) {
            global $wp;
            $current_url = home_url(add_query_arg([], $wp->request));
            $purge_curr_url = wp_nonce_url(admin_url('admin-post.php?action=cloudespeed_purge_current&target=' . urlencode($current_url)), 'cloudespeed_purge_current');

            $wp_admin_bar->add_node([
                'id'     => 'cloudespeed-purge-current',
                'parent' => 'cloudespeed-root',
                'title'  => '🎯 Purge Current URL Cache',
                'href'   => $purge_curr_url,
            ]);
        }

        $wp_admin_bar->add_node([
            'id'     => 'cloudespeed-dashboard-link',
            'parent' => 'cloudespeed-root',
            'title'  => '📊 Speed Dashboard',
            'href'   => admin_url('admin.php?page=cloudespeed-dashboard'),
        ]);
    }

    public function execute_api_call($endpoint_path, $method = 'POST', $data = []) {
        $api_key = get_option('cloudespeed_api_key', '');
        $api_url = get_option('cloudespeed_api_url', '');

        if (empty($api_key) || empty($api_url)) {
            return new WP_Error('not_configured', 'API Key and URL are not configured.');
        }

        // Construct full URL
        $base_url = preg_replace('#/api/ext/v1/cache.*$#', '', $api_url);
        $full_url = rtrim($base_url, '/') . '/api/ext/v1/cache' . $endpoint_path;

        $ssl_verify = get_option('cloudespeed_ssl_verify', '0') === '1';

        $args = [
            'method'    => $method,
            'timeout'   => 8,
            'sslverify' => $ssl_verify,
            'headers'   => [
                'X-CloudESpeed-Key' => $api_key,
                'Content-Type'      => 'application/json',
                'User-Agent'        => 'CloudESpeed-WordPress/' . CLOUDESPEED_VERSION,
            ],
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        $res = wp_remote_request($full_url, $args);
        if (is_wp_error($res)) {
            return $res;
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            return $json ? $json : ['success' => true, 'raw' => $body];
        }

        $err_msg = isset($json['message']) ? $json['message'] : (isset($json['error']) ? $json['error'] : "HTTP {$code}: {$body}");
        return new WP_Error('api_error', $err_msg, ['status' => $code]);
    }

    public function purge_cache($url_path = '') {
        if (self::$purged_this_request) {
            return true;
        }

        if (!empty($url_path)) {
            $parsed = parse_url($url_path, PHP_URL_PATH);
            $res = $this->execute_api_call('/purge-url', 'POST', ['url' => $parsed ? $parsed : $url_path]);
            $this->log_purge_event('URL: ' . ($parsed ? $parsed : $url_path));
        } else {
            $res = $this->execute_api_call('/purge', 'POST', ['timestamp' => time()]);
            $this->log_purge_event('Full FastCGI Purge');
        }

        self::$purged_this_request = true;

        if (get_option('cloudespeed_flush_object_cache', '1') === '1') {
            wp_cache_flush();
        }

        return !is_wp_error($res);
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
        $logs = array_slice($logs, 0, 15);
        set_transient('cloudespeed_purge_logs', $logs, DAY_IN_SECONDS * 7);
    }

    // Auto Invalidation Event Handlers
    public function on_post_save($post_id, $post) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if ($post && in_array($post->post_status, ['publish', 'trash', 'future'], true)) {
            $this->purge_cache(get_permalink($post_id));
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
        $this->purge_cache(get_permalink($product_id));
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

    // AJAX Handlers
    public function ajax_purge_all() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $res = $this->execute_api_call('/purge', 'POST', ['timestamp' => time()]);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        if (get_option('cloudespeed_flush_object_cache', '1') === '1') {
            wp_cache_flush();
        }
        $this->log_purge_event('Full FastCGI Purge (Dashboard)');
        wp_send_json_success(['message' => 'Nginx FastCGI microcache successfully purged!']);
    }

    public function ajax_purge_url() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
        if (empty($url)) {
            wp_send_json_error(['message' => 'Please provide a valid URL or path (e.g. /shop/).']);
        }
        $res = $this->execute_api_call('/purge-url', 'POST', ['url' => $url]);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        $this->log_purge_event('URL: ' . $url . ' (Dashboard)');
        wp_send_json_success(['message' => "Cache successfully invalidated for: {$url}"]);
    }

    public function ajax_toggle_devmode() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $enable = isset($_POST['enable']) && $_POST['enable'] === 'true';
        $hours  = isset($_POST['hours']) ? intval($_POST['hours']) : 3;

        $res = $this->execute_api_call('/dev-mode', 'POST', [
            'dev_mode'       => $enable,
            'duration_hours' => $hours,
        ]);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        $msg = $enable ? "Development Mode activated (bypassing cache for {$hours} hours)" : "Development Mode disabled (FastCGI caching restored)";
        $this->log_purge_event($msg);
        wp_send_json_success(['message' => $msg, 'dev_mode' => $enable]);
    }

    public function ajax_flush_object_cache() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        wp_cache_flush();
        $this->log_purge_event('WordPress Object Cache Flushed');
        wp_send_json_success(['message' => 'WordPress Object Cache & transients cleared!']);
    }

    public function ajax_get_status() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $res = $this->execute_api_call('/status', 'GET');
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        wp_send_json_success($res);
    }

    public function ajax_test_connection() {
        check_ajax_referer('cloudespeed_test_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $api_url = isset($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error(['message' => 'Please enter both the API Endpoint URL and X-CloudESpeed-Key.']);
        }

        $base_url = preg_replace('#/api/ext/v1/cache.*$#', '', $api_url);
        $status_url = rtrim($base_url, '/') . '/api/ext/v1/cache/status';

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
                $status_txt .= ' (FastCGI: ' . ($data['cache_enabled'] ? 'Active' : 'Disabled') . ')';
            }
            wp_send_json_success(['message' => $status_txt, 'data' => $data]);
        } elseif ($code === 401) {
            wp_send_json_error(['message' => 'Invalid API Key (Unauthorized: 401). Please verify your X-CloudESpeed-Key.']);
        } else {
            wp_send_json_error(['message' => "Server responded with HTTP {$code}: " . ($data['message'] ?? $body)]);
        }
    }

    public function handle_manual_purge_all() {
        if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_purge_all')) {
            wp_die('Unauthorized');
        }
        $this->purge_cache();
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=cloudespeed-dashboard');
        wp_redirect(add_query_arg('cloudespeed_msg', 'purged_all', $redirect));
        exit;
    }

    public function handle_manual_purge_current() {
        if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_purge_current')) {
            wp_die('Unauthorized');
        }
        $target = isset($_GET['target']) ? esc_url_raw(wp_unslash($_GET['target'])) : '';
        $this->purge_cache($target);
        $redirect = $target ? $target : home_url('/');
        wp_redirect(add_query_arg('cloudespeed_msg', 'purged_url', $redirect));
        exit;
    }

    /**
     * RENDER THE COMPREHENSIVE CYBER-LUXURY DASHBOARD PAGE
     */
    public function render_dashboard_page() {
        $is_configured = $this->is_configured();
        $api_url = get_option('cloudespeed_api_url', '');
        $logs = get_transient('cloudespeed_purge_logs') ?: [];
        $status_data = null;
        if ($is_configured) {
            $res = $this->execute_api_call('/status', 'GET');
            if (!is_wp_error($res)) {
                $status_data = $res;
            }
        }
        ?>
        <style>
            :root {
                --ces-bg: #090D16;
                --ces-surface: #0F172A;
                --ces-surface-card: #1E293B;
                --ces-border: rgba(255, 255, 255, 0.08);
                --ces-cyan: #06B6D4;
                --ces-blue: #3B82F6;
                --ces-purple: #8B5CF6;
                --ces-emerald: #10B981;
                --ces-amber: #F59E0B;
                --ces-pink: #EC4899;
                --ces-text: #F8FAFC;
                --ces-text-muted: #94A3B8;
                --ces-radius: 14px;
            }

            .ces-dashboard-wrap {
                max-width: 1280px;
                margin: 20px 20px 40px 0;
                font-family: -apple-system, BlinkMacSystemFont, 'Outfit', 'Segoe UI', Roboto, sans-serif;
                color: var(--ces-text);
            }

            .ces-header {
                background: linear-gradient(135deg, #0F172A 0%, #1E1B4B 100%);
                border: 1px solid var(--ces-border);
                border-radius: var(--ces-radius);
                padding: 28px 32px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
                position: relative;
                overflow: hidden;
                margin-bottom: 24px;
            }

            .ces-header::after {
                content: '';
                position: absolute;
                top: -50px;
                right: -50px;
                width: 200px;
                height: 200px;
                background: radial-gradient(circle, rgba(6, 182, 212, 0.2) 0%, transparent 70%);
                border-radius: 50%;
                pointer-events: none;
            }

            .ces-logo-badge {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: rgba(6, 182, 212, 0.15);
                border: 1px solid rgba(6, 182, 212, 0.3);
                color: var(--ces-cyan);
                padding: 4px 12px;
                border-radius: 50px;
                font-size: 11px;
                font-weight: 800;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                margin-bottom: 8px;
            }

            .ces-title {
                font-size: 26px;
                font-weight: 900;
                color: #FFFFFF;
                margin: 0;
                display: flex;
                align-items: center;
                gap: 10px;
            }

            .ces-desc {
                color: var(--ces-text-muted);
                font-size: 13px;
                margin: 6px 0 0 0;
            }

            .ces-header-actions {
                display: flex;
                align-items: center;
                gap: 12px;
                z-index: 2;
            }

            /* Metrics Grid */
            .ces-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 16px;
                margin-bottom: 24px;
            }

            .ces-stat-card {
                background: var(--ces-surface);
                border: 1px solid var(--ces-border);
                border-radius: var(--ces-radius);
                padding: 20px;
                position: relative;
                transition: transform 0.2s ease, border-color 0.2s ease;
            }

            .ces-stat-card:hover {
                transform: translateY(-2px);
                border-color: rgba(6, 182, 212, 0.4);
            }

            .ces-stat-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
            }

            .ces-stat-label {
                font-size: 12px;
                font-weight: 700;
                color: var(--ces-text-muted);
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .ces-stat-value {
                font-size: 24px;
                font-weight: 900;
                color: #FFFFFF;
            }

            .ces-stat-sub {
                font-size: 11px;
                color: var(--ces-text-muted);
                margin-top: 4px;
            }

            /* Main Layout Grid */
            .ces-main-grid {
                display: grid;
                grid-template-columns: 1fr 340px;
                gap: 24px;
            }

            @media (max-width: 1024px) {
                .ces-main-grid {
                    grid-template-columns: 1fr;
                }
            }

            .ces-panel {
                background: var(--ces-surface);
                border: 1px solid var(--ces-border);
                border-radius: var(--ces-radius);
                padding: 24px;
                margin-bottom: 24px;
            }

            .ces-panel-title {
                font-size: 16px;
                font-weight: 800;
                color: #FFFFFF;
                margin: 0 0 16px 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                border-bottom: 1px solid var(--ces-border);
                padding-bottom: 12px;
            }

            /* Action Buttons */
            .ces-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                padding: 10px 20px;
                border-radius: 10px;
                font-size: 13px;
                font-weight: 700;
                cursor: pointer;
                border: none;
                transition: all 0.2s ease;
                text-decoration: none;
            }

            .ces-btn-primary {
                background: linear-gradient(135deg, var(--ces-cyan), var(--ces-blue));
                color: #FFFFFF !important;
                box-shadow: 0 4px 15px rgba(6, 182, 212, 0.25);
            }

            .ces-btn-primary:hover {
                box-shadow: 0 6px 20px rgba(6, 182, 212, 0.4);
                transform: translateY(-1px);
            }

            .ces-btn-danger {
                background: linear-gradient(135deg, #EF4444, #DC2626);
                color: #FFFFFF !important;
                box-shadow: 0 4px 15px rgba(239, 68, 68, 0.25);
            }

            .ces-btn-amber {
                background: linear-gradient(135deg, var(--ces-amber), #D97706);
                color: #FFFFFF !important;
            }

            .ces-btn-outline {
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid var(--ces-border);
                color: var(--ces-text) !important;
            }

            .ces-btn-outline:hover {
                background: rgba(255, 255, 255, 0.1);
                border-color: rgba(255, 255, 255, 0.2);
            }

            .ces-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none !important;
            }

            /* Interactive Form Inputs */
            .ces-input-group {
                display: flex;
                gap: 10px;
                margin-top: 10px;
            }

            .ces-input {
                flex: 1;
                background: #090D16 !important;
                border: 1px solid var(--ces-border) !important;
                color: #FFFFFF !important;
                padding: 10px 14px !important;
                border-radius: 8px !important;
                font-size: 13px !important;
                font-family: monospace !important;
            }

            .ces-input:focus {
                border-color: var(--ces-cyan) !important;
                box-shadow: 0 0 0 1px var(--ces-cyan) !important;
            }

            /* Activity Log Table */
            .ces-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 12px;
            }

            .ces-table th {
                text-align: left;
                padding: 10px 12px;
                color: var(--ces-text-muted);
                font-weight: 700;
                border-bottom: 1px solid var(--ces-border);
                background: rgba(255, 255, 255, 0.02);
            }

            .ces-table td {
                padding: 10px 12px;
                border-bottom: 1px solid rgba(255, 255, 255, 0.04);
                color: var(--ces-text);
            }

            .ces-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 700;
            }

            .ces-badge-success { background: rgba(16, 185, 129, 0.15); color: #10B981; border: 1px solid rgba(16, 185, 129, 0.3); }
            .ces-badge-warning { background: rgba(245, 158, 11, 0.15); color: #F59E0B; border: 1px solid rgba(245, 158, 11, 0.3); }
            .ces-badge-danger  { background: rgba(239, 68, 68, 0.15); color: #EF4444; border: 1px solid rgba(239, 68, 68, 0.3); }
            .ces-badge-cyan    { background: rgba(6, 182, 212, 0.15); color: #06B6D4; border: 1px solid rgba(6, 182, 212, 0.3); }

            /* Toast */
            #ces-toast {
                display: none;
                position: fixed;
                bottom: 24px;
                right: 24px;
                background: var(--ces-surface);
                border: 1px solid var(--ces-cyan);
                color: #FFFFFF;
                padding: 14px 22px;
                border-radius: 12px;
                box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
                font-size: 13px;
                font-weight: 600;
                z-index: 999999;
                align-items: center;
                gap: 10px;
            }
        </style>

        <div class="ces-dashboard-wrap">
            
            <!-- Hero Header -->
            <div class="ces-header">
                <div>
                    <div class="ces-logo-badge">⚡ Cloud E Panel Official Cache Engine</div>
                    <h1 class="ces-title">Cloud E Speed Accelerator</h1>
                    <p class="ces-desc">Intelligent Nginx FastCGI microcaching, real-time invalidation, and development mode control.</p>
                </div>
                <div class="ces-header-actions">
                    <button type="button" class="ces-btn ces-btn-primary" id="btn-purge-all-top">
                        🚀 Purge All Cache
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=cloudespeed-settings'); ?>" class="ces-btn ces-btn-outline">
                        ⚙️ API Settings
                    </a>
                </div>
            </div>

            <!-- Notice Banner if not configured -->
            <?php if (!$is_configured): ?>
                <div style="background: rgba(245, 158, 11, 0.1); border: 1px solid rgba(245, 158, 11, 0.3); padding: 18px 24px; border-radius: var(--ces-radius); margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between;">
                    <div>
                        <strong style="color: #F59E0B; font-size: 15px;">⚠️ Cloud E Panel Connection Needed</strong>
                        <p style="color: var(--ces-text-muted); margin: 4px 0 0 0; font-size: 13px;">Please enter your Webhook Purge URL and X-CloudESpeed-Key in settings to activate automated acceleration.</p>
                    </div>
                    <a href="<?php echo admin_url('admin.php?page=cloudespeed-settings'); ?>" class="ces-btn ces-btn-amber">
                        Configure Now →
                    </a>
                </div>
            <?php endif; ?>

            <!-- Metrics Cards Grid -->
            <div class="ces-stats-grid">
                
                <div class="ces-stat-card">
                    <div class="ces-stat-header">
                        <span class="ces-stat-label">FastCGI Engine</span>
                        <span class="ces-badge <?php echo ($status_data && ($status_data['cache_enabled'] ?? true)) ? 'ces-badge-success' : 'ces-badge-warning'; ?>">
                            <?php echo ($status_data && ($status_data['cache_enabled'] ?? true)) ? 'ACTIVE' : 'STANDBY'; ?>
                        </span>
                    </div>
                    <div class="ces-stat-value" style="color: #10B981;">Nginx Microcache</div>
                    <div class="ces-stat-sub">Zero PHP-FPM overhead on cached hits</div>
                </div>

                <div class="ces-stat-card">
                    <div class="ces-stat-header">
                        <span class="ces-stat-label">Cache Profile</span>
                        <span class="ces-badge ces-badge-cyan">PRO</span>
                    </div>
                    <div class="ces-stat-value" style="color: #06B6D4;">
                        <?php echo esc_html(strtoupper($status_data['cache_profile'] ?? 'WordPress')); ?>
                    </div>
                    <div class="ces-stat-sub">TTL: <?php echo esc_html(($status_data['cache_ttl'] ?? 3600) / 60); ?> Minutes Default</div>
                </div>

                <div class="ces-stat-card">
                    <div class="ces-stat-header">
                        <span class="ces-stat-label">Development Mode</span>
                        <span class="ces-badge <?php echo (!empty($status_data['dev_mode'])) ? 'ces-badge-warning' : 'ces-badge-success'; ?>" id="badge-devmode-status">
                            <?php echo (!empty($status_data['dev_mode'])) ? 'BYPASS ACTIVE' : 'CACHING ON'; ?>
                        </span>
                    </div>
                    <div class="ces-stat-value" id="text-devmode-status" style="color: <?php echo (!empty($status_data['dev_mode'])) ? '#F59E0B' : '#F8FAFC'; ?>;">
                        <?php echo (!empty($status_data['dev_mode'])) ? 'Bypassing' : 'Live Cache'; ?>
                    </div>
                    <div class="ces-stat-sub">Auto-expires after 3 hours</div>
                </div>

                <div class="ces-stat-card">
                    <div class="ces-stat-header">
                        <span class="ces-stat-label">API Status</span>
                        <span class="ces-badge <?php echo $is_configured ? 'ces-badge-success' : 'ces-badge-danger'; ?>">
                            <?php echo $is_configured ? 'CONNECTED' : 'DISCONNECTED'; ?>
                        </span>
                    </div>
                    <div class="ces-stat-value" style="color: <?php echo $is_configured ? '#3B82F6' : '#EF4444'; ?>;">
                        <?php echo $is_configured ? 'REST v1 API' : 'Not Set'; ?>
                    </div>
                    <div class="ces-stat-sub">Automated Event Invalidation Ready</div>
                </div>

            </div>

            <!-- Main Interactive Controls & Sidebar Grid -->
            <div class="ces-main-grid">
                
                <!-- Left Column: Cache Purge Actions & Targeted Tools -->
                <div>
                    
                    <!-- One-Click Invalidation Controls Panel -->
                    <div class="ces-panel">
                        <div class="ces-panel-title">
                            <span>⚡ Quick Cache Operations</span>
                            <span style="font-size: 12px; font-weight: 500; color: var(--ces-text-muted);">Real-time Nginx Invalidation</span>
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            
                            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--ces-border); padding: 18px; border-radius: 10px;">
                                <h4 style="margin: 0 0 6px 0; color: #FFFFFF; font-size: 14px;">🚀 Full FastCGI Cache Purge</h4>
                                <p style="font-size: 12px; color: var(--ces-text-muted); margin: 0 0 14px 0;">Instantly flushes the complete Nginx cache directory for this domain.</p>
                                <button type="button" class="ces-btn ces-btn-primary" id="btn-purge-all" style="width: 100%;">
                                    Purge Entire Cache Now
                                </button>
                            </div>

                            <div style="background: rgba(255, 255, 255, 0.02); border: 1px solid var(--ces-border); padding: 18px; border-radius: 10px;">
                                <h4 style="margin: 0 0 6px 0; color: #FFFFFF; font-size: 14px;">🔄 Flush WordPress Object Cache</h4>
                                <p style="font-size: 12px; color: var(--ces-text-muted); margin: 0 0 14px 0;">Clears WordPress memory transients and internal object cache.</p>
                                <button type="button" class="ces-btn ces-btn-outline" id="btn-flush-obj" style="width: 100%;">
                                    Flush WP Object Cache
                                </button>
                            </div>

                        </div>

                        <!-- Targeted URL Purge Tool -->
                        <div style="margin-top: 20px; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--ces-border); padding: 18px; border-radius: 10px;">
                            <h4 style="margin: 0 0 6px 0; color: #FFFFFF; font-size: 14px;">🎯 Targeted URL / Path Invalidation</h4>
                            <p style="font-size: 12px; color: var(--ces-text-muted); margin: 0 0 10px 0;">Enter a relative path or slug to invalidate a specific page or product without clearing the entire site cache.</p>
                            <div class="ces-input-group">
                                <input type="text" id="ces-purge-url-input" class="ces-input" placeholder="e.g. /shop/ or /product/flagship-phone/" />
                                <button type="button" class="ces-btn ces-btn-primary" id="btn-purge-url">
                                    Purge URL
                                </button>
                            </div>
                        </div>

                    </div>

                    <!-- Development Mode Switcher Panel -->
                    <div class="ces-panel">
                        <div class="ces-panel-title">
                            <span>🛠️ Development Mode (Cache Bypass)</span>
                            <span class="ces-badge <?php echo (!empty($status_data['dev_mode'])) ? 'ces-badge-warning' : 'ces-badge-success'; ?>" id="devmode-pill">
                                <?php echo (!empty($status_data['dev_mode'])) ? 'BYPASS ACTIVE' : 'OFF'; ?>
                            </span>
                        </div>
                        <p style="font-size: 13px; color: var(--ces-text-muted); margin: 0 0 16px 0;">
                            When Development Mode is enabled, Cloud E Panel automatically bypasses the Nginx FastCGI microcache for all visitors. This allows you to inspect real-time CSS/JS changes and PHP updates immediately without having to purge cache repeatedly.
                        </p>
                        <div style="display: flex; align-items: center; justify-content: space-between; background: rgba(255, 255, 255, 0.02); border: 1px solid var(--ces-border); padding: 16px 20px; border-radius: 10px;">
                            <div>
                                <strong style="color: #FFFFFF; font-size: 14px;" id="devmode-title">
                                    <?php echo (!empty($status_data['dev_mode'])) ? 'Development Mode is currently Active' : 'Development Mode is currently Inactive'; ?>
                                </strong>
                                <p style="font-size: 12px; color: var(--ces-text-muted); margin: 4px 0 0 0;">
                                    <?php echo (!empty($status_data['dev_mode'])) ? 'All cache hits are bypassed. Cache will automatically resume after timer.' : 'Nginx is actively caching static & dynamic HTML responses for ultra-fast TTFB.'; ?>
                                </p>
                            </div>
                            <div>
                                <button type="button" class="ces-btn <?php echo (!empty($status_data['dev_mode'])) ? 'ces-btn-danger' : 'ces-btn-amber'; ?>" id="btn-toggle-devmode" data-active="<?php echo (!empty($status_data['dev_mode'])) ? '1' : '0'; ?>">
                                    <?php echo (!empty($status_data['dev_mode'])) ? 'Turn Off Dev Mode' : 'Enable Dev Mode (3h)'; ?>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Invalidation History Log -->
                    <div class="ces-panel">
                        <div class="ces-panel-title">
                            <span>🕒 Recent Invalidation Log</span>
                            <span style="font-size: 12px; font-weight: 500; color: var(--ces-text-muted);">Last 15 Events</span>
                        </div>
                        <?php if (empty($logs)): ?>
                            <p style="color: var(--ces-text-muted); font-size: 13px; margin: 10px 0;">No invalidation events recorded yet. Events will appear here in real time.</p>
                        <?php else: ?>
                            <div style="overflow-x: auto;">
                                <table class="ces-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 180px;">Timestamp</th>
                                            <th>Trigger / Invalidation Target</th>
                                            <th style="width: 100px; text-align: right;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($logs as $log): ?>
                                            <tr>
                                                <td style="font-family: monospace; color: var(--ces-text-muted);"><?php echo esc_html($log['time']); ?></td>
                                                <td style="font-weight: 600; color: #FFFFFF;"><?php echo esc_html($log['type']); ?></td>
                                                <td style="text-align: right;"><span class="ces-badge ces-badge-success">Purged</span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

                <!-- Right Column: System Info & Rules Summary -->
                <div>
                    
                    <!-- Automation Summary Widget -->
                    <div class="ces-panel">
                        <div class="ces-panel-title">
                            <span>⚡ Active Automation</span>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="color: var(--ces-text-muted);">Posts / Pages / CPT</span>
                                <span class="ces-badge ces-badge-success">✓ Enabled</span>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="color: var(--ces-text-muted);">Elementor Editor Saves</span>
                                <span class="ces-badge ces-badge-success">✓ Enabled</span>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="color: var(--ces-text-muted);">WooCommerce &amp; Stock</span>
                                <span class="ces-badge ces-badge-success">✓ Enabled</span>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="color: var(--ces-text-muted);">Nav Menus &amp; Themes</span>
                                <span class="ces-badge ces-badge-success">✓ Enabled</span>
                            </div>
                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                <span style="color: var(--ces-text-muted);">Dual WP Object Flush</span>
                                <span class="ces-badge ces-badge-success">✓ Enabled</span>
                            </div>
                        </div>
                        <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--ces-border);">
                            <a href="<?php echo admin_url('admin.php?page=cloudespeed-triggers'); ?>" class="ces-btn ces-btn-outline" style="width: 100%;">
                                Customize Triggers →
                            </a>
                        </div>
                    </div>

                    <!-- Nginx Cache Details Widget -->
                    <div class="ces-panel">
                        <div class="ces-panel-title">
                            <span>ℹ️ Server Environment</span>
                        </div>
                        <div style="font-size: 12px; color: var(--ces-text-muted); display: flex; flex-direction: column; gap: 10px;">
                            <div>
                                <span style="color: #FFFFFF; font-weight: 600;">Control Panel:</span> Cloud E Panel v0.1.0
                            </div>
                            <div>
                                <span style="color: #FFFFFF; font-weight: 600;">Accelerator:</span> Nginx FastCGI Microcache
                            </div>
                            <div>
                                <span style="color: #FFFFFF; font-weight: 600;">PHP-FPM Runtime:</span> PHP <?php echo phpversion(); ?>
                            </div>
                            <div>
                                <span style="color: #FFFFFF; font-weight: 600;">Cache Invalidation:</span> Async Non-blocking HTTP
                            </div>
                            <div>
                                <span style="color: #FFFFFF; font-weight: 600;">Bypass Cookies:</span> wordpress_logged_in_*, woocommerce_items_in_cart
                            </div>
                        </div>
                    </div>

                </div>

            </div>

            <!-- Floating Toast Notification -->
            <div id="ces-toast">
                <span id="ces-toast-icon">✓</span>
                <span id="ces-toast-msg">Operation completed successfully!</span>
            </div>

        </div>

        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('cloudespeed_dash_nonce'); ?>';

            function showToast(msg, isError) {
                var $t = $('#ces-toast');
                $('#ces-toast-msg').text(msg);
                $('#ces-toast-icon').text(isError ? '✕' : '✓');
                $t.css('border-color', isError ? '#EF4444' : '#06B6D4');
                $t.fadeIn(200);
                setTimeout(function() {
                    $t.fadeOut(300);
                }, 4000);
            }

            // Purge All Cache
            $('#btn-purge-all, #btn-purge-all-top').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Purging Cache...');
                $.post(ajaxurl, {
                    action: 'cloudespeed_ajax_purge_all',
                    nonce: nonce
                }, function(res) {
                    $btn.prop('disabled', false).text('Purge Entire Cache Now');
                    $('#btn-purge-all-top').text('🚀 Purge All Cache');
                    if (res.success) {
                        showToast(res.data.message, false);
                    } else {
                        showToast(res.data.message || 'Purge failed', true);
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Purge Entire Cache Now');
                    $('#btn-purge-all-top').text('🚀 Purge All Cache');
                    showToast('Server request failed or timed out', true);
                });
            });

            // Purge Targeted URL
            $('#btn-purge-url').on('click', function() {
                var url = $('#ces-purge-url-input').val().trim();
                if (!url) {
                    showToast('Please enter a valid URL path (e.g. /shop/)', true);
                    return;
                }
                var $btn = $(this);
                $btn.prop('disabled', true).text('Purging...');
                $.post(ajaxurl, {
                    action: 'cloudespeed_ajax_purge_url',
                    nonce: nonce,
                    url: url
                }, function(res) {
                    $btn.prop('disabled', false).text('Purge URL');
                    if (res.success) {
                        showToast(res.data.message, false);
                        $('#ces-purge-url-input').val('');
                    } else {
                        showToast(res.data.message || 'URL purge failed', true);
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Purge URL');
                    showToast('Server request failed', true);
                });
            });

            // Flush Object Cache
            $('#btn-flush-obj').on('click', function() {
                var $btn = $(this);
                $btn.prop('disabled', true).text('Flushing...');
                $.post(ajaxurl, {
                    action: 'cloudespeed_ajax_flush_object_cache',
                    nonce: nonce
                }, function(res) {
                    $btn.prop('disabled', false).text('Flush WP Object Cache');
                    if (res.success) {
                        showToast(res.data.message, false);
                    } else {
                        showToast(res.data.message, true);
                    }
                });
            });

            // Toggle Development Mode
            $('#btn-toggle-devmode').on('click', function() {
                var $btn = $(this);
                var currentlyActive = $btn.data('active') === 1;
                var targetState = !currentlyActive;

                $btn.prop('disabled', true).text('Switching Mode...');
                $.post(ajaxurl, {
                    action: 'cloudespeed_ajax_toggle_devmode',
                    nonce: nonce,
                    enable: targetState ? 'true' : 'false',
                    hours: 3
                }, function(res) {
                    $btn.prop('disabled', false);
                    if (res.success) {
                        showToast(res.data.message, false);
                        if (targetState) {
                            $btn.data('active', 1).removeClass('ces-btn-amber').addClass('ces-btn-danger').text('Turn Off Dev Mode');
                            $('#devmode-pill, #badge-devmode-status').removeClass('ces-badge-success').addClass('ces-badge-warning').text('BYPASS ACTIVE');
                            $('#text-devmode-status').css('color', '#F59E0B').text('Bypassing');
                            $('#devmode-title').text('Development Mode is currently Active');
                        } else {
                            $btn.data('active', 0).removeClass('ces-btn-danger').addClass('ces-btn-amber').text('Enable Dev Mode (3h)');
                            $('#devmode-pill, #badge-devmode-status').removeClass('ces-badge-warning').addClass('ces-badge-success').text('CACHING ON');
                            $('#text-devmode-status').css('color', '#F8FAFC').text('Live Cache');
                            $('#devmode-title').text('Development Mode is currently Inactive');
                        }
                    } else {
                        showToast(res.data.message || 'Failed to toggle Dev Mode', true);
                        $btn.text(currentlyActive ? 'Turn Off Dev Mode' : 'Enable Dev Mode (3h)');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text(currentlyActive ? 'Turn Off Dev Mode' : 'Enable Dev Mode (3h)');
                    showToast('API request timed out', true);
                });
            });
        });
        </script>
        <?php
    }

    /**
     * RENDER THE TRIGGERS CONFIGURATION PAGE
     */
    public function render_triggers_page() {
        ?>
        <div class="wrap" style="max-width: 900px;">
            <h1>⚡ Cloud E Speed — Invalidation Triggers</h1>
            <p>Select which WordPress and WooCommerce events will automatically invalidate the FastCGI cache.</p>

            <form method="post" action="options.php" style="background: #FFFFFF; border: 1px solid #E2E8F0; padding: 24px; border-radius: 12px; margin-top: 20px;">
                <?php settings_fields('cloudespeed_settings'); ?>
                <input type="hidden" name="cloudespeed_api_url" value="<?php echo esc_attr(get_option('cloudespeed_api_url')); ?>" />
                <input type="hidden" name="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" />
                <input type="hidden" name="cloudespeed_ssl_verify" value="<?php echo esc_attr(get_option('cloudespeed_ssl_verify', '0')); ?>" />

                <table class="form-table">
                    <tr>
                        <th scope="row">Posts, Pages &amp; CPT</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloudespeed_purge_on_post" value="1" <?php checked(get_option('cloudespeed_purge_on_post', '1'), '1'); ?> />
                                Invalidate cache whenever posts, pages, or custom post types are published, modified, or trashed
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WooCommerce Store</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloudespeed_purge_on_woo" value="1" <?php checked(get_option('cloudespeed_purge_on_woo', '1'), '1'); ?> />
                                Invalidate cache on product edits, new products, and inventory/stock deduction on orders
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Menus &amp; Widgets</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloudespeed_purge_on_menu" value="1" <?php checked(get_option('cloudespeed_purge_on_menu', '1'), '1'); ?> />
                                Invalidate cache when navigation menus, widgets, or Customizer settings change
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WP Object Cache</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloudespeed_flush_object_cache" value="1" <?php checked(get_option('cloudespeed_flush_object_cache', '1'), '1'); ?> />
                                Automatically flush WordPress Object Cache and transients alongside Nginx FastCGI purges
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Invalidation Rules', 'primary'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * RENDER THE API SETTINGS PAGE
     */
    public function render_settings_page() {
        ?>
        <div class="wrap" style="max-width: 900px;">
            <h1>🔌 Cloud E Panel API Configuration</h1>
            <p>Connect your WordPress installation to Cloud E Panel to enable instant FastCGI microcache invalidation.</p>

            <form method="post" action="options.php" style="background: #FFFFFF; border: 1px solid #E2E8F0; padding: 24px; border-radius: 12px; margin-top: 20px;">
                <?php settings_fields('cloudespeed_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Webhook Purge URL:</th>
                        <td>
                            <input type="url" name="cloudespeed_api_url" id="cloudespeed_api_url" value="<?php echo esc_attr(get_option('cloudespeed_api_url')); ?>" style="width: 100%; max-width: 550px; font-family: monospace;" placeholder="https://server.cloudetech.org:8443/api/ext/v1/cache/purge" required />
                            <p class="description">Obtained from Cloud E Panel &gt; <strong>Cloud E Speed (⚡)</strong> &gt; <em>API &amp; Plugin Guide</em>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">X-CloudESpeed-Key:</th>
                        <td>
                            <input type="password" name="cloudespeed_api_key" id="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" style="width: 100%; max-width: 550px; font-family: monospace;" required />
                            <p class="description">Your secret website API Key from the Cloud E Panel speed settings.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SSL Verification:</th>
                        <td>
                            <label>
                                <input type="checkbox" name="cloudespeed_ssl_verify" value="1" <?php checked(get_option('cloudespeed_ssl_verify', '0'), '1'); ?> />
                                Verify SSL certificate (Uncheck if using a self-signed certificate on port 8443)
                            </label>
                        </td>
                    </tr>
                </table>

                <div style="margin-top: 20px; display: flex; align-items: center; gap: 12px;">
                    <?php submit_button('Save Configuration', 'primary', 'submit', false); ?>
                    <button type="button" id="btn-test-connection" class="button button-secondary">
                        🔍 Test API Connection
                    </button>
                    <span id="test-connection-status" style="font-weight: 600; font-size: 13px;"></span>
                </div>
            </form>
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
                    $status.html('<span style="color:#EF4444;">✕ Server request timed out.</span>');
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize singleton
CloudESpeedPlugin::get_instance();
