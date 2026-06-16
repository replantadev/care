<?php
/**
 * Task: Peak Scheduler (addon eCommerce)
 *
 * Analiza los pedidos de las ultimas 4 semanas para identificar la ventana
 * de 2 horas con menor trafico fuera del horario pico, y reprograma las
 * actualizaciones en ese horario para minimizar impacto en ventas.
 *
 * Se ejecuta diariamente. La ventana elegida se almacena en rpcare_peak_window.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Peak_Scheduler {

    const OPTION_WINDOW  = 'rpcare_peak_window';
    const LOOKBACK_DAYS  = 28;
    const WINDOW_HOURS   = 2;
    const DEFAULT_HOUR   = 2;   // 2am fallback si no hay datos suficientes

    public static function run(array $args = []): array {
        if (!class_exists('WooCommerce')) {
            return ['skipped' => true, 'reason' => 'WooCommerce not active'];
        }

        $config     = RP_Care_Addon_Manager::get()->get_config('ecommerce');
        $peak_start = (int) ($config['peak_hours_start'] ?? 9);
        $peak_end   = (int) ($config['peak_hours_end']   ?? 22);

        $distribution = self::getOrderDistribution();
        $window       = self::findLowestWindow($distribution, $peak_start, $peak_end);

        update_option(self::OPTION_WINDOW, $window);
        self::rescheduleUpdates($window);

        RP_Care_Utils::log(
            'peak_scheduler',
            'info',
            sprintf('Ventana de bajo trafico: %02d:00-%02d:00 (%d pedidos)', $window['hour'], $window['hour'] + self::WINDOW_HOURS, $window['order_count'] ?? 0),
            $window
        );

        return ['window' => $window, 'distribution' => $distribution];
    }

    // -------------------------------------------------------------------------
    // Analisis de trafico
    // -------------------------------------------------------------------------

    /**
     * Construye un array de 24 elementos [hora => num_pedidos] para los
     * ultimos LOOKBACK_DAYS dias.
     *
     * @return int[]
     */
    private static function getOrderDistribution(): array {
        global $wpdb;

        $since = gmdate('Y-m-d H:i:s', strtotime('-' . self::LOOKBACK_DAYS . ' days'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT HOUR(post_date) AS h, COUNT(*) AS cnt
                 FROM {$wpdb->posts}
                 WHERE post_type   = 'shop_order'
                   AND post_status IN ('wc-completed','wc-processing','wc-pending')
                   AND post_date   >= %s
                 GROUP BY h",
                $since
            ),
            ARRAY_A
        );

        $dist = array_fill(0, 24, 0);
        foreach ((array) $rows as $row) {
            $dist[(int) $row['h']] = (int) $row['cnt'];
        }

        return $dist;
    }

    /**
     * Encuentra la ventana de WINDOW_HOURS horas consecutivas con menos
     * pedidos, evitando el horario pico [peak_start, peak_end).
     *
     * @param int[] $dist        Array de 24 elementos (hora => pedidos)
     * @param int   $peak_start  Primera hora del horario pico (inclusivo)
     * @param int   $peak_end    Primera hora fuera del horario pico (exclusivo)
     */
    private static function findLowestWindow(array $dist, int $peak_start, int $peak_end): array {
        $best_hour  = self::DEFAULT_HOUR;
        $best_score = PHP_INT_MAX;

        for ($h = 0; $h < 24; $h++) {
            if ($h >= $peak_start && $h < $peak_end) {
                continue;
            }
            $score = self::windowScore($dist, $h);
            if ($score < $best_score) {
                $best_score = $score;
                $best_hour  = $h;
            }
        }

        // Si todo el dia es "pico" (configuracion anormal), usar minimo absoluto
        if ($best_score === PHP_INT_MAX) {
            $best_hour  = self::absoluteMinHour($dist);
            $best_score = self::windowScore($dist, $best_hour);
        }

        return [
            'hour'        => $best_hour,
            'order_count' => $best_score,
            'computed_at' => current_time('mysql'),
        ];
    }

    /** Suma de pedidos en la ventana de WINDOW_HOURS horas a partir de $start. */
    private static function windowScore(array $dist, int $start): int {
        $score = 0;
        for ($i = 0; $i < self::WINDOW_HOURS; $i++) {
            $score += $dist[($start + $i) % 24];
        }
        return $score;
    }

    /** Hora con menor windowScore en todo el dia (fallback). */
    private static function absoluteMinHour(array $dist): int {
        $min_h = self::DEFAULT_HOUR;
        $min_v = PHP_INT_MAX;
        for ($h = 0; $h < 24; $h++) {
            $score = self::windowScore($dist, $h);
            if ($score < $min_v) {
                $min_v = $score;
                $min_h = $h;
            }
        }
        return $min_h;
    }

    // -------------------------------------------------------------------------
    // Reprogramacion
    // -------------------------------------------------------------------------

    /**
     * Reprograma rpcare_task_updates para que arranque en la proxima
     * ocurrencia de $window['hour']:00, manteniendo el intervalo del plan.
     */
    private static function rescheduleUpdates(array $window): void {
        if (!function_exists('as_next_scheduled_action')) {
            return;
        }

        $target_hour = (int) $window['hour'];
        $now         = current_time('timestamp');
        $today_run   = mktime($target_hour, 0, 0, (int) date('n', $now), (int) date('j', $now), (int) date('Y', $now));
        $next_run    = ($today_run > $now) ? $today_run : strtotime('+1 day', $today_run);

        $current_next = as_next_scheduled_action('rpcare_task_updates', [], 'replanta-care');
        if ($current_next && abs($current_next - $next_run) < HOUR_IN_SECONDS) {
            return;  // Ya esta programado en esta ventana
        }

        $opts      = get_option('rpcare_options', []);
        $plan      = $opts['plan'] ?? 'semilla';
        $freq      = RP_Care_Plan::get_update_frequency($plan);
        $schedules = wp_get_schedules();
        $interval  = isset($schedules[$freq]['interval']) ? (int) $schedules[$freq]['interval'] : DAY_IN_SECONDS;

        as_unschedule_all_actions('rpcare_task_updates', [], 'replanta-care');
        as_schedule_recurring_action($next_run, $interval, 'rpcare_task_updates', [], 'replanta-care');

        RP_Care_Utils::log(
            'peak_scheduler',
            'success',
            sprintf('Actualizaciones reprogramadas a las %02d:00 (proxima: %s)', $target_hour, date('Y-m-d H:i', $next_run))
        );
    }
}
