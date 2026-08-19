<?php
/**
 * Tab 3: API & Server Connection Settings View
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

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
