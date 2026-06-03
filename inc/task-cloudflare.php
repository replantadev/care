<?php
/**
 * Cloudflare integration (Raíz+).
 *
 * Uses Cloudflare API token stored in option `rpcare_cloudflare_token` (Zone scope: Zone Read,
 * Cache Purge, Page Rules Edit, Zone Settings Edit). Zone identifier auto-discovered from
 * site host the first time configure() runs and cached in `rpcare_cloudflare_zone_id`.
 *
 * Exposes:
 *   - RP_Care_Task_Cloudflare::configure() — apply WP-friendly settings + WP/Woo bypass rules.
 *   - RP_Care_Task_Cloudflare::purge_cache() — full cache purge.
 *   - RP_Care_Task_Cloudflare::status() — return zone summary for the dashboard.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Cloudflare {

    const API_BASE = 'https://api.cloudflare.com/client/v4';
    const OPT_TOKEN = 'rpcare_cloudflare_token';
    const OPT_ZONE  = 'rpcare_cloudflare_zone_id';
    const OPT_LAST  = 'rpcare_cloudflare_last_run';

    public static function configure($args = []) {
        if (!RP_Care_Plan::can_access_feature('cdn_config')) {
            return ['skipped' => 'plan_excluded'];
        }

        $token = trim(get_option(self::OPT_TOKEN, ''));
        if (empty($token)) {
            return ['error' => 'no_token', 'message' => 'Cloudflare API token no configurado'];
        }

        $zone_id = self::ensure_zone_id($token);
        if (is_wp_error($zone_id)) {
            return ['error' => $zone_id->get_error_code(), 'message' => $zone_id->get_error_message()];
        }

        $applied = [];

        // 1) Recommended settings
        $settings = [
            'always_use_https'         => 'on',
            'automatic_https_rewrites' => 'on',
            'brotli'                   => 'on',
            'min_tls_version'          => '1.2',
            'opportunistic_encryption' => 'on',
            'security_level'           => 'medium',
            'browser_check'            => 'on',
            'ssl'                      => 'flexible',
        ];
        foreach ($settings as $key => $value) {
            $result = self::api_patch("zones/{$zone_id}/settings/{$key}", ['value' => $value], $token);
            $applied['settings'][$key] = !is_wp_error($result);
        }

        // 2) WP/Woo bypass page rules — keep dynamic admin/login/woo paths uncached
        $home = home_url('/');
        $host = wp_parse_url($home, PHP_URL_HOST);
        $bypass_targets = [
            "*{$host}/wp-admin*",
            "*{$host}/wp-login.php*",
            "*{$host}/cart*",
            "*{$host}/checkout*",
            "*{$host}/my-account*",
            "*{$host}/?wc-ajax=*",
        ];

        $existing_rules = self::api_get("zones/{$zone_id}/pagerules", $token);
        $existing_targets = [];
        if (!is_wp_error($existing_rules) && isset($existing_rules['result'])) {
            foreach ($existing_rules['result'] as $rule) {
                foreach ($rule['targets'] ?? [] as $t) {
                    $existing_targets[] = $t['constraint']['value'] ?? '';
                }
            }
        }

        $rules_created = 0;
        foreach ($bypass_targets as $url_pattern) {
            if (in_array($url_pattern, $existing_targets, true)) continue;
            $payload = [
                'targets' => [[
                    'target' => 'url',
                    'constraint' => ['operator' => 'matches', 'value' => $url_pattern],
                ]],
                'actions' => [
                    ['id' => 'cache_level', 'value' => 'bypass'],
                ],
                'status'   => 'active',
                'priority' => 1,
            ];
            $r = self::api_post("zones/{$zone_id}/pagerules", $payload, $token);
            if (!is_wp_error($r)) $rules_created++;
        }
        $applied['bypass_rules_created'] = $rules_created;

        // 3) Security headers — add transform rule if not present (best effort)
        $applied['headers'] = self::apply_security_headers($zone_id, $token);

        update_option(self::OPT_LAST, [
            'when'    => current_time('mysql'),
            'applied' => $applied,
        ], false);

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('cloudflare_configure', 'success', 'Cloudflare configurado', $applied);
        }

        return $applied;
    }

    public static function purge_cache() {
        $token = trim(get_option(self::OPT_TOKEN, ''));
        $zone  = trim(get_option(self::OPT_ZONE, ''));
        if (empty($token) || empty($zone)) return ['error' => 'not_configured'];
        return self::api_post("zones/{$zone}/purge_cache", ['purge_everything' => true], $token);
    }

    public static function status() {
        $token = trim(get_option(self::OPT_TOKEN, ''));
        if (empty($token)) return ['configured' => false];
        $zone = get_option(self::OPT_ZONE, '');
        return [
            'configured' => !empty($zone),
            'zone_id'    => $zone,
            'last_run'   => get_option(self::OPT_LAST, null),
        ];
    }

    private static function ensure_zone_id($token) {
        $cached = get_option(self::OPT_ZONE, '');
        if (!empty($cached)) return $cached;

        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (!$host) return new WP_Error('no_host', 'No se pudo determinar el host');

        // Try host, then root domain
        $candidates = [$host];
        $parts = explode('.', $host);
        if (count($parts) > 2) {
            $candidates[] = implode('.', array_slice($parts, -2));
        }

        foreach ($candidates as $name) {
            $result = self::api_get('zones?name=' . rawurlencode($name), $token);
            if (is_wp_error($result)) continue;
            if (!empty($result['result'][0]['id'])) {
                $zone_id = $result['result'][0]['id'];
                update_option(self::OPT_ZONE, $zone_id, false);
                return $zone_id;
            }
        }

        return new WP_Error('zone_not_found', 'Zona Cloudflare no encontrada para ' . $host);
    }

    private static function apply_security_headers($zone_id, $token) {
        // Cloudflare Rulesets API — http_response_headers_transform phase
        $list = self::api_get("zones/{$zone_id}/rulesets/phases/http_response_headers_transform/entrypoint", $token);
        $existing = (!is_wp_error($list) && isset($list['result']['rules'])) ? $list['result']['rules'] : [];

        $desired = [
            ['name' => 'X-Content-Type-Options',   'value' => 'nosniff'],
            ['name' => 'X-Frame-Options',          'value' => 'SAMEORIGIN'],
            ['name' => 'Referrer-Policy',          'value' => 'strict-origin-when-cross-origin'],
            ['name' => 'Permissions-Policy',       'value' => 'geolocation=(), microphone=(), camera=()'],
        ];

        $existing_names = array_map(function($r){
            return $r['action_parameters']['headers'][0]['name'] ?? '';
        }, $existing);

        $added = 0;
        foreach ($desired as $h) {
            if (in_array($h['name'], $existing_names, true)) continue;
            $rule = [
                'description' => 'Replanta Care — ' . $h['name'],
                'expression'  => 'true',
                'action'      => 'rewrite',
                'action_parameters' => [
                    'headers' => [
                        $h['name'] => [
                            'operation' => 'set',
                            'value'     => $h['value'],
                        ],
                    ],
                ],
                'enabled' => true,
            ];
            $r = self::api_post("zones/{$zone_id}/rulesets/phases/http_response_headers_transform/entrypoint/rules", $rule, $token);
            if (!is_wp_error($r)) $added++;
        }
        return ['added' => $added];
    }

    private static function api_get($path, $token) {
        return self::api_request('GET', $path, null, $token);
    }
    private static function api_post($path, $body, $token) {
        return self::api_request('POST', $path, $body, $token);
    }
    private static function api_patch($path, $body, $token) {
        return self::api_request('PATCH', $path, $body, $token);
    }
    private static function api_request($method, $path, $body, $token) {
        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request(self::API_BASE . '/' . $path, $args);
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $decoded = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 400) {
            $msg = is_array($decoded) && !empty($decoded['errors'][0]['message'])
                ? $decoded['errors'][0]['message']
                : 'Cloudflare HTTP ' . $code;
            return new WP_Error('cf_api_' . $code, $msg);
        }
        return $decoded;
    }
}
