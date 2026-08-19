<?php
/**
 * Cloud E Speed — Admin UI & AJAX Controller
 *
 * Registers admin menus, enqueues scripts & styles, handles AJAX actions,
 * and renders clean light theme views.
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloudESpeed_Admin {

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_bar_menu', [__CLASS__, 'register_admin_bar_menu'], 100);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // AJAX handlers
        add_action('wp_ajax_cloudespeed_ajax_purge_all', [__CLASS__, 'ajax_purge_all']);
        add_action('wp_ajax_cloudespeed_ajax_purge_url', [__CLASS__, 'ajax_purge_url']);
        add_action('wp_ajax_cloudespeed_ajax_toggle_devmode', [__CLASS__, 'ajax_toggle_devmode']);
        add_action('wp_ajax_cloudespeed_ajax_flush_object_cache', [__CLASS__, 'ajax_flush_object_cache']);
        add_action('wp_ajax_cloudespeed_test_connection', [__CLASS__, 'ajax_test_connection']);
        add_action('wp_ajax_cloudespeed_get_status', [__CLASS__, 'ajax_get_status']);

        // Legacy POST handlers
        add_action('admin_post_cloudespeed_purge_all', [__CLASS__, 'handle_manual_purge_all']);
        add_action('admin_post_cloudespeed_purge_current', [__CLASS__, 'handle_manual_purge_current']);
    }

    public static function register_admin_menu() {
        // High-precision custom brand SVG icon for Cloud E Speed
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z" fill="#A7AAAD"/><path d="M12.5 8L8 14h4l-1 5 6-7h-4.5l1-4z" fill="#0284C7"/></svg>';
        $icon_data = 'data:image/svg+xml;base64,' . base64_encode($icon_svg);

        add_menu_page(
            __('Cloud E Speed Accelerator', 'cloudespeed'),
            'Cloud E Speed',
            'manage_options',
            'cloudespeed',
            [__CLASS__, 'render_dashboard_page'],
            $icon_data,
            3
        );

        add_action('admin_head', [__CLASS__, 'render_admin_custom_styles']);
        add_action('wp_head', [__CLASS__, 'render_admin_custom_styles']);
    }

    public static function render_admin_custom_styles() {
        if (!is_admin_bar_showing()) {
            return;
        }
        echo '<style>
            #toplevel_page_cloudespeed .wp-menu-image img {
                padding: 7px 0 0 0 !important;
                width: 20px !important;
                height: 20px !important;
                opacity: 0.85;
                transition: all 0.2s ease;
            }
            #toplevel_page_cloudespeed:hover .wp-menu-image img,
            #toplevel_page_cloudespeed.wp-has-current-submenu .wp-menu-image img {
                opacity: 1 !important;
                filter: drop-shadow(0 0 4px rgba(2, 132, 199, 0.6));
            }
            #wp-admin-bar-cloudespeed-root .ab-item {
                display: flex !important;
                align-items: center !important;
            }
            .ces-topbar-pill {
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 10px !important;
                font-weight: 800 !important;
                padding: 2px 7px !important;
                border-radius: 6px !important;
                margin-left: 6px !important;
                line-height: 1.2 !important;
                text-transform: uppercase !important;
                letter-spacing: 0.04em !important;
                transition: all 0.2s ease !important;
            }
            .ces-pill-live {
                background: #059669 !important;
                color: #FFFFFF !important;
            }
            .ces-pill-dev {
                background: #F59E0B !important;
                color: #0F172A !important;
                box-shadow: 0 0 8px rgba(245, 158, 11, 0.6) !important;
            }
        </style>';
    }

    public static function register_settings() {
        register_setting('cloudespeed_settings', 'cloudespeed_api_url', 'esc_url_raw');
        register_setting('cloudespeed_settings', 'cloudespeed_api_key', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_purge_on_post', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_purge_on_woo', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_purge_on_menu', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_flush_object_cache', 'sanitize_text_field');
        register_setting('cloudespeed_settings', 'cloudespeed_ssl_verify', 'sanitize_text_field');
    }

    public static function register_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $is_dev_mode = (get_transient('cloudespeed_dev_mode_active') === 1);
        $pill_class = $is_dev_mode ? 'ces-pill-dev' : 'ces-pill-live';
        $pill_text = $is_dev_mode ? 'Disabled' : 'Active';
        $purge_all_url = wp_nonce_url(admin_url('admin-post.php?action=cloudespeed_purge_all'), 'cloudespeed_purge_all');

        $wp_admin_bar->add_node([
            'id'    => 'cloudespeed-root',
            'title' => '<span style="display:inline-flex;align-items:center;gap:6px;font-weight:700;"><svg style="width:15px;height:15px;margin-top:-2px;" viewBox="0 0 24 24" fill="none"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96z" fill="#94A3B8"/><path d="M12.5 8L8 14h4l-1 5 6-7h-4.5l1-4z" fill="#38BDF8"/></svg><span style="color:#F1F5F9;">Cloud E Speed</span><span id="ab-cloudespeed-pill" class="ces-topbar-pill ' . $pill_class . '">' . $pill_text . '</span></span>',
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

    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_cloudespeed') {
            return;
        }

        wp_enqueue_style(
            'cloudespeed-admin-css',
            CLOUDESPEED_PLUGIN_URL . 'admin/css/admin-dashboard.css',
            [],
            CLOUDESPEED_VERSION
        );

        wp_enqueue_script(
            'cloudespeed-admin-js',
            CLOUDESPEED_PLUGIN_URL . 'admin/js/admin-dashboard.js',
            ['jquery'],
            CLOUDESPEED_VERSION,
            true
        );

        wp_localize_script('cloudespeed-admin-js', 'cloudespeedData', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'dashNonce' => wp_create_nonce('cloudespeed_dash_nonce'),
            'testNonce' => wp_create_nonce('cloudespeed_test_nonce'),
        ]);
    }

    public static function render_dashboard_page() {
        $conn_info     = CloudESpeed_Discovery::get_connection_info();
        $is_configured = CloudESpeed_Discovery::is_configured();
        $is_native     = $conn_info['is_native'];
        $logs          = CloudESpeed_Purger::get_logs();
        $status_data   = null;

        if ($is_configured) {
            $res = CloudESpeed_API::get_status();
            if (!is_wp_error($res)) {
                $status_data = $res;
                if (isset($status_data['dev_mode'])) {
                    if (!empty($status_data['dev_mode'])) {
                        set_transient('cloudespeed_dev_mode_active', 1, 3 * HOUR_IN_SECONDS);
                    } else {
                        delete_transient('cloudespeed_dev_mode_active');
                    }
                }
            }
        }

        // Include Main View Template
        require_once CLOUDESPEED_PLUGIN_DIR . 'admin/views/dashboard-page.php';
    }

    // AJAX Handlers
    public static function ajax_purge_all() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $res = CloudESpeed_API::purge_all();
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        if (get_option('cloudespeed_flush_object_cache', '1') === '1') {
            wp_cache_flush();
        }
        CloudESpeed_Purger::log_event('Full FastCGI Purge (Dashboard)');
        wp_send_json_success(['message' => 'Nginx FastCGI microcache successfully purged!']);
    }

    public static function ajax_purge_url() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $url = isset($_POST['url']) ? sanitize_text_field(wp_unslash($_POST['url'])) : '';
        if (empty($url)) {
            wp_send_json_error(['message' => 'Please provide a valid URL or path (e.g. /shop/).']);
        }
        $res = CloudESpeed_API::purge_url($url);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        CloudESpeed_Purger::log_event('URL: ' . $url . ' (Dashboard)');
        wp_send_json_success(['message' => "Cache successfully invalidated for: {$url}"]);
    }

    public static function ajax_toggle_devmode() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $enable = isset($_POST['enable']) && $_POST['enable'] === 'true';
        $hours  = isset($_POST['hours']) ? intval($_POST['hours']) : 3;

        $res = CloudESpeed_API::toggle_dev_mode($enable, $hours);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }

        if ($enable) {
            set_transient('cloudespeed_dev_mode_active', 1, $hours * HOUR_IN_SECONDS);
        } else {
            delete_transient('cloudespeed_dev_mode_active');
        }

        $msg = $enable ? "Development Mode activated (bypassing cache for {$hours} hours)" : "Development Mode disabled (FastCGI caching restored)";
        CloudESpeed_Purger::log_event($msg);
        wp_send_json_success(['message' => $msg, 'dev_mode' => $enable]);
    }

    public static function ajax_flush_object_cache() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        wp_cache_flush();
        CloudESpeed_Purger::log_event('WordPress Object Cache Flushed');
        wp_send_json_success(['message' => 'WordPress Object Cache & transients cleared!']);
    }

    public static function ajax_get_status() {
        check_ajax_referer('cloudespeed_dash_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        $res = CloudESpeed_API::get_status();
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }
        wp_send_json_success($res);
    }

    public static function ajax_test_connection() {
        check_ajax_referer('cloudespeed_test_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }

        $api_url = isset($_POST['api_url']) ? esc_url_raw(wp_unslash($_POST['api_url'])) : '';
        $api_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';

        $info = CloudESpeed_Discovery::get_connection_info();
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
            // Fallback for native server loopback
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

    public static function handle_manual_purge_all() {
        if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_purge_all')) {
            wp_die('Unauthorized');
        }
        CloudESpeed_Purger::purge();
        $redirect = wp_get_referer() ?: admin_url('admin.php?page=cloudespeed');
        wp_redirect(add_query_arg('cloudespeed_msg', 'purged_all', $redirect));
        exit;
    }

    public static function handle_manual_purge_current() {
        if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_purge_current')) {
            wp_die('Unauthorized');
        }
        $target = isset($_GET['target']) ? esc_url_raw(wp_unslash($_GET['target'])) : '';
        CloudESpeed_Purger::purge($target);
        $redirect = $target ? $target : home_url('/');
        wp_redirect(add_query_arg('cloudespeed_msg', 'purged_url', $redirect));
        exit;
    }
}
