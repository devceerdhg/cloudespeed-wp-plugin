<?php
/**
 * Cloud E Speed — Automated Invalidation Hook Engine
 *
 * Listens for content updates, WooCommerce inventory events, Elementor saves,
 * and navigations, triggering automated FastCGI cache flushes.
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloudESpeed_Purger {

    private static $purged_this_request = false;

    /**
     * Register all enabled automated hooks.
     */
    public static function register_hooks() {
        $opt_posts = get_option('cloudespeed_purge_on_post', '1');
        $opt_woo   = get_option('cloudespeed_purge_on_woo', '1');
        $opt_menus = get_option('cloudespeed_purge_on_menu', '1');

        if ($opt_posts === '1') {
            add_action('save_post', [__CLASS__, 'on_post_save'], 10, 2);
            add_action('deleted_post', [__CLASS__, 'on_post_delete'], 10, 2);
            add_action('trashed_post', [__CLASS__, 'on_post_delete'], 10, 2);
            add_action('comment_post', [__CLASS__, 'on_comment_change']);
            add_action('edit_comment', [__CLASS__, 'on_comment_change']);
            add_action('delete_comment', [__CLASS__, 'on_comment_change']);
            add_action('elementor/editor/after_save', [__CLASS__, 'on_elementor_save'], 10, 2);
        }

        if ($opt_woo === '1') {
            add_action('woocommerce_update_product', [__CLASS__, 'on_woo_product_update']);
            add_action('woocommerce_delete_product', [__CLASS__, 'on_woo_product_update']);
            add_action('woocommerce_reduce_order_stock', [__CLASS__, 'on_woo_stock_change']);
            add_action('woocommerce_restore_order_stock', [__CLASS__, 'on_woo_stock_change']);
            add_action('woocommerce_product_set_stock_status', [__CLASS__, 'on_woo_stock_change']);
        }

        if ($opt_menus === '1') {
            add_action('wp_update_nav_menu', [__CLASS__, 'on_menu_update']);
            add_action('customize_save_after', [__CLASS__, 'on_customizer_save']);
            add_action('switch_theme', [__CLASS__, 'on_theme_switch']);
        }
    }

    /**
     * Purge cache with optional URL and dual object cache flushing.
     *
     * @param string $url_path
     * @return bool
     */
    public static function purge($url_path = '') {
        if (self::$purged_this_request) {
            return true;
        }

        if (!empty($url_path)) {
            $res = CloudESpeed_API::purge_url($url_path);
            self::log_event('URL: ' . $url_path);
        } else {
            $res = CloudESpeed_API::purge_all();
            self::log_event('Full FastCGI Purge');
        }

        self::$purged_this_request = true;

        if (get_option('cloudespeed_flush_object_cache', '1') === '1') {
            wp_cache_flush();
        }

        return !is_wp_error($res);
    }

    /**
     * Record an invalidation event in transient log for the dashboard.
     *
     * @param string $description
     */
    public static function log_event($description) {
        $logs = get_transient('cloudespeed_purge_logs');
        if (!is_array($logs)) {
            $logs = [];
        }
        array_unshift($logs, [
            'time' => current_time('mysql'),
            'type' => sanitize_text_field($description),
        ]);
        $logs = array_slice($logs, 0, 15);
        set_transient('cloudespeed_purge_logs', $logs, DAY_IN_SECONDS * 7);
    }

    /**
     * Get recent purge logs.
     *
     * @return array
     */
    public static function get_logs() {
        $logs = get_transient('cloudespeed_purge_logs');
        return is_array($logs) ? $logs : [];
    }

    // Event Handlers
    public static function on_post_save($post_id, $post) {
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if ($post && in_array($post->post_status, ['publish', 'trash', 'future'], true)) {
            self::purge(get_permalink($post_id));
        }
    }

    public static function on_post_delete($post_id) {
        self::purge();
    }

    public static function on_comment_change() {
        self::purge();
    }

    public static function on_elementor_save($post_id, $editor_data) {
        self::purge(get_permalink($post_id));
    }

    public static function on_woo_product_update($product_id) {
        self::purge(get_permalink($product_id));
    }

    public static function on_woo_stock_change() {
        self::purge();
    }

    public static function on_menu_update() {
        self::purge();
    }

    public static function on_customizer_save() {
        self::purge();
    }

    public static function on_theme_switch() {
        self::purge();
    }
}
