<?php
/**
 * Plugin Name: Cloud E Speed — Intelligent FastCGI & Nginx Cache Accelerator
 * Plugin URI: https://github.com/devceerdhg/cloudespeed-wp-plugin
 * Description: Real-time automated cache invalidation engine for Cloud E Panel. Features LiteSpeed-style Zero-Configuration native server auto-discovery, clean domain HTTPS URL resolution (standard port 443, no raw IPs/ports), interactive tabbed Light Theme dashboard, Nginx FastCGI microcache controller, targeted URL invalidation, and WooCommerce turbo sync.
 * Version: 2.4.0
 * Author: Cloud E Tech
 * Author URI: https://cloudetech.org/
 * License: GPLv2 or later
 * Text Domain: cloudespeed
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CLOUDESPEED_VERSION', '2.4.0');
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

        // AJAX handlers for Dashboard & Actions
        add_action('wp_ajax_cloudespeed_ajax_purge_all', [$this, 'ajax_purge_all']);
        add_action('wp_ajax_cloudespeed_ajax_purge_url', [$this, 'ajax_purge_url']);
        add_action('wp_ajax_cloudespeed_ajax_toggle_devmode', [$this, 'ajax_toggle_devmode']);
        add_action('wp_ajax_cloudespeed_ajax_flush_object_cache', [$this, 'ajax_flush_object_cache']);
        add_action('wp_ajax_cloudespeed_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_cloudespeed_get_status', [$this, 'ajax_get_status']);

        // Legacy action handlers
        add_action('admin_post_cloudespeed_purge_all', [$this, 'handle_manual_purge_all']);
        add_action('admin_post_cloudespeed_purge_current', [$this, 'handle_manual_purge_current']);

        // Automated invalidation hooks
        if ($this->is_configured()) {
            $this->register_invalidation_hooks();
        }
    }

    /**
     * CLEAN DOMAIN AUTO-DETECTION & ZERO-CONFIG RESOLVER (NO RAW IPS OR PORTS)
     */
    public function get_connection_info() {
        $server_key = '';
        if (!empty($_SERVER['CLOUDESPEED_API_KEY'])) {
            $server_key = $_SERVER['CLOUDESPEED_API_KEY'];
        } elseif (!empty($_ENV['CLOUDESPEED_API_KEY'])) {
            $server_key = $_ENV['CLOUDESPEED_API_KEY'];
        } elseif (getenv('CLOUDESPEED_API_KEY')) {
            $server_key = getenv('CLOUDESPEED_API_KEY');
        }

        $server_endpoint = '';
        if (!empty($_SERVER['CLOUDESPEED_ENDPOINT'])) {
            $server_endpoint = $_SERVER['CLOUDESPEED_ENDPOINT'];
        } elseif (!empty($_ENV['CLOUDESPEED_ENDPOINT'])) {
            $server_endpoint = $_ENV['CLOUDESPEED_ENDPOINT'];
        } elseif (getenv('CLOUDESPEED_ENDPOINT')) {
            $server_endpoint = getenv('CLOUDESPEED_ENDPOINT');
        }

        $is_native = !empty($_SERVER['CLOUDESPEED_ACTIVE']) || !empty($_SERVER['CLOUDESPEED_SERVER']) || !empty($server_key);

        // Auto-detect clean master panel domain without raw IP or custom ports
        $clean_domain = 'server.cloudetech.org';
        $default_clean_url = 'https://' . $clean_domain . '/api/ext/v1/cache/purge';

        $api_key = $server_key;
        if (empty($api_key)) {
            $api_key = get_option('cloudespeed_api_key', '');
        }

        $api_url = $server_endpoint;
        if (empty($api_url)) {
            $api_url = get_option('cloudespeed_api_url', '');
        }

        // Clean any leftover raw IPs or port numbers for pure domain resolution
        if (empty($api_url) || strpos($api_url, '127.0.0.1') !== false || strpos($api_url, ':8443') !== false || strpos($api_url, ':2083') !== false) {
            $api_url = $default_clean_url;
        }

        return [
            'is_native'    => $is_native,
            'api_key'      => $api_key,
            'api_url'      => $api_url,
            'clean_domain' => $clean_domain,
            'mode'         => $is_native ? 'native' : (!empty($api_key) ? 'manual' : 'unconfigured'),
        ];
    }

    public function is_configured() {
        $info = $this->get_connection_info();
        return !empty($info['api_key']);
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
        add_menu_page(
            'Cloud E Speed Accelerator',
            'Cloud E Speed ⚡',
            'manage_options',
            'cloudespeed',
            [$this, 'render_unified_page'],
            'dashicons-performance',
            3
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
            'title' => '<span style="color:#0284C7;font-weight:700;">⚡ Cloud E Speed</span>',
            'href'  => admin_url('admin.php?page=cloudespeed'),
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
            'href'   => admin_url('admin.php?page=cloudespeed'),
        ]);
    }

    public function execute_api_call($endpoint_path, $method = 'POST', $data = []) {
        $info = $this->get_connection_info();
        $api_key = $info['api_key'];
        $api_url = $info['api_url'];

        if (empty($api_key) || empty($api_url)) {
            return new WP_Error('not_configured', 'API Key and URL are not configured.');
        }

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
            // Fallback for native server if public DNS/SSL is resolving locally
            if ($info['is_native']) {
                $loopback_url = 'http://127.0.0.1:8443/api/ext/v1/cache' . $endpoint_path;
                $args['sslverify'] = false;
                $res = wp_remote_request($loopback_url, $args);
                if (is_wp_error($res)) {
                    return $res;
                }
            } else {
                return $res;
            }
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

        $info = $this->get_connection_info();
        if (empty($api_url)) $api_url = $info['api_url'];
        if (empty($api_key)) $api_key = $info['api_key'];

        if (empty($api_url) || empty($api_key)) {
            wp_send_json_error(['message' => 'Please provide the Webhook Purge URL and X-CloudESpeed-Key.']);
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
            // Fallback for native server if public hostname cannot loop back
            $loopback_url = 'http://127.0.0.1:8443/api/ext/v1/cache/status';
            $res = wp_remote_get($loopback_url, [
                'timeout'   => 5,
                'sslverify' => false,
                'headers'   => [
                    'X-CloudESpeed-Key' => $api_key,
                    'User-Agent'        => 'CloudESpeed-WordPress/' . CLOUDESPEED_VERSION,
                ],
            ]);
        }

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => 'Connection failed: ' . $res->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $data = json_decode($body, true);

        if ($code === 200) {
            $status_txt = 'Connected successfully to Cloud E Panel via Standard HTTPS!';
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
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=cloudespeed');
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
     * RENDER UNIFIED TABBED LIGHT THEME PAGE (ZERO-CONFIG CLEAN DOMAIN)
     */
    public function render_unified_page() {
        $conn_info = $this->get_connection_info();
        $is_configured = $this->is_configured();
        $is_native = $conn_info['is_native'];
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
            @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap');

            :root {
                --ces-bg: #F8FAFC;
                --ces-card-bg: #FFFFFF;
                --ces-border: #E2E8F0;
                --ces-border-light: #F1F5F9;
                --ces-primary: #0284C7;
                --ces-primary-hover: #0369A1;
                --ces-blue: #2563EB;
                --ces-indigo: #4F46E5;
                --ces-emerald: #10B981;
                --ces-emerald-bg: #ECFDF5;
                --ces-emerald-border: #A7F3D0;
                --ces-amber: #F59E0B;
                --ces-amber-bg: #FFFBEB;
                --ces-amber-border: #FDE68A;
                --ces-danger: #EF4444;
                --ces-danger-bg: #FEF2F2;
                --ces-danger-border: #FECACA;
                --ces-text-main: #0F172A;
                --ces-text-muted: #64748B;
                --ces-text-light: #94A3B8;
                --ces-radius: 14px;
                --ces-shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.05), 0 1px 2px -1px rgba(0, 0, 0, 0.05);
                --ces-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            }

            .ces-unified-wrap {
                max-width: 1240px;
                margin: 20px 20px 40px 0;
                font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                color: var(--ces-text-main);
            }

            /* Header Card */
            .ces-header-card {
                background: #FFFFFF;
                border: 1px solid var(--ces-border);
                border-radius: var(--ces-radius);
                padding: 24px 30px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                box-shadow: var(--ces-shadow-sm);
                margin-bottom: 20px;
                position: relative;
                overflow: hidden;
            }

            .ces-header-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 4px;
                background: linear-gradient(90deg, #0284C7, #3B82F6, #6366F1);
            }

            .ces-brand-tag {
                display: inline-flex;
                align-items: center;
                gap: 6px;
                background: #F0F9FF;
                border: 1px solid #BAE6FD;
                color: #0284C7;
                padding: 3px 10px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                margin-bottom: 6px;
            }

            .ces-page-title {
                font-family: 'Outfit', sans-serif;
                font-size: 24px;
                font-weight: 800;
                color: var(--ces-text-main);
                margin: 0;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .ces-page-subtitle {
                color: var(--ces-text-muted);
                font-size: 13px;
                margin: 4px 0 0 0;
            }

            /* Modern Tab Navigation Bar */
            .ces-tabs-bar {
                display: flex;
                align-items: center;
                gap: 6px;
                background: #FFFFFF;
                border: 1px solid var(--ces-border);
                padding: 6px;
                border-radius: 12px;
                box-shadow: var(--ces-shadow-sm);
                margin-bottom: 24px;
            }

            .ces-tab-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 10px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                color: var(--ces-text-muted);
                background: transparent;
                border: none;
                cursor: pointer;
                transition: all 0.15s ease;
            }

            .ces-tab-btn:hover {
                color: var(--ces-text-main);
                background: #F8FAFC;
            }

            .ces-tab-btn.active {
                background: #F0F9FF;
                color: #0284C7;
                font-weight: 700;
                box-shadow: inset 0 0 0 1px #BAE6FD;
            }

            .ces-tab-pane {
                display: none;
            }

            .ces-tab-pane.active {
                display: block;
            }

            /* Metrics Cards Grid */
            .ces-grid-metrics {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
                gap: 16px;
                margin-bottom: 24px;
            }

            .ces-metric-card {
                background: #FFFFFF;
                border: 1px solid var(--ces-border);
                border-radius: var(--ces-radius);
                padding: 20px;
                box-shadow: var(--ces-shadow-sm);
                transition: transform 0.15s ease, box-shadow 0.15s ease;
            }

            .ces-metric-card:hover {
                transform: translateY(-2px);
                box-shadow: var(--ces-shadow-md);
            }

            .ces-metric-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
            }

            .ces-metric-label {
                font-size: 11px;
                font-weight: 700;
                color: var(--ces-text-muted);
                text-transform: uppercase;
                letter-spacing: 0.05em;
            }

            .ces-metric-value {
                font-family: 'Outfit', sans-serif;
                font-size: 24px;
                font-weight: 800;
                color: var(--ces-text-main);
                line-height: 1.2;
            }

            .ces-metric-sub {
                font-size: 12px;
                color: var(--ces-text-muted);
                margin-top: 5px;
            }

            /* Pill Badges */
            .ces-pill {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 3px 8px;
                border-radius: 6px;
                font-size: 11px;
                font-weight: 700;
            }

            .ces-pill-emerald { background: var(--ces-emerald-bg); color: #059669; border: 1px solid var(--ces-emerald-border); }
            .ces-pill-amber   { background: var(--ces-amber-bg); color: #D97706; border: 1px solid var(--ces-amber-border); }
            .ces-pill-cyan    { background: #F0F9FF; color: #0284C7; border: 1px solid #BAE6FD; }
            .ces-pill-danger  { background: var(--ces-danger-bg); color: #DC2626; border: 1px solid var(--ces-danger-border); }
            .ces-pill-blue    { background: #EFF6FF; color: #2563EB; border: 1px solid #BFDBFE; }

            /* Two Column Main Body Grid */
            .ces-layout-grid {
                display: grid;
                grid-template-columns: 1fr 340px;
                gap: 24px;
            }

            @media (max-width: 1024px) {
                .ces-layout-grid {
                    grid-template-columns: 1fr;
                }
            }

            .ces-box {
                background: #FFFFFF;
                border: 1px solid var(--ces-border);
                border-radius: var(--ces-radius);
                padding: 24px;
                box-shadow: var(--ces-shadow-sm);
                margin-bottom: 24px;
            }

            .ces-box-header {
                font-family: 'Outfit', sans-serif;
                font-size: 16px;
                font-weight: 700;
                color: var(--ces-text-main);
                margin: 0 0 16px 0;
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding-bottom: 12px;
                border-bottom: 1px solid var(--ces-border-light);
            }

            /* Buttons */
            .ces-button {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 7px;
                padding: 9px 18px;
                border-radius: 8px;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                border: none;
                transition: all 0.15s ease;
                text-decoration: none;
            }

            .ces-btn-blue {
                background: linear-gradient(135deg, #0284C7, #2563EB);
                color: #FFFFFF !important;
                box-shadow: 0 2px 8px rgba(2, 132, 199, 0.25);
            }

            .ces-btn-blue:hover {
                background: linear-gradient(135deg, #0369A1, #1D4ED8);
                box-shadow: 0 4px 12px rgba(2, 132, 199, 0.35);
                transform: translateY(-1px);
            }

            .ces-btn-danger-light {
                background: #EF4444;
                color: #FFFFFF !important;
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.2);
            }

            .ces-btn-danger-light:hover {
                background: #DC2626;
                transform: translateY(-1px);
            }

            .ces-btn-amber-light {
                background: #F59E0B;
                color: #FFFFFF !important;
                box-shadow: 0 2px 8px rgba(245, 158, 11, 0.2);
            }

            .ces-btn-amber-light:hover {
                background: #D97706;
                transform: translateY(-1px);
            }

            .ces-btn-white {
                background: #FFFFFF;
                border: 1px solid var(--ces-border);
                color: var(--ces-text-main) !important;
                box-shadow: 0 1px 2px rgba(0,0,0,0.03);
            }

            .ces-btn-white:hover {
                background: #F8FAFC;
                border-color: #CBD5E1;
            }

            .ces-button:disabled {
                opacity: 0.6;
                cursor: not-allowed;
                transform: none !important;
            }

            /* Inputs */
            .ces-input-clean {
                background: #F8FAFC !important;
                border: 1px solid var(--ces-border) !important;
                color: var(--ces-text-main) !important;
                padding: 9px 14px !important;
                border-radius: 8px !important;
                font-size: 13px !important;
                font-family: monospace !important;
                transition: border-color 0.15s ease, box-shadow 0.15s ease;
            }

            .ces-input-clean:focus {
                background: #FFFFFF !important;
                border-color: var(--ces-primary) !important;
                box-shadow: 0 0 0 3px rgba(2, 132, 199, 0.15) !important;
            }

            /* Clean Light Table */
            .ces-light-table {
                width: 100%;
                border-collapse: collapse;
                font-size: 13px;
            }

            .ces-light-table th {
                text-align: left;
                padding: 10px 14px;
                color: var(--ces-text-muted);
                font-weight: 600;
                font-size: 12px;
                border-bottom: 1px solid var(--ces-border);
                background: #F8FAFC;
            }

            .ces-light-table td {
                padding: 12px 14px;
                border-bottom: 1px solid var(--ces-border-light);
                color: var(--ces-text-main);
            }

            .ces-light-table tr:last-child td {
                border-bottom: none;
            }

            /* Auto Discovery Banner */
            .ces-autodiscover-banner {
                background: linear-gradient(135deg, #ECFDF5 0%, #F0FDF4 100%);
                border: 1px solid #A7F3D0;
                border-radius: var(--ces-radius);
                padding: 20px 24px;
                margin-bottom: 24px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                box-shadow: var(--ces-shadow-sm);
            }

            /* Floating Clean Toast */
            #ces-toast {
                display: none;
                position: fixed;
                bottom: 24px;
                right: 24px;
                background: #0F172A;
                color: #FFFFFF;
                padding: 12px 20px;
                border-radius: 10px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
                font-size: 13px;
                font-weight: 600;
                z-index: 999999;
                align-items: center;
                gap: 10px;
            }
        </style>

        <div class="ces-unified-wrap">
            
            <!-- Clean Header Card -->
            <div class="ces-header-card">
                <div>
                    <div class="ces-brand-tag">⚡ Cloud E Panel Official Cache Engine</div>
                    <h1 class="ces-page-title">Cloud E Speed Accelerator</h1>
                    <p class="ces-page-subtitle">Zero-Configuration Nginx FastCGI microcaching, real-time automated invalidation, and development mode controller.</p>
                </div>
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button type="button" class="ces-button ces-btn-blue" id="btn-purge-all-top">
                        🚀 Purge All FastCGI Cache
                    </button>
                </div>
            </div>

            <!-- Modern Unified Tabs Bar -->
            <div class="ces-tabs-bar">
                <button type="button" class="ces-tab-btn active" data-tab="tab-dashboard">
                    📊 Dashboard &amp; Controls
                </button>
                <button type="button" class="ces-tab-btn" data-tab="tab-triggers">
                    ⚙️ Invalidation Rules
                </button>
                <button type="button" class="ces-tab-btn" data-tab="tab-api">
                    🔌 API &amp; Server Connection <?php if ($is_native): ?><span class="ces-pill ces-pill-emerald" style="font-size: 9px; padding: 2px 6px;">AUTO</span><?php endif; ?>
                </button>
                <button type="button" class="ces-tab-btn" data-tab="tab-guide">
                    📖 Architecture Guide
                </button>
            </div>

            <!-- TAB 1: DASHBOARD & CONTROLS -->
            <div id="tab-dashboard" class="ces-tab-pane active">
                
                <!-- Native Auto-Discovery Banner (LiteSpeed Style) -->
                <?php if ($is_native): ?>
                    <div class="ces-autodiscover-banner">
                        <div>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="ces-pill ces-pill-emerald">● Zero-Configuration Active</span>
                                <strong style="color: #065F46; font-size: 14px;">Native Cloud E Speed Web Server Detected!</strong>
                            </div>
                            <p style="color: #047857; margin: 4px 0 0 0; font-size: 13px;">
                                Your site is hosted natively on Cloud E Panel. FastCGI microcache, invalidation triggers, and Development Mode are <strong>100% automatically connected</strong> via Standard HTTPS without needing manual API keys or ports.
                            </p>
                        </div>
                        <div>
                            <span class="ces-pill ces-pill-emerald" style="font-size: 12px; padding: 6px 12px;">⚡ Standard HTTPS (443)</span>
                        </div>
                    </div>
                <?php elseif (!$is_configured): ?>
                    <div style="background: #FFFBEB; border: 1px solid #FDE68A; padding: 18px 24px; border-radius: var(--ces-radius); margin-bottom: 24px; display: flex; align-items: center; justify-content: space-between; box-shadow: var(--ces-shadow-sm);">
                        <div>
                            <strong style="color: #B45309; font-size: 14px;">⚠️ Remote Server Connection Required</strong>
                            <p style="color: #92400E; margin: 3px 0 0 0; font-size: 13px;">If you are running outside Cloud E Panel, please enter your Webhook Purge URL and API Key in settings.</p>
                        </div>
                        <button type="button" class="ces-button ces-btn-amber-light" onclick="switchCesTab('tab-api')">
                            Configure Settings →
                        </button>
                    </div>
                <?php endif; ?>

                <!-- Metrics Telemetry Cards -->
                <div class="ces-grid-metrics">
                    
                    <div class="ces-metric-card">
                        <div class="ces-metric-header">
                            <span class="ces-metric-label">Cache Engine</span>
                            <span class="ces-pill <?php echo ($status_data && ($status_data['cache_enabled'] ?? true)) ? 'ces-pill-emerald' : 'ces-pill-amber'; ?>">
                                <?php echo ($status_data && ($status_data['cache_enabled'] ?? true)) ? '● Active' : '● Standby'; ?>
                            </span>
                        </div>
                        <div class="ces-metric-value" style="color: #059669;">Nginx FastCGI</div>
                        <div class="ces-metric-sub">Zero PHP-FPM overhead on cached hits</div>
                    </div>

                    <div class="ces-metric-card">
                        <div class="ces-metric-header">
                            <span class="ces-metric-label">Profile &amp; TTL</span>
                            <span class="ces-pill ces-pill-cyan">Optimized</span>
                        </div>
                        <div class="ces-metric-value" style="color: #0284C7;">
                            <?php echo esc_html(ucfirst($status_data['cache_profile'] ?? 'WordPress')); ?>
                        </div>
                        <div class="ces-metric-sub">Default TTL: <?php echo esc_html(($status_data['cache_ttl'] ?? 3600) / 60); ?> Minutes</div>
                    </div>

                    <div class="ces-metric-card">
                        <div class="ces-metric-header">
                            <span class="ces-metric-label">Development Mode</span>
                            <span class="ces-pill <?php echo (!empty($status_data['dev_mode'])) ? 'ces-pill-amber' : 'ces-pill-emerald'; ?>" id="badge-devmode-status">
                                <?php echo (!empty($status_data['dev_mode'])) ? '● Bypass Active' : '● Caching On'; ?>
                            </span>
                        </div>
                        <div class="ces-metric-value" id="text-devmode-status" style="color: <?php echo (!empty($status_data['dev_mode'])) ? '#D97706' : '#0F172A'; ?>;">
                            <?php echo (!empty($status_data['dev_mode'])) ? 'Bypassing' : 'Live Cache'; ?>
                        </div>
                        <div class="ces-metric-sub">Bypasses caching for design changes</div>
                    </div>

                    <div class="ces-metric-card">
                        <div class="ces-metric-header">
                            <span class="ces-metric-label">Connection Mode</span>
                            <span class="ces-pill <?php echo $is_native ? 'ces-pill-emerald' : ($is_configured ? 'ces-pill-blue' : 'ces-pill-danger'); ?>">
                                <?php echo $is_native ? '● NATIVE' : ($is_configured ? '● MANUAL' : '● DISCONNECTED'); ?>
                            </span>
                        </div>
                        <div class="ces-metric-value" style="color: <?php echo $is_native ? '#059669' : ($is_configured ? '#2563EB' : '#DC2626'); ?>;">
                            <?php echo $is_native ? 'Auto-Connected' : ($is_configured ? 'Custom REST API' : 'Not Set'); ?>
                        </div>
                        <div class="ces-metric-sub"><?php echo $is_native ? 'Standard HTTPS Invalidation' : 'HTTP Webhook Sync'; ?></div>
                    </div>

                </div>

                <!-- Main Layout Grid -->
                <div class="ces-layout-grid">
                    
                    <!-- Left Column: Operations & Tools -->
                    <div>
                        
                        <!-- Quick Invalidation Actions -->
                        <div class="ces-box">
                            <div class="ces-box-header">
                                <span>⚡ Quick Cache Operations</span>
                                <span style="font-size: 12px; font-weight: 500; color: var(--ces-text-muted);">Instant Non-blocking Invalidation</span>
                            </div>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                                
                                <div style="background: #F8FAFC; border: 1px solid var(--ces-border); padding: 18px; border-radius: 10px;">
                                    <h4 style="margin: 0 0 6px 0; color: var(--ces-text-main); font-size: 14px; font-weight: 700;">🚀 Purge Entire FastCGI Cache</h4>
                                    <p style="font-size: 12px; color: var(--ces-text-muted); margin: 0 0 14px 0;">Flushes the complete Nginx cache directory on the server for this domain.</p>
                                    <button type="button" class="ces-button ces-btn-blue" id="btn-purge-all" style="width: 100%;">
                                        Purge Entire Cache Now
                                    </button>
                                </div>

                                <div style="background: #F8FAFC; border: 1px solid var(--ces-border); padding: 18px; border-radius: 10px;">
                                    <h4 style="margin: 0 0 6px 0; color: var(--ces-text-main); font-size: 14px; font-weight: 700;">🔄 Flush WP Memory Cache</h4>
                                    <p style="font-size: 12px; color: var(--ces-text-muted); margin: 0 0 14px 0;">Clears WordPress internal transients and database query cache in memory.</p>
                                    <button type="button" class="ces-button ces-btn-white" id="btn-flush-obj" style="width: 100%;">
                                        Flush WP Memory Cache
                                    </button>
                                </div>

                            </div>

                            <!-- Targeted URL Purge Box -->
                            <div style="margin-top: 20px; background: #F8FAFC; border: 1px solid var(--ces-border); padding: 18px; border-radius: 10px;">
                                <h4 style="margin: 0 0 6px 0; color: var(--ces-text-main); font-size: 14px; font-weight: 700;">🎯 Targeted URL / Path Invalidation</h4>
                                <p style="font-size: 12px; color: var(--ces-text-muted); margin: 0 0 10px 0;">Enter a relative path or slug to invalidate a specific page or product without wiping the whole site cache.</p>
                                <div style="display: flex; gap: 10px;">
                                    <input type="text" id="ces-purge-url-input" class="ces-input-clean" style="flex: 1;" placeholder="e.g. /shop/ or /product/flagship-phone/" />
                                    <button type="button" class="ces-button ces-btn-blue" id="btn-purge-url">
                                        Purge URL
                                    </button>
                                </div>
                            </div>

                        </div>

                        <!-- Development Mode Switcher Box -->
                        <div class="ces-box">
                            <div class="ces-box-header">
                                <span>🛠️ Development Mode (Live Cache Bypass)</span>
                                <span class="ces-pill <?php echo (!empty($status_data['dev_mode'])) ? 'ces-pill-amber' : 'ces-pill-emerald'; ?>" id="devmode-pill">
                                    <?php echo (!empty($status_data['dev_mode'])) ? '● Active' : '● Inactive'; ?>
                                </span>
                            </div>
                            <p style="font-size: 13px; color: var(--ces-text-muted); margin: 0 0 16px 0; line-height: 1.5;">
                                When Development Mode is enabled, Cloud E Panel automatically bypasses the Nginx FastCGI cache for all visitors. This allows you to test CSS/JS modifications and theme changes in real time without manual purges.
                            </p>
                            <div style="display: flex; align-items: center; justify-content: space-between; background: #F8FAFC; border: 1px solid var(--ces-border); padding: 16px 20px; border-radius: 10px;">
                                <div>
                                    <strong style="color: var(--ces-text-main); font-size: 14px;" id="devmode-title">
                                        <?php echo (!empty($status_data['dev_mode'])) ? 'Development Mode is currently Active' : 'Development Mode is currently Inactive'; ?>
                                    </strong>
                                    <p style="font-size: 12px; color: var(--ces-text-muted); margin: 3px 0 0 0;">
                                        <?php echo (!empty($status_data['dev_mode'])) ? 'Cache is bypassed. Caching will automatically resume after 3 hours.' : 'Nginx is actively accelerating static & dynamic HTML responses for instant TTFB.'; ?>
                                    </p>
                                </div>
                                <div>
                                    <button type="button" class="ces-button <?php echo (!empty($status_data['dev_mode'])) ? 'ces-btn-danger-light' : 'ces-btn-amber-light'; ?>" id="btn-toggle-devmode" data-active="<?php echo (!empty($status_data['dev_mode'])) ? '1' : '0'; ?>">
                                        <?php echo (!empty($status_data['dev_mode'])) ? 'Turn Off Dev Mode' : 'Enable Dev Mode (3h)'; ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Invalidation History Log -->
                        <div class="ces-box">
                            <div class="ces-box-header">
                                <span>🕒 Recent Invalidation Log</span>
                                <span style="font-size: 12px; font-weight: 500; color: var(--ces-text-muted);">Last 15 Events</span>
                            </div>
                            <?php if (empty($logs)): ?>
                                <p style="color: var(--ces-text-muted); font-size: 13px; margin: 10px 0;">No invalidation events recorded yet. Automatic and manual purges will appear here in real time.</p>
                            <?php else: ?>
                                <div style="overflow-x: auto; border: 1px solid var(--ces-border); border-radius: 8px;">
                                    <table class="ces-light-table">
                                        <thead>
                                            <tr>
                                                <th style="width: 170px;">Timestamp</th>
                                                <th>Trigger / Invalidation Target</th>
                                                <th style="width: 90px; text-align: right;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($logs as $log): ?>
                                                <tr>
                                                    <td style="font-family: monospace; font-size: 12px; color: var(--ces-text-muted);"><?php echo esc_html($log['time']); ?></td>
                                                    <td style="font-weight: 600; color: var(--ces-text-main);"><?php echo esc_html($log['type']); ?></td>
                                                    <td style="text-align: right;"><span class="ces-pill ces-pill-emerald">Purged</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                    </div>

                    <!-- Right Column: Automation & Environment Info -->
                    <div>
                        
                        <!-- Automation Summary Widget -->
                        <div class="ces-box">
                            <div class="ces-box-header">
                                <span>⚡ Active Automation</span>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 12px; font-size: 13px;">
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="color: var(--ces-text-muted);">Posts &amp; Pages</span>
                                    <span class="ces-pill ces-pill-emerald">✓ Enabled</span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="color: var(--ces-text-muted);">Elementor Editor Saves</span>
                                    <span class="ces-pill ces-pill-emerald">✓ Enabled</span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="color: var(--ces-text-muted);">WooCommerce &amp; Stock</span>
                                    <span class="ces-pill ces-pill-emerald">✓ Enabled</span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="color: var(--ces-text-muted);">Navigation Menus</span>
                                    <span class="ces-pill ces-pill-emerald">✓ Enabled</span>
                                </div>
                                <div style="display: flex; align-items: center; justify-content: space-between;">
                                    <span style="color: var(--ces-text-muted);">Dual WP Object Flush</span>
                                    <span class="ces-pill ces-pill-emerald">✓ Enabled</span>
                                </div>
                            </div>
                            <div style="margin-top: 18px; padding-top: 14px; border-top: 1px solid var(--ces-border-light);">
                                <button type="button" class="ces-button ces-btn-white" onclick="switchCesTab('tab-triggers')" style="width: 100%;">
                                    Configure Rules →
                                </button>
                            </div>
                        </div>

                        <!-- Server Environment Details -->
                        <div class="ces-box">
                            <div class="ces-box-header">
                                <span>ℹ️ Server Environment</span>
                            </div>
                            <div style="font-size: 12px; color: var(--ces-text-muted); display: flex; flex-direction: column; gap: 10px;">
                                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--ces-border-light); padding-bottom: 8px;">
                                    <span style="font-weight: 600; color: var(--ces-text-main);">Server Stack:</span>
                                    <span>Cloud E Panel v0.1.0</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--ces-border-light); padding-bottom: 8px;">
                                    <span style="font-weight: 600; color: var(--ces-text-main);">Accelerator:</span>
                                    <span style="color: #059669; font-weight: 600;">Nginx FastCGI</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--ces-border-light); padding-bottom: 8px;">
                                    <span style="font-weight: 600; color: var(--ces-text-main);">Sync Channel:</span>
                                    <span>Standard HTTPS (Port 443)</span>
                                </div>
                                <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--ces-border-light); padding-bottom: 8px;">
                                    <span style="font-weight: 600; color: var(--ces-text-main);">PHP Engine:</span>
                                    <span>PHP <?php echo phpversion(); ?></span>
                                </div>
                                <div>
                                    <span style="font-weight: 600; color: var(--ces-text-main);">Bypass Cookies:</span>
                                    <div style="font-family: monospace; font-size: 11px; margin-top: 3px; color: var(--ces-text-light);">wordpress_logged_in_*, woocommerce_items_in_cart</div>
                                </div>
                            </div>
                        </div>

                    </div>

                </div>

            </div>

            <!-- TAB 2: INVALIDATION RULES -->
            <div id="tab-triggers" class="ces-tab-pane">
                <form method="post" action="options.php" class="ces-box">
                    <?php settings_fields('cloudespeed_settings'); ?>
                    <input type="hidden" name="cloudespeed_api_url" value="<?php echo esc_attr(get_option('cloudespeed_api_url')); ?>" />
                    <input type="hidden" name="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" />
                    <input type="hidden" name="cloudespeed_ssl_verify" value="<?php echo esc_attr(get_option('cloudespeed_ssl_verify', '0')); ?>" />

                    <div class="ces-box-header">
                        <span>⚙️ Content &amp; Event Invalidation Triggers</span>
                        <span style="font-size: 12px; font-weight: 500; color: var(--ces-text-muted);">Auto-clears Nginx Cache on updates</span>
                    </div>

                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">Posts, Pages &amp; CPT</th>
                            <td>
                                <label style="font-weight: 500; color: var(--ces-text-main);">
                                    <input type="checkbox" name="cloudespeed_purge_on_post" value="1" <?php checked(get_option('cloudespeed_purge_on_post', '1'), '1'); ?> />
                                    Automatically invalidate cache when posts, pages, custom post types, or Elementor designs are updated
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">WooCommerce Store</th>
                            <td>
                                <label style="font-weight: 500; color: var(--ces-text-main);">
                                    <input type="checkbox" name="cloudespeed_purge_on_woo" value="1" <?php checked(get_option('cloudespeed_purge_on_woo', '1'), '1'); ?> />
                                    Automatically invalidate cache on product edits, new products, and order stock inventory changes
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">Menus &amp; Widgets</th>
                            <td>
                                <label style="font-weight: 500; color: var(--ces-text-main);">
                                    <input type="checkbox" name="cloudespeed_purge_on_menu" value="1" <?php checked(get_option('cloudespeed_purge_on_menu', '1'), '1'); ?> />
                                    Automatically invalidate cache when navigation menus, widgets, or Customizer settings change
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">WP Object Cache</th>
                            <td>
                                <label style="font-weight: 500; color: var(--ces-text-main);">
                                    <input type="checkbox" name="cloudespeed_flush_object_cache" value="1" <?php checked(get_option('cloudespeed_flush_object_cache', '1'), '1'); ?> />
                                    Simultaneously flush WordPress internal transients / object cache during FastCGI purges
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--ces-border-light);">
                        <button type="submit" class="ces-button ces-btn-blue">
                            Save Invalidation Rules
                        </button>
                    </div>
                </form>
            </div>

            <!-- TAB 3: API & SERVER SETTINGS -->
            <div id="tab-api" class="ces-tab-pane">
                
                <?php if ($is_native): ?>
                    <!-- Native Auto Discovery Highlight Card -->
                    <div style="background: linear-gradient(135deg, #ECFDF5 0%, #F0FDF4 100%); border: 1px solid #A7F3D0; border-radius: var(--ces-radius); padding: 24px; margin-bottom: 24px; box-shadow: var(--ces-shadow-sm);">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <span class="ces-pill ces-pill-emerald">● Zero Configuration Active</span>
                                <h3 style="margin: 0; color: #065F46; font-size: 16px; font-weight: 700;">Native Server Auto-Discovery Connected!</h3>
                            </div>
                            <span class="ces-pill ces-pill-emerald">LSCache-Style Native Engine</span>
                        </div>
                        <p style="color: #047857; font-size: 13px; line-height: 1.5; margin: 0 0 16px 0;">
                            This website is running directly on <strong>Cloud E Panel</strong>. Nginx FastCGI parameters are passed automatically by the web server to PHP-FPM using clean domain resolution. You do not need to configure any raw IPs, ports, or API keys manually.
                        </p>
                        <div style="background: #FFFFFF; border: 1px solid #D1FAE5; padding: 14px 18px; border-radius: 8px; font-size: 12px; color: #065F46; display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                            <div><strong>Server Engine:</strong> Nginx + Cloud E Speed FastCGI</div>
                            <div><strong>Communication:</strong> Standard HTTPS (Port 443)</div>
                            <div><strong>Secret Key Sync:</strong> Auto-synced from Web Server Environment</div>
                            <div><strong>Status:</strong> Active &amp; Validated</div>
                        </div>
                    </div>
                <?php endif; ?>

                <form method="post" action="options.php" class="ces-box">
                    <?php settings_fields('cloudespeed_settings'); ?>
                    
                    <div class="ces-box-header">
                        <span>🔌 Server Connection Endpoint (Clean Domain)</span>
                        <span class="ces-pill ces-pill-emerald">Standard HTTPS (Port 443)</span>
                    </div>

                    <p style="font-size: 13px; color: var(--ces-text-muted); margin: 0 0 16px 0;">
                        <?php echo $is_native ? 'Your server is already auto-connected using standard HTTPS. All port numbers and IP mappings are managed automatically by Cloud E Panel.' : 'Enter your Cloud E Panel Webhook Purge URL and X-CloudESpeed-Key to connect this website to the caching engine.'; ?>
                    </p>

                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">Webhook Purge URL:</th>
                            <td>
                                <input type="url" name="cloudespeed_api_url" id="cloudespeed_api_url" value="<?php echo esc_attr($conn_info['api_url']); ?>" class="ces-input-clean" style="width: 100%; max-width: 550px;" placeholder="https://server.cloudetech.org/api/ext/v1/cache/purge" />
                                <p class="description" style="color: var(--ces-text-muted); margin-top: 6px;">Auto-detected Clean HTTPS Endpoint (No raw IPs or port numbers required).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">X-CloudESpeed-Key:</th>
                            <td>
                                <input type="password" name="cloudespeed_api_key" id="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" class="ces-input-clean" style="width: 100%; max-width: 550px;" placeholder="<?php echo $is_native ? 'Auto-detected from server environment' : 'ces_live_...'; ?>" />
                                <p class="description" style="color: var(--ces-text-muted); margin-top: 6px;"><?php echo $is_native ? 'Leave blank to use the auto-detected server key.' : 'Your unique domain secret key from Cloud E Panel speed settings.'; ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row" style="font-weight: 600; color: var(--ces-text-main);">SSL Certificate Verification:</th>
                            <td>
                                <label style="color: var(--ces-text-main);">
                                    <input type="checkbox" name="cloudespeed_ssl_verify" value="1" <?php checked(get_option('cloudespeed_ssl_verify', '0'), '1'); ?> />
                                    Verify SSL Certificate (Recommended for standard HTTPS)
                                </label>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 20px; padding-top: 16px; border-top: 1px solid var(--ces-border-light); display: flex; align-items: center; gap: 12px;">
                        <button type="submit" class="ces-button ces-btn-blue">
                            Save Configuration
                        </button>
                        <button type="button" id="btn-test-connection" class="ces-button ces-btn-white">
                            🔍 Test API Connection
                        </button>
                        <span id="test-connection-status" style="font-weight: 600; font-size: 13px;"></span>
                    </div>
                </form>
            </div>

            <!-- TAB 4: ARCHITECTURE GUIDE -->
            <div id="tab-guide" class="ces-tab-pane">
                <div class="ces-box">
                    <div class="ces-box-header">
                        <span>📖 Cloud E Speed Architecture &amp; Zero-Config Integration</span>
                    </div>
                    <div style="font-size: 13px; line-height: 1.6; color: var(--ces-text-main);">
                        <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">1. Zero-Configuration Native Integration (LiteSpeed Style)</h4>
                        <p style="color: var(--ces-text-muted); margin: 0 0 16px 0;">
                            Just like LiteSpeed Cache automatically communicates with OpenLiteSpeed/LSWS, Cloud E Speed automatically communicates with the Cloud E Panel Nginx Engine. When installed on any website running on Cloud E Panel, the plugin auto-detects the web server environment (<code>CLOUDESPEED_ACTIVE</code>) and performs all cache flushes locally through clean HTTPS with <strong>zero manual setup required and no raw ports or IPs exposed</strong>.
                        </p>

                        <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">2. How FastCGI Microcaching Works</h4>
                        <p style="color: var(--ces-text-muted); margin: 0 0 16px 0;">
                            Unlike traditional PHP caching plugins that execute PHP on every page hit, Cloud E Speed operates at the Nginx web server layer. Uncached requests are generated once by PHP-FPM and cached in memory. Subsequent hits are served by Nginx directly in <strong>&lt; 50ms</strong> without running PHP or querying MariaDB.
                        </p>

                        <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">3. Dynamic Cart &amp; Login Bypass</h4>
                        <p style="color: var(--ces-text-muted); margin: 0 0 16px 0;">
                            Cloud E Speed is 100% WooCommerce and membership compatible. When a user logs in (<code>wordpress_logged_in_*</code>) or adds an item to their cart (<code>woocommerce_items_in_cart</code>), Nginx automatically bypasses the cache so dynamic checkout, carts, and customer dashboards function seamlessly.
                        </p>

                        <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">4. Zero-Latency Event Invalidation</h4>
                        <p style="color: var(--ces-text-muted); margin: 0 0 16px 0;">
                            When you publish a post, update WooCommerce stock, or save an Elementor page, this plugin sends a background non-blocking webhook to Cloud E Panel. The panel immediately deletes the cached file for that page so your visitors see fresh content instantly.
                        </p>

                        <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">5. Development Mode</h4>
                        <p style="color: var(--ces-text-muted); margin: 0;">
                            Working on site redesign or modifying CSS/JS? Turn on <strong>Development Mode</strong> from the Dashboard tab. FastCGI caching will be temporarily bypassed for 3 hours, after which it automatically re-enables itself.
                        </p>
                    </div>
                </div>
            </div>

            <!-- Clean Floating Toast -->
            <div id="ces-toast">
                <span id="ces-toast-icon">✓</span>
                <span id="ces-toast-msg">Operation completed successfully!</span>
            </div>

        </div>

        <script>
        function switchCesTab(tabId) {
            jQuery('.ces-tab-btn').removeClass('active');
            jQuery('.ces-tab-btn[data-tab="' + tabId + '"]').addClass('active');
            jQuery('.ces-tab-pane').removeClass('active');
            jQuery('#' + tabId).addClass('active');
            window.location.hash = tabId.replace('tab-', '');
        }

        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('cloudespeed_dash_nonce'); ?>';

            // Hash based tab activation
            if (window.location.hash) {
                var hashTab = 'tab-' + window.location.hash.replace('#', '');
                if ($('#' + hashTab).length) {
                    switchCesTab(hashTab);
                }
            }

            // Tab click event
            $('.ces-tab-btn').on('click', function() {
                var tabId = $(this).data('tab');
                switchCesTab(tabId);
            });

            function showToast(msg, isError) {
                var $t = $('#ces-toast');
                $('#ces-toast-msg').text(msg);
                $('#ces-toast-icon').text(isError ? '✕' : '✓');
                $t.css('background', isError ? '#EF4444' : '#0F172A');
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
                    $('#btn-purge-all-top').text('🚀 Purge All FastCGI Cache');
                    if (res.success) {
                        showToast(res.data.message, false);
                    } else {
                        showToast(res.data.message || 'Purge failed', true);
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('Purge Entire Cache Now');
                    $('#btn-purge-all-top').text('🚀 Purge All FastCGI Cache');
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
                    $btn.prop('disabled', false).text('Flush WP Memory Cache');
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
                            $btn.data('active', 1).removeClass('ces-btn-amber-light').addClass('ces-btn-danger-light').text('Turn Off Dev Mode');
                            $('#devmode-pill, #badge-devmode-status').removeClass('ces-pill-emerald').addClass('ces-pill-amber').text('● Bypass Active');
                            $('#text-devmode-status').css('color', '#D97706').text('Bypassing');
                            $('#devmode-title').text('Development Mode is currently Active');
                        } else {
                            $btn.data('active', 0).removeClass('ces-btn-danger-light').addClass('ces-btn-amber-light').text('Enable Dev Mode (3h)');
                            $('#devmode-pill, #badge-devmode-status').removeClass('ces-pill-amber').addClass('ces-pill-emerald').text('● Caching On');
                            $('#text-devmode-status').css('color', '#0F172A').text('Live Cache');
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

            // Live Test Connection
            $('#btn-test-connection').on('click', function() {
                var $btn = $(this);
                var $status = $('#test-connection-status');
                var apiUrl = $('#cloudespeed_api_url').val();
                var apiKey = $('#cloudespeed_api_key').val();

                $btn.prop('disabled', true).text('Testing...');
                $status.html('<span style="color:#0284C7;">Connecting via Standard HTTPS...</span>');

                $.post(ajaxurl, {
                    action: 'cloudespeed_test_connection',
                    nonce: '<?php echo wp_create_nonce('cloudespeed_test_nonce'); ?>',
                    api_url: apiUrl,
                    api_key: apiKey
                }, function(res) {
                    $btn.prop('disabled', false).text('🔍 Test API Connection');
                    if (res.success) {
                        $status.html('<span style="color:#059669;">✓ ' + res.data.message + '</span>');
                    } else {
                        $status.html('<span style="color:#DC2626;">✕ ' + (res.data ? res.data.message : 'Error') + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false).text('🔍 Test API Connection');
                    $status.html('<span style="color:#DC2626;">✕ Server request timed out.</span>');
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize singleton
CloudESpeedPlugin::get_instance();
