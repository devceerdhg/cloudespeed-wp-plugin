<?php
/**
 * Plugin Name: Cloud E Speed Cache Purger
 * Plugin URI: https://cloudetech.org/
 * Description: Automatically purges Nginx FastCGI microcache via Cloud E Panel API when posts, products, or stock are updated.
 * Version: 1.0.0
 * Author: Cloud E Tech
 * Author URI: https://cloudetech.org/
 * Text Domain: cloudespeed
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloudESpeedPlugin {
    private $api_key;
    private $api_url;
    // We use a static flag to prevent multiple purges during a single page load/request
    private static $purged_this_request = false;

    public function __construct() {
        $this->api_key = get_option('cloudespeed_api_key', '');
        $this->api_url = get_option('cloudespeed_api_url', '');

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);

        if (!empty($this->api_key) && !empty($this->api_url)) {
            // WordPress Core Hooks
            add_action('save_post', [$this, 'trigger_purge']);
            add_action('deleted_post', [$this, 'trigger_purge']);
            add_action('trashed_post', [$this, 'trigger_purge']);
            
            // WooCommerce Specific Hooks
            add_action('woocommerce_update_product', [$this, 'trigger_purge']);
            add_action('woocommerce_delete_product', [$this, 'trigger_purge']);
            add_action('woocommerce_reduce_order_stock', [$this, 'trigger_purge']);
            add_action('woocommerce_restore_order_stock', [$this, 'trigger_purge']);
            
            // Admin bar button
            add_action('admin_bar_menu', [$this, 'add_admin_bar_button'], 100);
        }
    }

    public function add_settings_page() {
        add_options_page(
            'Cloud E Speed Settings',
            'Cloud E Speed (Cache)',
            'manage_options',
            'cloudespeed-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        register_setting('cloudespeed_settings_group', 'cloudespeed_api_key');
        register_setting('cloudespeed_settings_group', 'cloudespeed_api_url');
    }

    public function render_settings_page() {
        $msg = isset($_GET['msg']) ? sanitize_text_field($_GET['msg']) : '';
        ?>
        <div class="wrap">
            <h1><span style="color: #06B6D4;">⚡</span> Cloud E Speed - Nginx Cache Purger</h1>
            <?php if ($msg === 'purged'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>Success:</strong> Cache purge signal sent to Cloud E Panel!</p>
                </div>
            <?php endif; ?>
            <p>Configure the API connection to your Cloud E Panel to automatically purge the Nginx FastCGI Cache whenever content (posts, pages, products, or stock) is updated.</p>
            
            <form method="post" action="options.php" style="background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 650px; border-radius: 8px; margin-top: 20px;">
                <?php settings_fields('cloudespeed_settings_group'); ?>
                <?php do_settings_sections('cloudespeed_settings_group'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Panel Purge API URL:</th>
                        <td>
                            <input type="url" name="cloudespeed_api_url" value="<?php echo esc_attr(get_option('cloudespeed_api_url')); ?>" style="width: 100%;" placeholder="e.g. https://your-panel.com:8443/api/ext/v1/cache/purge" required />
                            <p class="description">The webhook endpoint provided in your Cloud E Panel "API & Plugin Guide" section.</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">X-CloudESpeed-Key:</th>
                        <td>
                            <input type="password" name="cloudespeed_api_key" value="<?php echo esc_attr(get_option('cloudespeed_api_key')); ?>" style="width: 100%;" required />
                            <p class="description">The secret API key for this website.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Configuration', 'primary'); ?>
            </form>
            
            <?php if (!empty($this->api_key) && !empty($this->api_url)): ?>
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; max-width: 650px; border-radius: 8px; margin-top: 20px;">
                <h3>Manual Cache Purge</h3>
                <p>Use this button if you have made changes directly to the database or if you want to force a cache clear immediately.</p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <input type="hidden" name="action" value="cloudespeed_manual_purge">
                    <?php wp_nonce_field('cloudespeed_manual_purge_nonce'); ?>
                    <button type="submit" class="button button-secondary">⚡ Purge Nginx Cache Now</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function trigger_purge($post_id = null) {
        // Prevent purging on autosave or revisions
        if ($post_id && (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id))) {
            return;
        }

        // Prevent duplicate calls on the same request
        if (self::$purged_this_request) {
            return;
        }

        if (empty($this->api_key) || empty($this->api_url)) {
            return;
        }

        $args = [
            'headers' => [
                'X-CloudESpeed-Key' => $this->api_key,
                'Content-Type'      => 'application/json'
            ],
            'body' => json_encode(['event' => 'content_updated', 'timestamp' => time()]),
            'timeout' => 3,
            'blocking' => false // Fire and forget (do not block WP execution)
        ];

        wp_remote_post($this->api_url, $args);
        self::$purged_this_request = true;
    }
    
    public function add_admin_bar_button($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $purge_url = wp_nonce_url(admin_url('admin-post.php?action=cloudespeed_manual_purge'), 'cloudespeed_manual_purge_nonce');
        $wp_admin_bar->add_node([
            'id'    => 'cloudespeed_purge',
            'title' => '⚡ Purge Nginx Cache',
            'href'  => $purge_url,
            'meta'  => [
                'title' => 'Purge Cloud E Speed Cache',
            ],
        ]);
    }
}

new CloudESpeedPlugin();

// Handle manual purge request from settings page or admin bar
add_action('admin_post_cloudespeed_manual_purge', function() {
    if (!current_user_can('manage_options') || !check_admin_referer('cloudespeed_manual_purge_nonce')) {
        wp_die('Unauthorized');
    }

    $plugin = new CloudESpeedPlugin();
    $plugin->trigger_purge();

    $redirect_url = wp_get_referer();
    if (!$redirect_url) {
        $redirect_url = admin_url('options-general.php?page=cloudespeed-settings');
    }
    
    wp_redirect(add_query_arg('msg', 'purged', $redirect_url));
    exit;
});
