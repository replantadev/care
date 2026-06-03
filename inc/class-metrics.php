<?php
/**
 * Replanta Care — Metrics client (proxy to Hub).
 *
 * Pulls GA4 / Search Console / Cloudflare summaries from the Hub
 * (which holds all OAuth/API credentials) and caches locally.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Metrics {

    const CACHE_TTL = 30 * MINUTE_IN_SECONDS;

    public static function ga4($days = 30) {
        return self::fetch('ga4', $days);
    }

    public static function search_console($days = 30) {
        return self::fetch('sc', $days);
    }

    public static function cloudflare($days = 7) {
        return self::fetch('cloudflare', $days);
    }

    public static function all($days = 30) {
        return self::fetch('all', $days);
    }

    private static function fetch($type, $days) {
        $days = max(1, (int) $days);
        $cache_key = 'rpcare_metrics_' . $type . '_' . $days;
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $hub_url    = RP_Care_Plan::get_hub_url();
        $options    = get_option('rpcare_options', []);
        $site_token = $options['site_token'] ?? get_option('rpcare_site_token', '');

        if (empty($hub_url) || empty($site_token)) {
            return new WP_Error('not_configured', 'Hub URL o site_token no configurados');
        }

        $url = trailingslashit($hub_url) . 'wp-json/replanta-hub/v1/metrics/' . $type . '/' . rawurlencode($site_token);
        $url = add_query_arg(['days' => $days], $url);

        $r = wp_remote_get($url, [
            'timeout' => 25,
            'headers' => [
                'X-RPHUB-Token' => $site_token,
                'Accept'        => 'application/json',
            ],
        ]);

        if (is_wp_error($r)) return $r;
        $code = wp_remote_retrieve_response_code($r);
        $body = json_decode(wp_remote_retrieve_body($r), true);

        if ($code >= 400) {
            return new WP_Error('hub_' . $code, $body['message'] ?? 'Hub HTTP ' . $code);
        }
        if (!is_array($body)) {
            return new WP_Error('bad_response', 'Respuesta inválida del Hub');
        }

        set_transient($cache_key, $body, self::CACHE_TTL);
        return $body;
    }

    public static function flush_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rpcare_metrics_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_rpcare_metrics_%'");
    }
}
