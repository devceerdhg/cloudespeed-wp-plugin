=== Cloud E Speed Cache Accelerator ===
Contributors: cloudetech
Tags: cache, cloud e panel, fastcgi, nginx, performance, speed
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automates Nginx FastCGI microcache management and instant invalidation for Cloud E Panel hosted websites.

== Description ==

This official plugin integrates your WordPress and WooCommerce website natively with Cloud E Panel's FastCGI caching engine.

When you edit a post, page, product, or when inventory stock changes in WooCommerce, this plugin will instantly trigger a non-blocking background request to your Cloud E Panel server. The server immediately invalidates the microcache for your website, ensuring visitors always see fresh content with instant response times (< 50ms).

Features:
* Native Zero-Configuration Web Server Auto-Discovery.
* Clean Domain Standard HTTPS (Port 443) communication.
* Targeted URL and Path-specific cache invalidation.
* 3-Hour Development Mode controller (Live Cache Bypass).
* Automated WooCommerce, Post, Page, and Menu sync.
* Dual WordPress Object Cache and transients flushing.
* Extremely lightweight (Zero database overhead on the frontend).

== Installation ==

1. Upload the `cloudespeed-wp-plugin` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. If hosted on Cloud E Panel, the plugin is 100% automatically connected.
4. For standalone remote servers, navigate to Cloud E Speed -> Server Sync to configure your Webhook Purge URL.

== Frequently Asked Questions ==

= Do I need to configure API Keys if hosted on Cloud E Panel? =
No. Cloud E Speed automatically detects the native Cloud E Panel web server environment and connects securely over standard HTTPS.

= Does this clear the WordPress Object Cache? =
Yes, you can enable simultaneous WordPress Object Cache flushing from the Invalidation Rules tab.
