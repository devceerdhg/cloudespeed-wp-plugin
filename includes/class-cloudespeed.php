<?php
/**
 * Cloud E Speed — Main Plugin Orchestrator
 *
 * Singleton class that bootstraps all components (Discovery, Purger, Admin).
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloudESpeed {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Initialize Admin UI & AJAX
        CloudESpeed_Admin::init();

        // Initialize Automated Invalidation Hooks if configured or natively auto-discovered
        if (CloudESpeed_Discovery::is_configured()) {
            CloudESpeed_Purger::register_hooks();
        }
    }
}
