<?php
/**
 * Tab 1: Minimal Dashboard View
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Minimal Metrics Bar -->
<div class="ces-grid-metrics">
    
    <div class="ces-metric-card">
        <div class="ces-metric-header">
            <span class="ces-metric-label">Cache Engine</span>
            <span class="ces-pill <?php echo ($status_data && ($status_data['cache_enabled'] ?? true)) ? 'ces-pill-emerald' : 'ces-pill-amber'; ?>">
                <?php echo ($status_data && ($status_data['cache_enabled'] ?? true)) ? '● Active' : '● Standby'; ?>
            </span>
        </div>
        <div class="ces-metric-value" style="color: #059669;">Nginx FastCGI</div>
    </div>

    <div class="ces-metric-card">
        <div class="ces-metric-header">
            <span class="ces-metric-label">Cache TTL</span>
            <span class="ces-pill ces-pill-cyan"><?php echo esc_html(ucfirst($status_data['cache_profile'] ?? 'WordPress')); ?></span>
        </div>
        <div class="ces-metric-value" style="color: #0284C7;">
            <?php echo esc_html(($status_data['cache_ttl'] ?? 3600) / 60); ?>m
        </div>
    </div>

    <div class="ces-metric-card">
        <div class="ces-metric-header">
            <span class="ces-metric-label">Dev Mode</span>
            <span class="ces-pill <?php echo (!empty($status_data['dev_mode'])) ? 'ces-pill-amber' : 'ces-pill-emerald'; ?>" id="badge-devmode-status">
                <?php echo (!empty($status_data['dev_mode'])) ? '● Bypass' : '● Off'; ?>
            </span>
        </div>
        <div class="ces-metric-value" id="text-devmode-status" style="color: <?php echo (!empty($status_data['dev_mode'])) ? '#D97706' : '#0F172A'; ?>;">
            <?php echo (!empty($status_data['dev_mode'])) ? 'Active' : 'Disabled'; ?>
        </div>
    </div>

    <div class="ces-metric-card">
        <div class="ces-metric-header">
            <span class="ces-metric-label">Server Sync</span>
            <span class="ces-pill <?php echo $is_native ? 'ces-pill-emerald' : ($is_configured ? 'ces-pill-blue' : 'ces-pill-danger'); ?>">
                <?php echo $is_native ? '● NATIVE' : ($is_configured ? '● MANUAL' : '● DISCONNECTED'); ?>
            </span>
        </div>
        <div class="ces-metric-value" style="color: <?php echo $is_native ? '#059669' : ($is_configured ? '#2563EB' : '#DC2626'); ?>;">
            <?php echo $is_native ? 'Connected' : ($is_configured ? 'REST API' : 'Not Set'); ?>
        </div>
    </div>

</div>

<!-- Main Layout Grid -->
<div class="ces-layout-grid">
    
    <!-- Left Column: Quick Cache Actions & Logs -->
    <div>
        
        <!-- Quick Operations Box -->
        <div class="ces-box">
            <div class="ces-box-header">
                <span>⚡ Cache Operations</span>
            </div>
            
            <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
                <button type="button" class="ces-button ces-btn-blue" id="btn-purge-all" style="flex: 1; min-width: 180px;">
                    🚀 Purge Entire Cache
                </button>

                <button type="button" class="ces-button ces-btn-white" id="btn-flush-obj" style="flex: 1; min-width: 180px;">
                    🔄 Flush Object Cache
                </button>

                <button type="button" class="ces-button <?php echo (!empty($status_data['dev_mode'])) ? 'ces-btn-danger-light' : 'ces-btn-amber-light'; ?>" id="btn-toggle-devmode" data-active="<?php echo (!empty($status_data['dev_mode'])) ? '1' : '0'; ?>" style="flex: 1; min-width: 180px;">
                    <?php echo (!empty($status_data['dev_mode'])) ? 'Turn Off Dev Mode' : '🛠️ Enable Dev Mode (3h)'; ?>
                </button>
            </div>

            <!-- Targeted URL Invalidation Bar -->
            <div style="background: #F8FAFC; border: 1px solid var(--ces-border); padding: 14px 16px; border-radius: 10px;">
                <label style="display: block; font-size: 12px; font-weight: 700; color: var(--ces-text-muted); text-transform: uppercase; margin-bottom: 8px;">
                    🎯 Targeted URL Invalidation
                </label>
                <div style="display: flex; gap: 8px;">
                    <input type="text" id="ces-purge-url-input" class="ces-input-clean" style="flex: 1;" placeholder="/shop/ or /product-slug/" />
                    <button type="button" class="ces-button ces-btn-blue" id="btn-purge-url">
                        Purge URL
                    </button>
                </div>
            </div>

        </div>

        <!-- Activity History Log -->
        <div class="ces-box">
            <div class="ces-box-header">
                <span>🕒 Invalidation History</span>
                <span style="font-size: 11px; color: var(--ces-text-muted);">Real-time</span>
            </div>
            <?php if (empty($logs)): ?>
                <p style="color: var(--ces-text-muted); font-size: 13px; margin: 10px 0;">No invalidation events recorded yet.</p>
            <?php else: ?>
                <div style="overflow-x: auto; border: 1px solid var(--ces-border); border-radius: 8px;">
                    <table class="ces-light-table">
                        <thead>
                            <tr>
                                <th style="width: 160px;">Time</th>
                                <th>Target</th>
                                <th style="width: 80px; text-align: right;">Status</th>
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

    <!-- Right Column: Minimal Status Info -->
    <div>
        
        <!-- Summary Widget -->
        <div class="ces-box">
            <div class="ces-box-header">
                <span>⚡ Automation Status</span>
            </div>
            <div style="display: flex; flex-direction: column; gap: 10px; font-size: 13px;">
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="color: var(--ces-text-muted);">Posts &amp; Pages</span>
                    <span class="ces-pill ces-pill-emerald">Active</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="color: var(--ces-text-muted);">Elementor Editor</span>
                    <span class="ces-pill ces-pill-emerald">Active</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="color: var(--ces-text-muted);">WooCommerce &amp; Stock</span>
                    <span class="ces-pill ces-pill-emerald">Active</span>
                </div>
                <div style="display: flex; align-items: center; justify-content: space-between;">
                    <span style="color: var(--ces-text-muted);">Navigation Menus</span>
                    <span class="ces-pill ces-pill-emerald">Active</span>
                </div>
            </div>
            <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid var(--ces-border-light);">
                <button type="button" class="ces-button ces-btn-white" onclick="switchCesTab('tab-triggers')" style="width: 100%; font-size: 12px;">
                    Configure Rules →
                </button>
            </div>
        </div>

        <!-- Server Environment Details -->
        <div class="ces-box">
            <div class="ces-box-header">
                <span>ℹ️ Server Info</span>
            </div>
            <div style="font-size: 12px; color: var(--ces-text-muted); display: flex; flex-direction: column; gap: 8px;">
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--ces-text-main); font-weight: 600;">Stack:</span>
                    <span>Cloud E Panel</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--ces-text-main); font-weight: 600;">Accelerator:</span>
                    <span style="color: #059669; font-weight: 600;">Nginx FastCGI</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--ces-text-main); font-weight: 600;">Sync:</span>
                    <span>HTTPS (Port 443)</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span style="color: var(--ces-text-main); font-weight: 600;">PHP:</span>
                    <span>PHP <?php echo phpversion(); ?></span>
                </div>
            </div>
        </div>

    </div>

</div>
