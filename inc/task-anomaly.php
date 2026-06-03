<?php
/**
 * Response-time anomaly detection.
 *
 * Maintains a rolling 30-day baseline of homepage response times.
 * Flags an anomaly when the latest measurement is > 2× the mean or beyond
 * 3 sigmas (whichever is stricter), provided we have ≥10 samples.
 *
 * Data stored in option `rpcare_anomaly_history` (array of {t, ms}).
 * Last alert stored in `rpcare_anomaly_last_alert` (timestamp) to throttle.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Anomaly {

    const OPT_HISTORY = 'rpcare_anomaly_history';
    const OPT_LAST_ALERT = 'rpcare_anomaly_last_alert';
    const ALERT_COOLDOWN = 6 * HOUR_IN_SECONDS;
    const HISTORY_DAYS = 30;
    const MIN_SAMPLES = 10;

    public static function run($args = []) {
        if (!RP_Care_Plan::can_access_feature('anomaly_detection')) {
            return ['skipped' => 'plan_excluded'];
        }

        $sample = self::measure();
        if (is_wp_error($sample)) {
            return ['error' => $sample->get_error_message()];
        }

        $history = self::load_history();
        $history[] = ['t' => time(), 'ms' => $sample];
        $history = self::trim_history($history);
        update_option(self::OPT_HISTORY, $history, false);

        if (count($history) < self::MIN_SAMPLES) {
            return ['sample_ms' => $sample, 'baseline' => 'building'];
        }

        $stats = self::stats($history);
        $z = $stats['stddev'] > 0 ? abs($sample - $stats['mean']) / $stats['stddev'] : 0;
        $ratio = $stats['mean'] > 0 ? $sample / $stats['mean'] : 1;
        $is_anomaly = $z > 3 && $ratio > 2;

        $result = [
            'sample_ms' => $sample,
            'mean_ms'   => round($stats['mean']),
            'stddev_ms' => round($stats['stddev']),
            'z_score'   => round($z, 2),
            'ratio'     => round($ratio, 2),
            'is_anomaly'=> $is_anomaly,
            'samples'   => count($history),
        ];

        if ($is_anomaly && self::can_alert()) {
            self::emit_alert($result);
            update_option(self::OPT_LAST_ALERT, time(), false);
        }

        if (class_exists('RP_Care_Utils')) {
            RP_Care_Utils::log('anomaly_check', $is_anomaly ? 'warning' : 'success', 'Response baseline check', $result);
        }

        return $result;
    }

    private static function measure() {
        $url = home_url('/?rpcare_anomaly=1');
        $start = microtime(true);
        $response = wp_remote_get($url, [
            'timeout'    => 30,
            'sslverify'  => false,
            'user-agent' => 'Replanta-Care/Anomaly',
        ]);
        $elapsed = (microtime(true) - $start) * 1000;
        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 500) {
            return new WP_Error('http_' . $code, 'Origin returned ' . $code);
        }
        return (int) $elapsed;
    }

    private static function load_history() {
        $h = get_option(self::OPT_HISTORY, []);
        return is_array($h) ? $h : [];
    }

    private static function trim_history($history) {
        $cutoff = time() - self::HISTORY_DAYS * DAY_IN_SECONDS;
        return array_values(array_filter($history, fn($e) => isset($e['t']) && $e['t'] >= $cutoff));
    }

    private static function stats($history) {
        $values = array_column($history, 'ms');
        $n = count($values);
        $mean = array_sum($values) / $n;
        $variance = 0;
        foreach ($values as $v) {
            $variance += pow($v - $mean, 2);
        }
        $variance /= $n;
        return ['mean' => $mean, 'stddev' => sqrt($variance)];
    }

    private static function can_alert() {
        $last = (int) get_option(self::OPT_LAST_ALERT, 0);
        return (time() - $last) > self::ALERT_COOLDOWN;
    }

    private static function emit_alert($result) {
        do_action('rpcare_anomaly_detected', $result);

        $email = get_option('rpcare_options', [])['notification_email'] ?? get_option('admin_email');
        if (!$email) return;

        $subject = '[Replanta Care] Pico de latencia detectado en ' . home_url('/');
        $body = sprintf(
            "Se ha detectado una anomalía en el tiempo de respuesta del sitio.\n\n" .
            "Sitio: %s\n" .
            "Medición actual: %d ms\n" .
            "Media 30 días: %d ms\n" .
            "Ratio: %.2fx\n" .
            "Z-score: %.2f\n" .
            "Muestras: %d\n\n" .
            "Revisión recomendada del estado del servidor.",
            home_url('/'),
            $result['sample_ms'],
            $result['mean_ms'],
            $result['ratio'],
            $result['z_score'],
            $result['samples']
        );
        wp_mail($email, $subject, $body);
    }
}
