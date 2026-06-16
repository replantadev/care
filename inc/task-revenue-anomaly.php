<?php
/**
 * Task: Revenue Anomaly Detector (addon eCommerce)
 *
 * Compara los ingresos de la ultima ventana de 12 horas con los de la
 * misma ventana hace 7 dias. Si la caida supera el umbral configurado,
 * notifica al Hub y envia un email de alerta al administrador.
 *
 * Se ejecuta dos veces al dia (twicedaily). Mantiene historial de MAX_HISTORY
 * entradas (~30 dias a frecuencia twicedaily).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Revenue_Anomaly {

    const OPTION_LAST_CHECK = 'rpcare_revenue_last_check';
    const OPTION_HISTORY    = 'rpcare_revenue_history';
    const MAX_HISTORY       = 60;
    const DEFAULT_THRESHOLD = 35.0;   // % de caida para disparar alerta
    const WINDOW_HOURS      = 12;
    const BASELINE_DAYS     = 7;
    const MIN_BASELINE      = 10.0;   // ingresos minimos (EUR) para que la comparacion sea significativa

    public static function run(array $args = []): array {
        if (!class_exists('WooCommerce')) {
            return ['skipped' => true, 'reason' => 'WooCommerce not active'];
        }

        $config    = RP_Care_Addon_Manager::get()->get_config('ecommerce');
        $threshold = (float) ($config['revenue_alert_threshold'] ?? self::DEFAULT_THRESHOLD);

        $current  = self::getRevenue(0);
        $baseline = self::getRevenue(self::BASELINE_DAYS);
        $drop_pct = self::computeDrop($current['total'], $baseline['total']);

        // Solo alertar si habia actividad real hace 7 dias (evitar falsos positivos en tiendas nuevas)
        $alert = ($baseline['total'] >= self::MIN_BASELINE && $drop_pct >= $threshold);

        $entry = [
            'ts'        => current_time('mysql'),
            'current'   => $current,
            'baseline'  => $baseline,
            'drop_pct'  => $drop_pct,
            'threshold' => $threshold,
            'alert'     => $alert,
        ];

        update_option(self::OPTION_LAST_CHECK, $entry);
        self::appendHistory($entry);

        if ($alert) {
            self::triggerAlert($entry, $config);
        }

        RP_Care_Utils::log(
            'revenue_anomaly',
            $alert ? 'warning' : 'success',
            sprintf('Revenue: %.2f vs %.2f (hace 7d) — caida %.1f%%', $current['total'], $baseline['total'], $drop_pct),
            $entry
        );

        return $entry;
    }

    // -------------------------------------------------------------------------
    // Consultas
    // -------------------------------------------------------------------------

    /**
     * Agrega ingresos para una ventana de WINDOW_HOURS horas terminando ahora
     * (o hace $days_ago dias).
     *
     * @param int $days_ago 0 = periodo actual, 7 = misma ventana la semana pasada
     */
    private static function getRevenue(int $days_ago): array {
        global $wpdb;

        $offset    = $days_ago * DAY_IN_SECONDS;
        $end       = current_time('timestamp') - $offset;
        $start     = $end - (self::WINDOW_HOURS * HOUR_IN_SECONDS);
        $end_str   = gmdate('Y-m-d H:i:s', $end);
        $start_str = gmdate('Y-m-d H:i:s', $start);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS orders, COALESCE(SUM(pm.meta_value), 0) AS total
                 FROM {$wpdb->posts} p
                 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_order_total'
                 WHERE p.post_type   = 'shop_order'
                   AND p.post_status IN ('wc-completed', 'wc-processing')
                   AND p.post_date   >= %s
                   AND p.post_date   < %s",
                $start_str,
                $end_str
            ),
            ARRAY_A
        );

        return [
            'orders'     => (int)   ($result['orders'] ?? 0),
            'total'      => (float) ($result['total']  ?? 0.0),
            'period_end' => $end_str,
        ];
    }

    private static function computeDrop(float $current, float $baseline): float {
        if ($baseline <= 0.0) {
            return 0.0;
        }
        $drop = (($baseline - $current) / $baseline) * 100.0;
        return round(max(0.0, $drop), 2);
    }

    // -------------------------------------------------------------------------
    // Alerta
    // -------------------------------------------------------------------------

    private static function triggerAlert(array $entry, array $config): void {
        RP_Care_Addon_Manager::notify_hub('revenue_anomaly', $entry);

        $to      = !empty($config['alert_email']) ? $config['alert_email'] : get_option('admin_email');
        $subject = sprintf('[Replanta Care] Alerta de ingresos — caida del %.1f%%', $entry['drop_pct']);
        $body    = self::buildEmailBody($entry);

        wp_mail($to, $subject, $body);

        RP_Care_Utils::log(
            'revenue_anomaly',
            'error',
            sprintf('Caida de ingresos %.1f%% — Hub notificado, email enviado a %s', $entry['drop_pct'], $to)
        );
    }

    private static function buildEmailBody(array $entry): string {
        return sprintf(
            "Alerta de ingresos en %s\n\n"
            . "Periodo actual (12h): %.2f EUR (%d pedidos)\n"
            . "Hace 7 dias (misma ventana): %.2f EUR (%d pedidos)\n"
            . "Caida detectada: %.1f%% (umbral: %.0f%%)\n\n"
            . "Revisa tu tienda en: %s\n\n"
            . "-- Replanta Care",
            home_url(),
            $entry['current']['total'],
            $entry['current']['orders'],
            $entry['baseline']['total'],
            $entry['baseline']['orders'],
            $entry['drop_pct'],
            $entry['threshold'],
            admin_url('admin.php?page=replanta-care-portal')
        );
    }

    // -------------------------------------------------------------------------
    // Historial
    // -------------------------------------------------------------------------

    private static function appendHistory(array $entry): void {
        $history   = (array) get_option(self::OPTION_HISTORY, []);
        $history[] = [
            'ts'     => $entry['ts'],
            'total'  => $entry['current']['total'],
            'orders' => $entry['current']['orders'],
            'drop'   => $entry['drop_pct'],
            'alert'  => $entry['alert'],
        ];

        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        update_option(self::OPTION_HISTORY, $history);
    }
}
