<?php
/**
 * Cloud E Speed — Native Auto-Discovery Engine
 *
 * Handles Native Zero Configuration by discovering the Cloud E Panel
 * FastCGI environment and resolving standard HTTPS clean endpoints without raw IPs or ports.
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloudESpeed_Discovery {

    /**
     * Resolve server connection details dynamically.
     *
     * @return array
     */
    public static function get_connection_info() {
        $server_key = '';
        if (!empty($_SERVER['CLOUDESPEED_API_KEY'])) {
            $server_key = $_SERVER['CLOUDESPEED_API_KEY'];
        } elseif (!empty($_ENV['CLOUDESPEED_API_KEY'])) {
            $server_key = $_ENV['CLOUDESPEED_API_KEY'];
        } elseif (getenv('CLOUDESPEED_API_KEY')) {
            $server_key = getenv('CLOUDESPEED_API_KEY');
        }

        $server_endpoint = '';
        if (!empty($_SERVER['CLOUDESPEED_ENDPOINT'])) {
            $server_endpoint = $_SERVER['CLOUDESPEED_ENDPOINT'];
        } elseif (!empty($_ENV['CLOUDESPEED_ENDPOINT'])) {
            $server_endpoint = $_ENV['CLOUDESPEED_ENDPOINT'];
        } elseif (getenv('CLOUDESPEED_ENDPOINT')) {
            $server_endpoint = getenv('CLOUDESPEED_ENDPOINT');
        }

        $is_native = !empty($_SERVER['CLOUDESPEED_ACTIVE']) || !empty($_SERVER['CLOUDESPEED_SERVER']) || !empty($server_key);

        // Auto-detect clean master panel domain without raw IP or custom ports
        $clean_domain = 'server.cloudetech.org';
        $default_clean_url = 'https://' . $clean_domain . '/api/ext/v1/cache/purge';

        $api_key = $server_key;
        if (empty($api_key)) {
            $api_key = get_option('cloudespeed_api_key', '');
        }

        $api_url = $server_endpoint;
        if (empty($api_url)) {
            $api_url = get_option('cloudespeed_api_url', '');
        }

        // Clean any leftover raw IPs or port numbers for pure domain resolution
        if (empty($api_url) || strpos($api_url, '127.0.0.1') !== false || strpos($api_url, ':8443') !== false || strpos($api_url, ':2083') !== false) {
            $api_url = $default_clean_url;
        }

        return [
            'is_native'    => $is_native,
            'api_key'      => $api_key,
            'api_url'      => $api_url,
            'clean_domain' => $clean_domain,
            'mode'         => $is_native ? 'native' : (!empty($api_key) ? 'manual' : 'unconfigured'),
        ];
    }

    /**
     * Check if the plugin has valid credentials configured or auto-discovered.
     *
     * @return bool
     */
    public static function is_configured() {
        $info = self::get_connection_info();
        return !empty($info['api_key']);
    }
}
