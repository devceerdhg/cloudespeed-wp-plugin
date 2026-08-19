<?php
/**
 * Cloud E Speed — API Client
 *
 * Handles HTTP requests to the Cloud E Panel External Cache API over standard HTTPS.
 *
 * @package CloudESpeed
 */

if (!defined('ABSPATH')) {
    exit;
}

class CloudESpeed_API {

    /**
     * Execute an API call to the caching engine.
     *
     * @param string $endpoint_path e.g. '/purge', '/purge-url', '/dev-mode', '/status'
     * @param string $method        HTTP method ('POST' or 'GET')
     * @param array  $data          Optional JSON payload
     * @return array|WP_Error
     */
    public static function call($endpoint_path, $method = 'POST', $data = []) {
        $info = CloudESpeed_Discovery::get_connection_info();
        $api_key = $info['api_key'];
        $api_url = $info['api_url'];

        if (empty($api_key) || empty($api_url)) {
            return new WP_Error('not_configured', __('API Key and URL are not configured.', 'cloudespeed'));
        }

        $base_url = preg_replace('#/api/ext/v1/cache.*$#', '', $api_url);
        $full_url = rtrim($base_url, '/') . '/api/ext/v1/cache' . $endpoint_path;

        $ssl_verify = get_option('cloudespeed_ssl_verify', '0') === '1';

        $args = [
            'method'    => $method,
            'timeout'   => 8,
            'sslverify' => $ssl_verify,
            'headers'   => [
                'X-CloudESpeed-Key' => $api_key,
                'Content-Type'      => 'application/json',
                'User-Agent'        => 'CloudESpeed-WordPress/' . CLOUDESPEED_VERSION,
            ],
        ];

        if ($method === 'POST' && !empty($data)) {
            $args['body'] = json_encode($data);
        }

        $res = wp_remote_request($full_url, $args);
        if (is_wp_error($res)) {
            // Fallback for native server if public DNS/SSL is resolving locally
            if ($info['is_native']) {
                $loopback_url = 'http://127.0.0.1:8443/api/ext/v1/cache' . $endpoint_path;
                $args['sslverify'] = false;
                $res = wp_remote_request($loopback_url, $args);
                if (is_wp_error($res)) {
                    return $res;
                }
            } else {
                return $res;
            }
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);
        $json = json_decode($body, true);

        if ($code >= 200 && $code < 300) {
            return $json ? $json : ['success' => true, 'raw' => $body];
        }

        $err_msg = isset($json['message']) ? $json['message'] : (isset($json['error']) ? $json['error'] : "HTTP {$code}: {$body}");
        return new WP_Error('api_error', $err_msg, ['status' => $code]);
    }

    /**
     * Purge all FastCGI cache on the server.
     *
     * @return bool|WP_Error
     */
    public static function purge_all() {
        $res = self::call('/purge', 'POST', ['timestamp' => time()]);
        if (is_wp_error($res)) {
            return $res;
        }
        return true;
    }

    /**
     * Invalidate a single URL or slug path.
     *
     * @param string $url
     * @return bool|WP_Error
     */
    public static function purge_url($url) {
        $parsed = parse_url($url, PHP_URL_PATH);
        $path = $parsed ? $parsed : $url;
        $res = self::call('/purge-url', 'POST', ['url' => $path]);
        if (is_wp_error($res)) {
            return $res;
        }
        return true;
    }

    /**
     * Toggle Development Mode (cache bypass).
     *
     * @param bool $enable
     * @param int  $hours
     * @return array|WP_Error
     */
    public static function toggle_dev_mode($enable, $hours = 3) {
        return self::call('/dev-mode', 'POST', [
            'dev_mode'       => (bool) $enable,
            'duration_hours' => (int) $hours,
        ]);
    }

    /**
     * Fetch live cache status & telemetry from server.
     *
     * @return array|WP_Error
     */
    public static function get_status() {
        return self::call('/status', 'GET');
    }
}
