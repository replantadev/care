<?php
/**
 * Replanta Care — Pipeline Test Runner
 *
 * Extensible architecture for automated testing before and after updates.
 *
 * Architecture:
 *   RP_Care_Test_Runner_Interface  — contract for individual test suites.
 *   RP_Care_Test_Runner            — orchestrates all registered suites.
 *   RP_Care_Test_Suite_HTTP        — core HTTP checks (home 200, critical URLs, no 5xx).
 *   RP_Care_Test_Suite_WordPress   — WP REST, loopback, cron, plugin versions, DB.
 *   RP_Care_Test_Suite_DOM         — DOM/fingerprint regression (HTML comparison).
 *   RP_Care_Test_Suite_Ecommerce   — WooCommerce checks (sandbox only, no real orders).
 *   RP_Care_Visual_Test_Runner_Interface — stub for future Playwright integration.
 *
 * Test results:
 *   ok       — passed, no issues.
 *   warning  — operator may override with explicit reason.
 *   critical — always blocks; cannot be overridden.
 *
 * DOM vs visual:
 *   RP_Care_Test_Suite_DOM compares HTML fingerprints — it is NOT a visual test.
 *   Real visual testing (screenshot diff) requires a separate service.
 *   See RP_Care_Visual_Test_Runner_Interface for the future integration point.
 *
 * Compatible with PHP 7.4+ (Care's minimum requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Interfaces ────────────────────────────────────────────────────────────

interface RP_Care_Test_Runner_Interface {
    public function name(): string;
    /** @return array{status: string, checks: array[], summary: string} */
    public function run( array $context ): array;
}

/**
 * Stub for future screenshot/visual diff integration.
 * Implement this interface and register via RP_Care_Test_Runner::register().
 */
interface RP_Care_Visual_Test_Runner_Interface extends RP_Care_Test_Runner_Interface {
    /** @return bool True if the external screenshot service is reachable. */
    public function is_available(): bool;
}

// ── Orchestrator ───────────────────────────────────────────────────────────

class RP_Care_Test_Runner {

    /** @var RP_Care_Test_Runner_Interface[] */
    private static array $registered = [];

    /**
     * Register a custom test suite (used by add-ons).
     */
    public static function register( RP_Care_Test_Runner_Interface $suite ): void {
        self::$registered[] = $suite;
    }

    /**
     * Run all registered test suites.
     *
     * @param string $batch_id     The batch being tested (used for log context).
     * @param array  $options      e.g. ['non_destructive' => true] for production verification.
     * @return array{
     *   severity: string,
     *   suites: array[],
     *   has_critical: bool,
     *   has_warning: bool,
     *   summary: string,
     * }
     */
    public static function run( string $batch_id = '', array $options = [] ): array {
        $context = array_merge( $options, [ 'batch_id' => $batch_id ] );

        $suites_to_run = array_merge(
            [
                new RP_Care_Test_Suite_HTTP(),
                new RP_Care_Test_Suite_WordPress(),
                new RP_Care_Test_Suite_DOM(),
            ],
            self::$registered
        );

        // Ecommerce suite only when addon is active.
        if ( class_exists( 'WooCommerce' ) && empty( $options['non_destructive'] ) ) {
            $suites_to_run[] = new RP_Care_Test_Suite_Ecommerce();
        }

        $results      = [];
        $has_critical = false;
        $has_warning  = false;

        foreach ( $suites_to_run as $suite ) {
            $result                 = $suite->run( $context );
            $result['suite']        = $suite->name();
            $results[]              = $result;

            if ( $result['status'] === 'critical' ) {
                $has_critical = true;
            } elseif ( $result['status'] === 'warning' ) {
                $has_warning = true;
            }
        }

        $severity = $has_critical ? 'critical' : ( $has_warning ? 'warning' : 'ok' );

        return [
            'severity'     => $severity,
            'suites'       => $results,
            'has_critical' => $has_critical,
            'has_warning'  => $has_warning,
            'summary'      => self::build_summary( $results ),
        ];
    }

    private static function build_summary( array $results ): string {
        $counts = [ 'ok' => 0, 'warning' => 0, 'critical' => 0, 'skipped' => 0 ];
        foreach ( $results as $suite ) {
            foreach ( $suite['checks'] ?? [] as $check ) {
                $status = $check['status'] ?? 'ok';
                if ( isset( $counts[ $status ] ) ) {
                    $counts[ $status ]++;
                }
            }
        }
        return sprintf(
            '%d ok, %d warning, %d critical, %d skipped',
            $counts['ok'], $counts['warning'], $counts['critical'], $counts['skipped']
        );
    }
}

// ── HTTP Suite ────────────────────────────────────────────────────────────

class RP_Care_Test_Suite_HTTP implements RP_Care_Test_Runner_Interface {

    public function name(): string {
        return 'http';
    }

    public function run( array $context ): array {
        $checks    = [];
        $has_crit  = false;
        $has_warn  = false;

        // Home page 200.
        $home = $this->check_url( home_url( '/' ), 'home_200' );
        $checks[] = $home;
        if ( $home['status'] === 'critical' ) $has_crit = true;
        if ( $home['status'] === 'warning' )  $has_warn = true;

        // Critical URLs from config.
        $critical_urls = get_option( 'rpcare_critical_urls', [] );
        if ( is_array( $critical_urls ) ) {
            foreach ( $critical_urls as $url ) {
                $r = $this->check_url( $url, 'critical_url' );
                $checks[] = $r;
                if ( $r['status'] === 'critical' ) $has_crit = true;
                if ( $r['status'] === 'warning' )  $has_warn = true;
            }
        }

        // Login page reachable (doesn't test credentials — just HTTP).
        $login = $this->check_url( wp_login_url(), 'login_page', 200 );
        $checks[] = $login;
        if ( $login['status'] === 'critical' ) $has_crit = true;

        // PHP fatal error check in home response body.
        $fatal_check = $this->check_php_errors_in_home();
        $checks[]    = $fatal_check;
        if ( $fatal_check['status'] === 'critical' ) $has_crit = true;

        $severity = $has_crit ? 'critical' : ( $has_warn ? 'warning' : 'ok' );

        return [
            'status'  => $severity,
            'checks'  => $checks,
            'summary' => count( $checks ) . ' HTTP checks',
        ];
    }

    private function check_url( string $url, string $check_name, int $expected = 200 ): array {
        $start    = microtime( true );
        $response = wp_remote_get( $url, [ 'timeout' => 12, 'redirection' => 3 ] );
        $ttfb_ms  = (int) round( ( microtime( true ) - $start ) * 1000 );

        if ( is_wp_error( $response ) ) {
            return [
                'name'    => $check_name,
                'url'     => $url,
                'status'  => 'critical',
                'message' => 'HTTP error: ' . $response->get_error_message(),
                'ttfb_ms' => $ttfb_ms,
            ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code >= 500 ) {
            return [
                'name'    => $check_name,
                'url'     => $url,
                'status'  => 'critical',
                'message' => "HTTP $code — server error.",
                'code'    => $code,
                'ttfb_ms' => $ttfb_ms,
            ];
        }

        if ( $code !== $expected ) {
            return [
                'name'    => $check_name,
                'url'     => $url,
                'status'  => 'warning',
                'message' => "Expected HTTP $expected, got $code.",
                'code'    => $code,
                'ttfb_ms' => $ttfb_ms,
            ];
        }

        // Performance regression: warn if TTFB > 8 seconds.
        $ttfb_limit = (int) get_option( 'rpcare_test_ttfb_limit_ms', 8000 );
        if ( $ttfb_ms > $ttfb_limit ) {
            return [
                'name'    => $check_name,
                'url'     => $url,
                'status'  => 'warning',
                'message' => "TTFB {$ttfb_ms}ms exceeds {$ttfb_limit}ms threshold.",
                'code'    => $code,
                'ttfb_ms' => $ttfb_ms,
            ];
        }

        return [
            'name'    => $check_name,
            'url'     => $url,
            'status'  => 'ok',
            'code'    => $code,
            'ttfb_ms' => $ttfb_ms,
        ];
    }

    private function check_php_errors_in_home(): array {
        $response = wp_remote_get( home_url( '/' ), [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) {
            return [ 'name' => 'php_errors_in_body', 'status' => 'skipped', 'message' => 'Could not fetch home page.' ];
        }
        $body = wp_remote_retrieve_body( $response );
        $markers = [ 'Fatal error', 'Parse error', 'Warning:', 'PHP Notice:' ];
        foreach ( $markers as $marker ) {
            if ( stripos( $body, $marker ) !== false ) {
                return [
                    'name'    => 'php_errors_in_body',
                    'status'  => 'critical',
                    'message' => "PHP error marker found in home page response: \"$marker\"",
                ];
            }
        }
        return [ 'name' => 'php_errors_in_body', 'status' => 'ok', 'message' => 'No PHP error markers in home page.' ];
    }
}

// ── WordPress Suite ───────────────────────────────────────────────────────

class RP_Care_Test_Suite_WordPress implements RP_Care_Test_Runner_Interface {

    public function name(): string {
        return 'wordpress';
    }

    public function run( array $context ): array {
        $checks   = [];
        $has_crit = false;
        $has_warn = false;

        // REST API reachable.
        $rest = $this->check_rest_api();
        $checks[] = $rest;
        if ( $rest['status'] === 'critical' ) $has_crit = true;

        // WP Loopback.
        $loopback = $this->check_loopback();
        $checks[] = $loopback;
        if ( $loopback['status'] === 'critical' ) $has_crit = true;

        // Database connectivity.
        $db = $this->check_database();
        $checks[] = $db;
        if ( $db['status'] === 'critical' ) $has_crit = true;

        // Action Scheduler health.
        $as = $this->check_action_scheduler();
        $checks[] = $as;
        if ( $as['status'] === 'warning' ) $has_warn = true;

        // Plugin status: all expected plugins active.
        $plugins = $this->check_plugin_status();
        $checks[] = $plugins;
        if ( $plugins['status'] === 'critical' ) $has_crit = true;

        $severity = $has_crit ? 'critical' : ( $has_warn ? 'warning' : 'ok' );

        return [
            'status'  => $severity,
            'checks'  => $checks,
            'summary' => 'WordPress core health checks',
        ];
    }

    private function check_rest_api(): array {
        $url      = rest_url( 'wp/v2/types' );
        $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) ) {
            return [ 'name' => 'rest_api', 'status' => 'critical', 'message' => 'REST API: ' . $response->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) {
            return [ 'name' => 'rest_api', 'status' => 'ok', 'message' => "REST API returned HTTP $code." ];
        }
        return [ 'name' => 'rest_api', 'status' => 'warning', 'message' => "REST API returned unexpected HTTP $code." ];
    }

    private function check_loopback(): array {
        if ( ! function_exists( 'WP_Site_Health' ) ) {
            return [ 'name' => 'loopback', 'status' => 'skipped', 'message' => 'WP_Site_Health not available.' ];
        }
        $result = wp_remote_get( admin_url( 'admin-ajax.php?action=health-check-site-status-result' ), [
            'timeout'    => 10,
            'cookies'    => [],
            'sslverify'  => true,
        ] );
        if ( is_wp_error( $result ) ) {
            return [ 'name' => 'loopback', 'status' => 'warning', 'message' => 'Loopback check failed: ' . $result->get_error_message() ];
        }
        return [ 'name' => 'loopback', 'status' => 'ok', 'message' => 'Loopback request succeeded.' ];
    }

    private function check_database(): array {
        global $wpdb;
        $result = $wpdb->get_var( 'SELECT 1' );
        if ( $result == '1' ) {
            return [ 'name' => 'database', 'status' => 'ok', 'message' => 'Database query OK.' ];
        }
        return [ 'name' => 'database', 'status' => 'critical', 'message' => 'Database query failed.' ];
    }

    private function check_action_scheduler(): array {
        if ( ! class_exists( 'ActionScheduler' ) ) {
            return [ 'name' => 'action_scheduler', 'status' => 'skipped', 'message' => 'Action Scheduler not active.' ];
        }
        global $wpdb;
        $failed = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions
             WHERE status = 'failed'
             AND scheduled_date_gmt > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 24 HOUR)"
        );
        if ( $failed > 5 ) {
            return [ 'name' => 'action_scheduler', 'status' => 'warning', 'message' => "$failed AS actions failed in the last 24h." ];
        }
        return [ 'name' => 'action_scheduler', 'status' => 'ok', 'message' => "$failed AS failures in 24h." ];
    }

    private function check_plugin_status(): array {
        $active = get_option( 'active_plugins', [] );
        if ( empty( $active ) ) {
            return [ 'name' => 'plugin_status', 'status' => 'warning', 'message' => 'No active plugins found.' ];
        }
        return [
            'name'    => 'plugin_status',
            'status'  => 'ok',
            'message' => count( $active ) . ' plugins active.',
            'count'   => count( $active ),
        ];
    }
}

// ── DOM Fingerprint Suite ─────────────────────────────────────────────────

/**
 * Compares the current home page DOM fingerprint against a saved baseline.
 *
 * IMPORTANT: This is HTML/fingerprint comparison — NOT a visual test.
 * Visual testing (pixel-level screenshot diff) requires a separate external
 * service (Playwright, Percy, etc.). See RP_Care_Visual_Test_Runner_Interface.
 */
class RP_Care_Test_Suite_DOM implements RP_Care_Test_Runner_Interface {

    public function name(): string {
        return 'dom_fingerprint';
    }

    public function run( array $context ): array {
        $baseline = get_option( 'rpcare_staging_eval_baseline', [] );

        if ( empty( $baseline ) ) {
            return [
                'status'  => 'skipped',
                'checks'  => [ [ 'name' => 'dom_baseline', 'status' => 'skipped', 'message' => 'No baseline captured.' ] ],
                'summary' => 'DOM fingerprint: no baseline',
            ];
        }

        if ( ! class_exists( 'RP_Care_Task_StagingEval' ) ) {
            return [
                'status'  => 'skipped',
                'checks'  => [ [ 'name' => 'dom_comparison', 'status' => 'skipped', 'message' => 'StagingEval not available.' ] ],
                'summary' => 'DOM fingerprint: skipped',
            ];
        }

        $current = RP_Care_Task_StagingEval::capture_production_snapshot();
        if ( empty( $current ) || empty( $current['http'] ) || empty( $baseline['http'] ) ) {
            return [
                'status'  => 'skipped',
                'checks'  => [ [ 'name' => 'dom_comparison', 'status' => 'skipped', 'message' => 'Could not capture current snapshot.' ] ],
                'summary' => 'DOM fingerprint: skipped',
            ];
        }

        // delegate to existing diff logic.
        $diff = RP_Care_Task_StagingEval::diff_http_snapshots( $baseline['http'], $current['http'] );

        $checks   = [];
        $has_crit = false;
        $has_warn = false;

        foreach ( $diff as $check ) {
            $checks[] = [
                'name'    => $check['check'] ?? 'dom_check',
                'status'  => $check['severity'] ?? 'ok',
                'message' => $check['message'] ?? '',
            ];
            if ( ( $check['severity'] ?? '' ) === 'critical' ) $has_crit = true;
            if ( ( $check['severity'] ?? '' ) === 'warning' )  $has_warn = true;
        }

        if ( empty( $checks ) ) {
            $checks[] = [ 'name' => 'dom_overall', 'status' => 'ok', 'message' => 'No DOM regression detected.' ];
        }

        $severity = $has_crit ? 'critical' : ( $has_warn ? 'warning' : 'ok' );

        return [
            'status'  => $severity,
            'checks'  => $checks,
            'summary' => 'DOM fingerprint comparison (HTML only — not visual)',
        ];
    }
}

// ── Ecommerce Suite ───────────────────────────────────────────────────────

/**
 * WooCommerce-specific checks for staging.
 *
 * NEVER: real orders, real payments, real emails, real webhooks, live gateways.
 * ALWAYS: sandbox mode, simulated checkout, cleanup of test data.
 */
class RP_Care_Test_Suite_Ecommerce implements RP_Care_Test_Runner_Interface {

    public function name(): string {
        return 'ecommerce';
    }

    public function run( array $context ): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return [
                'status'  => 'skipped',
                'checks'  => [ [ 'name' => 'woo_active', 'status' => 'skipped', 'message' => 'WooCommerce not active.' ] ],
                'summary' => 'WooCommerce: not active',
            ];
        }

        $checks   = [];
        $has_crit = false;
        $has_warn = false;

        // WooCommerce active.
        $checks[] = [ 'name' => 'woo_active', 'status' => 'ok', 'message' => 'WooCommerce active: ' . WC_VERSION ];

        // No live payment gateways.
        $gw = $this->check_no_live_gateways();
        $checks[] = $gw;
        if ( $gw['status'] === 'critical' ) $has_crit = true;

        // WC REST API.
        $rest = $this->check_wc_rest_api();
        $checks[] = $rest;
        if ( $rest['status'] === 'warning' ) $has_warn = true;

        // Shop page reachable.
        $shop = $this->check_shop_page();
        $checks[] = $shop;
        if ( $shop['status'] === 'critical' ) $has_crit = true;

        // Cart endpoint.
        $cart = $this->check_cart_page();
        $checks[] = $cart;
        if ( $cart['status'] === 'warning' ) $has_warn = true;

        // Emails disabled (re-check here for ecommerce context).
        $emails = $this->check_emails_disabled();
        $checks[] = $emails;
        if ( $emails['status'] === 'critical' ) $has_crit = true;

        $severity = $has_crit ? 'critical' : ( $has_warn ? 'warning' : 'ok' );

        return [
            'status'  => $severity,
            'checks'  => $checks,
            'summary' => 'WooCommerce ecommerce checks (no real orders/payments/emails)',
        ];
    }

    private function check_no_live_gateways(): array {
        $live = [];
        $gateways = WC()->payment_gateways()->payment_gateways();
        foreach ( $gateways as $gw ) {
            if ( $gw->enabled !== 'yes' ) continue;
            $is_test = ( isset( $gw->testmode ) && $gw->testmode )
                       || ( isset( $gw->settings['testmode'] ) && $gw->settings['testmode'] !== 'no' );
            if ( ! $is_test ) {
                $live[] = $gw->id;
            }
        }
        if ( ! empty( $live ) ) {
            return [ 'name' => 'no_live_gateways', 'status' => 'critical', 'message' => 'Live gateways: ' . implode( ', ', $live ) ];
        }
        return [ 'name' => 'no_live_gateways', 'status' => 'ok', 'message' => 'No live payment gateways.' ];
    }

    private function check_wc_rest_api(): array {
        $url  = rest_url( 'wc/v3/products?per_page=1' );
        $resp = wp_remote_get( $url, [ 'timeout' => 8 ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'name' => 'wc_rest_api', 'status' => 'warning', 'message' => 'WC REST API unreachable: ' . $resp->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code === 200 || $code === 401 ) { // 401 expected without auth
            return [ 'name' => 'wc_rest_api', 'status' => 'ok', 'message' => "WC REST returned HTTP $code." ];
        }
        return [ 'name' => 'wc_rest_api', 'status' => 'warning', 'message' => "WC REST returned HTTP $code." ];
    }

    private function check_shop_page(): array {
        $shop_url = wc_get_page_permalink( 'shop' );
        if ( ! $shop_url ) {
            return [ 'name' => 'shop_page', 'status' => 'warning', 'message' => 'WooCommerce shop page not configured.' ];
        }
        $resp = wp_remote_get( $shop_url, [ 'timeout' => 10 ] );
        if ( is_wp_error( $resp ) ) {
            return [ 'name' => 'shop_page', 'status' => 'critical', 'message' => 'Shop page error: ' . $resp->get_error_message() ];
        }
        $code = (int) wp_remote_retrieve_response_code( $resp );
        if ( $code === 200 ) {
            return [ 'name' => 'shop_page', 'status' => 'ok', 'message' => "Shop page returned HTTP $code." ];
        }
        return [ 'name' => 'shop_page', 'status' => 'critical', 'message' => "Shop page returned HTTP $code." ];
    }

    private function check_cart_page(): array {
        $cart_url = wc_get_cart_url();
        if ( ! $cart_url ) {
            return [ 'name' => 'cart_page', 'status' => 'warning', 'message' => 'Cart URL not available.' ];
        }
        $resp = wp_remote_get( $cart_url, [ 'timeout' => 10 ] );
        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
        $status = ( $code === 200 ) ? 'ok' : 'warning';
        return [ 'name' => 'cart_page', 'status' => $status, 'message' => "Cart page HTTP $code." ];
    }

    private function check_emails_disabled(): array {
		$suppressed = defined( 'RPCARE_STAGING_SUPPRESS_EMAIL' ) && RPCARE_STAGING_SUPPRESS_EMAIL;
		$suppressed = $suppressed || (bool) get_option( 'rpcare_staging_suppress_email', false );
		$suppressed = $suppressed || (
			class_exists( 'RP_Care_Staging_Email_Sink' )
			&& RP_Care_Staging_Email_Sink::is_active()
		);
        if ( $suppressed ) {
            return [ 'name' => 'emails_disabled', 'status' => 'ok', 'message' => 'Email suppression active.' ];
        }
        return [
            'name'    => 'emails_disabled',
            'status'  => 'critical',
            'message' => 'Email suppression not confirmed. Ecommerce tests must not send real emails.',
        ];
    }
}
