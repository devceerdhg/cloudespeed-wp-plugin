<?php
/**
 * Tab 2: Invalidation Triggers & Rules View
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

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
