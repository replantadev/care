<?php
/**
 * Core Web Vitals & Performance Measurement
 *
 * Uses Google PageSpeed Insights API (no key required for low volume).
 * Stores last result in option `rpcare_cwv_last`.
 * Hooked into quarterly review (Semilla+) and monthly review (Raíz+).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_CWV {

    const OPTION_LAST = 'rpcare_cwv_last';
    const OPTION_HISTORY = 'rpcare_cwv_history';
    const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    public static function run($args = []) {
        if (!RP_Care_Plan::can_access_feature('cwv_reports')) {
            return ['skipped' => 'plan_excluded'];
        }

        $url = home_url('/');
        $strategies = ['mobile', 'desktop'];
        $results = [];

        foreach ($strategies as $strategy) {
            $result = self::query_psi($url, $strategy);
            if (!is_wp_error($result)) {
                $results[$strategy] = $result;
            } else {
                $results[$strategy] = ['error' => $result->get_error_message()];
            }
        }

        $payload = [
            'url'        => $url,
            'measured_at'=> current_time('mysql'),
            'mobile'     => $results['mobile'] ?? null,
            'desktop'    => $results['desktop'] ?? null,
        ];

        update_option(self::OPTION_LAST, $payload, false);

        $history = get_option(self::OPTION_HISTORY, []);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'date'    => current_time('mysql'),
            'mobile'  => isset($results['mobile']['scores']) ? $results['mobile']['scores'] : null,
            'desktop' => isset($results['desktop']['scores']) ? $results['desktop']['scores'] : null,
        ];
        if (count($history) > 24) {
            $history = array_slice($history, -24);
        }
        update_option(self::OPTION_HISTORY, $history, false);

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('cwv_measurement', 'success', 'CWV measured', $payload);
        }

        return $payload;
    }

    private static function query_psi($url, $strategy) {
        $api_url = add_query_arg([
            'url'      => $url,
            'strategy' => $strategy,
            'category' => 'performance',
        ], self::API_URL);

        $key = get_option('rpcare_psi_api_key', '');
        if (!empty($key)) {
            $api_url = add_query_arg(['key' => $key], $api_url);
        }

        $response = wp_remote_get($api_url, ['timeout' => 60]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return new WP_Error('psi_http_' . $code, 'PageSpeed Insights returned ' . $code);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['lighthouseResult'])) {
            return new WP_Error('psi_bad_payload', 'Unexpected PSI response');
        }

        $lh = $body['lighthouseResult'];
        $audits = $lh['audits'] ?? [];
        $perf_score = isset($lh['categories']['performance']['score'])
            ? round($lh['categories']['performance']['score'] * 100)
            : null;

        $extract = function($key) use ($audits) {
            if (!isset($audits[$key])) return null;
            return [
                'value'    => $audits[$key]['numericValue'] ?? null,
                'display'  => $audits[$key]['displayValue'] ?? null,
                'score'    => $audits[$key]['score'] ?? null,
            ];
        };

        return [
            'fetched_at'   => current_time('mysql'),
            'scores'       => [
                'performance' => $perf_score,
            ],
            'metrics' => [
                'lcp'  => $extract('largest-contentful-paint'),
                'fcp'  => $extract('first-contentful-paint'),
                'cls'  => $extract('cumulative-layout-shift'),
                'tbt'  => $extract('total-blocking-time'),
                'ttfb' => $extract('server-response-time'),
                'si'   => $extract('speed-index'),
            ],
        ];
    }

    public static function get_last() {
        return get_option(self::OPTION_LAST, null);
    }

    public static function get_history() {
        return get_option(self::OPTION_HISTORY, []);
    }

    public static function format_summary($payload = null) {
        $payload = $payload ?: self::get_last();
        if (!$payload) return '';

        $lines = [];
        foreach (['mobile', 'desktop'] as $strategy) {
            if (empty($payload[$strategy]['metrics'])) continue;
            $m = $payload[$strategy]['metrics'];
            $score = $payload[$strategy]['scores']['performance'] ?? '–';
            $lines[] = sprintf(
                '%s — Performance: %s | LCP: %s | CLS: %s | TTFB: %s',
                ucfirst($strategy),
                $score,
                $m['lcp']['display'] ?? '–',
                $m['cls']['display'] ?? '–',
                $m['ttfb']['display'] ?? '–'
            );
        }
        return implode("\n", $lines);
    }
}
