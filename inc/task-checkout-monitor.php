<?php
/**
 * Task: Checkout Monitor (addon eCommerce)
 *
 * Verifica cada 15 minutos que el flujo de compra de WooCommerce funciona:
 *   - Paginas de tienda, carrito y checkout accesibles (HTTP 200/30x)
 *   - WC REST API responde en /wp-json/wc/v3/ (200 o 401 = OK)
 *   - Al menos una pasarela de pago activa configurada
 *
 * Tras CONSECUTIVE_FAIL fallos seguidos notifica al Hub de forma inmediata.
 * Historial de MAX_HISTORY entradas (96 = 24h a 15min).
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Checkout_Monitor {

    const OPTION_STATUS      = 'rpcare_checkout_status';
    const OPTION_HISTORY     = 'rpcare_checkout_history';
    const OPTION_CONSEC_FAIL = 'rpcare_checkout_consec_fail';
    const MAX_HISTORY        = 96;
    const CONSECUTIVE_FAIL   = 2;
    const REQUEST_TIMEOUT    = 10;

    public static function run(array $args = []): array {
        if (!class_exists('WooCommerce')) {
            return ['skipped' => true, 'reason' => 'WooCommerce not active'];
        }

        $checks = self::runChecks();
        $passed = self::countPassed($checks);
        $total  = count($checks);
        $ok     = ($passed === $total);

        $entry = [
            'ts'     => current_time('mysql'),
            'ok'     => $ok,
            'passed' => $passed,
            'total'  => $total,
            'checks' => $checks,
        ];

        update_option(self::OPTION_STATUS, $entry);
        self::appendHistory($entry);

        if ($ok) {
            update_option(self::OPTION_CONSEC_FAIL, 0);
        } else {
            self::handleFailure($entry);
        }

        RP_Care_Utils::log(
            'checkout_monitor',
            $ok ? 'success' : 'warning',
            sprintf('%d/%d checks pasados', $passed, $total),
            $checks
        );

        return $entry;
    }

    // -------------------------------------------------------------------------
    // Checks individuales
    // -------------------------------------------------------------------------

    private static function runChecks(): array {
        $checks = [];

        $checks['shop']     = self::checkPage(wc_get_page_permalink('shop'), 'shop');
        $checks['cart']     = self::checkPage(wc_get_page_permalink('cart'), 'cart');
        $checks['checkout'] = self::checkPage(wc_get_page_permalink('checkout'), 'checkout');
        $checks['wc_rest']  = self::checkWcRestApi();

        $gateway_result             = self::checkPaymentGateways();
        $checks['payment_gateways'] = $gateway_result;

        return $checks;
    }

    private static function checkPage(string $url, string $label): array {
        if (!$url) {
            return ['label' => $label, 'ok' => false, 'reason' => 'Pagina no configurada en WC'];
        }

        $response = wp_remote_get($url, [
            'timeout'     => self::REQUEST_TIMEOUT,
            'user-agent'  => 'ReplantaCare/1.0 CheckoutMonitor',
            'sslverify'   => false,
            'redirection' => 3,
        ]);

        if (is_wp_error($response)) {
            return ['label' => $label, 'ok' => false, 'reason' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $ok   = ($code >= 200 && $code < 400);

        return ['label' => $label, 'ok' => $ok, 'http' => $code];
    }

    private static function checkWcRestApi(): array {
        $url      = home_url('/wp-json/wc/v3/');
        $response = wp_remote_get($url, [
            'timeout'    => 8,
            'user-agent' => 'ReplantaCare/1.0 CheckoutMonitor',
            'sslverify'  => false,
        ]);

        if (is_wp_error($response)) {
            return ['label' => 'wc_rest', 'ok' => false, 'reason' => $response->get_error_message()];
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        // 401 = WC REST responds but requires auth — means the endpoint IS running
        $ok   = in_array($code, [200, 401, 403], true);

        return ['label' => 'wc_rest', 'ok' => $ok, 'http' => $code];
    }

    private static function checkPaymentGateways(): array {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return ['label' => 'gateways', 'ok' => true, 'note' => 'WC no inicializado aun'];
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        $enabled  = [];

        foreach ($gateways as $id => $gw) {
            if ($gw->enabled === 'yes') {
                $enabled[] = $id;
            }
        }

        if (empty($enabled)) {
            return ['label' => 'gateways', 'ok' => false, 'reason' => 'Sin pasarelas de pago activas'];
        }

        return ['label' => 'gateways', 'ok' => true, 'active' => $enabled];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function countPassed(array $checks): int {
        $passed = 0;
        foreach ($checks as $check) {
            if (!empty($check['ok'])) {
                $passed++;
            }
        }
        return $passed;
    }

    private static function handleFailure(array $entry): void {
        $consec = (int) get_option(self::OPTION_CONSEC_FAIL, 0) + 1;
        update_option(self::OPTION_CONSEC_FAIL, $consec);

        if ($consec < self::CONSECUTIVE_FAIL) {
            return;
        }

        RP_Care_Addon_Manager::notify_hub('checkout_failure', [
            'consecutive_failures' => $consec,
            'checks'               => $entry['checks'],
            'ts'                   => $entry['ts'],
        ]);

        RP_Care_Utils::log(
            'checkout_monitor',
            'error',
            "Fallo de checkout #{$consec} — Hub notificado"
        );
    }

    private static function appendHistory(array $entry): void {
        $history   = (array) get_option(self::OPTION_HISTORY, []);
        $history[] = [
            'ts'     => $entry['ts'],
            'ok'     => $entry['ok'],
            'passed' => $entry['passed'],
            'total'  => $entry['total'],
        ];

        if (count($history) > self::MAX_HISTORY) {
            $history = array_slice($history, -self::MAX_HISTORY);
        }

        update_option(self::OPTION_HISTORY, $history);
    }
}
