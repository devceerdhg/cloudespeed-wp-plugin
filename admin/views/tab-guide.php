<?php
/**
 * Tab 4: Architecture & Comprehensive Guide View
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="ces-box">
    <div class="ces-box-header">
        <span>📖 Cloud E Speed — Architecture &amp; User Guide</span>
        <span class="ces-pill ces-pill-cyan">Documentation</span>
    </div>
    
    <div style="font-size: 13px; line-height: 1.6; color: var(--ces-text-main);">
        
        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">1. Zero-Configuration Native Integration</h4>
            <p style="color: var(--ces-text-muted); margin: 0 0 10px 0;">
                Cloud E Speed connects automatically to Cloud E Panel's Nginx Web Server Engine. When installed on any domain hosted on the panel, it detects the native web server environment (<code>CLOUDESPEED_ACTIVE</code>) and performs all cache operations via clean HTTPS without requiring raw IPs or port configurations.
            </p>
        </div>

        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">2. How FastCGI Microcaching Works</h4>
            <p style="color: var(--ces-text-muted); margin: 0 0 10px 0;">
                Unlike conventional PHP-based caching plugins that still trigger PHP runtime overhead, Cloud E Speed operates at the Nginx web server layer. Uncached requests are compiled once by PHP-FPM and cached. Subsequent visitor requests are served directly by Nginx in <strong>&lt; 50ms</strong> without touching PHP or querying the database.
            </p>
        </div>

        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">3. Dynamic Cart, Checkout &amp; Login Bypass</h4>
            <p style="color: var(--ces-text-muted); margin: 0 0 10px 0;">
                Cloud E Speed is 100% compatible with WooCommerce and membership portals. When a user logs in (<code>wordpress_logged_in_*</code>) or adds a product to their shopping cart (<code>woocommerce_items_in_cart</code>), Nginx dynamically bypasses the cache so customer carts, checkouts, and accounts update seamlessly.
            </p>
        </div>

        <div style="margin-bottom: 24px;">
            <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">4. Instant Event Invalidation</h4>
            <p style="color: var(--ces-text-muted); margin: 0 0 10px 0;">
                When you publish/update a post, edit WooCommerce stock, save an Elementor page, or modify a navigation menu, this plugin automatically notifies Cloud E Panel in the background. The server immediately invalidates the corresponding cached files without causing server load.
            </p>
        </div>

        <div>
            <h4 style="font-size: 15px; margin: 0 0 8px 0; color: #0284C7;">5. Development Mode</h4>
            <p style="color: var(--ces-text-muted); margin: 0;">
                When designing your website or tweaking CSS/JS stylesheets, enable <strong>Development Mode</strong> from the Dashboard tab. FastCGI caching will be temporarily bypassed for 3 hours, allowing you to see changes immediately. After 3 hours, caching automatically re-engages.
            </p>
        </div>

    </div>
</div>
