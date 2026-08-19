<?php
/**
 * Cloud E Speed — Main Dashboard View Container
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<?php
$is_dev_active = CloudESpeed_Admin::is_dev_mode_active();
?>

<div class="ces-unified-wrap">
    
    <!-- Clean Minimal Header Card -->
    <div class="ces-header-card">
        <div style="display: flex; align-items: center; gap: 12px;">
            <h1 class="ces-page-title" style="margin: 0;">
                <span>Cloud E Speed</span>
                <span class="ces-pill <?php echo $is_dev_active ? 'ces-pill-amber' : 'ces-pill-emerald'; ?>" id="header-devmode-pill" style="font-size: 11px; padding: 3px 8px; border-radius: 6px; letter-spacing: 0.02em;">
                    <?php echo $is_dev_active ? '⚠️ Cache Paused (Dev Mode)' : '● Cache Enabled'; ?>
                </span>
            </h1>
            <span class="ces-brand-tag" style="margin: 0;">FastCGI Accelerator</span>
        </div>
        <div style="display: flex; align-items: center; gap: 10px;">
            <button type="button" class="ces-button ces-btn-blue" id="btn-purge-all-top">
                🚀 Purge All Cache
            </button>
        </div>
    </div>

    <!-- Minimal Dev Mode Warning Alert Banner -->
    <div id="ces-devmode-warning-banner" class="ces-devmode-alert-banner" style="<?php echo !$is_dev_active ? 'display:none;' : 'display:flex;'; ?>">
        <div style="display: flex; align-items: center; gap: 10px;">
            <span style="font-size: 18px;">⚠️</span>
            <strong style="color: #92400E; font-size: 13px;">FastCGI Cache is Paused (Development Mode Active) — visitors see live site.</strong>
        </div>
        <div>
            <button type="button" class="ces-button ces-btn-blue" id="btn-quick-disable-devmode" style="padding: 6px 14px; font-size: 12px;">
                ▶️ Resume Caching
            </button>
        </div>
    </div>

    <!-- Modern Clean Tabs Bar -->
    <div class="ces-tabs-bar">
        <button type="button" class="ces-tab-btn active" data-tab="tab-dashboard">
            📊 Dashboard
        </button>
        <button type="button" class="ces-tab-btn" data-tab="tab-triggers">
            ⚙️ Auto-Purge Rules
        </button>
        <button type="button" class="ces-tab-btn" data-tab="tab-api">
            🔌 Server Sync <?php if ($is_native): ?><span class="ces-pill ces-pill-emerald" style="font-size: 9px; padding: 2px 6px;">AUTO</span><?php endif; ?>
        </button>
        <button type="button" class="ces-tab-btn" data-tab="tab-guide">
            📖 Guide &amp; Docs
        </button>
    </div>

    <!-- TAB 1: DASHBOARD & CONTROLS -->
    <div id="tab-dashboard" class="ces-tab-pane active">
        <?php require_once CLOUDESPEED_PLUGIN_DIR . 'admin/views/tab-dashboard.php'; ?>
    </div>

    <!-- TAB 2: INVALIDATION RULES -->
    <div id="tab-triggers" class="ces-tab-pane">
        <?php require_once CLOUDESPEED_PLUGIN_DIR . 'admin/views/tab-triggers.php'; ?>
    </div>

    <!-- TAB 3: API & SERVER SETTINGS -->
    <div id="tab-api" class="ces-tab-pane">
        <?php require_once CLOUDESPEED_PLUGIN_DIR . 'admin/views/tab-api.php'; ?>
    </div>

    <!-- TAB 4: ARCHITECTURE GUIDE -->
    <div id="tab-guide" class="ces-tab-pane">
        <?php require_once CLOUDESPEED_PLUGIN_DIR . 'admin/views/tab-guide.php'; ?>
    </div>

    <!-- Clean Floating Toast -->
    <div id="ces-toast">
        <span id="ces-toast-icon">✓</span>
        <span id="ces-toast-msg">Success</span>
    </div>

</div>
