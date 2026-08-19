<?php
/**
 * Plugin Name: Cloud E Speed — Intelligent FastCGI & Nginx Cache Accelerator
 * Plugin URI: https://github.com/devceerdhg/cloudespeed-wp-plugin
 * Description: Real-time automated cache invalidation engine for Cloud E Panel. Features Native Zero-Configuration web server auto-discovery, clean domain HTTPS URL resolution (standard port 443, no raw IPs/ports), interactive tabbed Light Theme dashboard, Nginx FastCGI microcache controller, targeted URL invalidation, and WooCommerce turbo sync.
 * Version: 2.5.0
 * Author: Cloud E Tech
 * Author URI: https://cloudetech.org/
 * License: GPLv2 or later
 * Text Domain: cloudespeed
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin Constants
define('CLOUDESPEED_VERSION', '2.5.0');
define('CLOUDESPEED_PLUGIN_FILE', __FILE__);
define('CLOUDESPEED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CLOUDESPEED_PLUGIN_URL', plugin_dir_url(__FILE__));

// Require Core OOP Architecture Files
require_once CLOUDESPEED_PLUGIN_DIR . 'includes/class-cloudespeed-discovery.php';
require_once CLOUDESPEED_PLUGIN_DIR . 'includes/class-cloudespeed-api.php';
require_once CLOUDESPEED_PLUGIN_DIR . 'includes/class-cloudespeed-purger.php';
require_once CLOUDESPEED_PLUGIN_DIR . 'includes/class-cloudespeed-admin.php';
require_once CLOUDESPEED_PLUGIN_DIR . 'includes/class-cloudespeed.php';

/**
 * Initialize Cloud E Speed Plugin Singleton
 *
 * @return CloudESpeed
 */
function cloudespeed() {
    return CloudESpeed::get_instance();
}

// Fire up the plugin
cloudespeed();
