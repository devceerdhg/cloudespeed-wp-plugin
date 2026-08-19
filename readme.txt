=== Cloud E Speed Cache Purger ===
Contributors: cloudetech
Tags: cache, cloud e panel, fastcgi, nginx, performance
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automates Nginx FastCGI microcache purging for Cloud E Panel hosted websites.

== Description ==

This official plugin integrates your WordPress/WooCommerce site with Cloud E Panel's Intelligent FastCGI cache system.

When you edit a post, page, product, or when inventory stock changes in WooCommerce, this plugin will instantly trigger a non-blocking background request to your Cloud E Panel server. The server will then immediately flush the Nginx microcache for your website, ensuring visitors always see the latest content without manual intervention.

Features:
* Instantly clears FastCGI cache on content updates.
* Adds a "⚡ Purge Nginx Cache" button to your WordPress Admin Bar.
* Extremely lightweight (Zero database queries on the frontend).
* Non-blocking HTTP POST requests to prevent any backend slowdowns.

== Installation ==

1. Upload the `cloudespeed-wp-plugin` directory to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to Settings -> Cloud E Speed (Cache).
4. Enter the `Panel Purge API URL` and `X-CloudESpeed-Key` provided in your Cloud E Panel dashboard.
5. Save changes.

== Frequently Asked Questions ==

= Where do I get my API Key? =
Log in to your Cloud E Panel, navigate to "Cloud E Speed", select your domain, and click on "API & Plugin Guide". Your unique API key and Webhook URL will be displayed there.

= Does this clear the WordPress Object Cache? =
No, this plugin specifically sends a webhook to the Cloud E Panel backend to wipe the **Nginx FastCGI Cache**. Object cache can be cleared from the Cloud E Panel "Terminal & Tools" page.
