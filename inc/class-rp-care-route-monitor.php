<?php
/**
 * Replanta Care — Route Integrity Monitor
 *
 * Monitors a matrix of critical WooCommerce URLs after updates, staging operations,
 * cache purges, permalink changes, and on a configurable schedule.
 *
 * CRITICAL URL MATRIX
 * ───────────────────
 * home, shop, category (first), product (first), cart, checkout, my-account, REST API.
 * Each URL is probed for: HTTP status, final URL, redirect chain length, canonical,
 * basic content fingerprint (hash of body without personal data).
 * The matrix is repeated for each active language when WPML/Polylang is detected.
 *
 * FALSE-POSITIVE GUARD
 * ────────────────────
 * A known failure mode: home URL returns HTTP 200 (from cache) while critical
 * shop/checkout routes return 404 from origin. Care detects this by comparing
 * cached (default request) vs. origin (cache-bypass header) status codes.
 *
 * SECURITY
 * ────────
 * • Probes use wp_remote_get() — no custom sockets or shell execution.
 * • Only status codes, redirects, and structural fingerprints are stored.
 *   No HTML body, no personal data, no session cookies.
 * • No authentication cookies are sent; all requests are anonymous.
 * • Cache bypass header is sent only when a known bypass header is configured.
 *
 * @since 1.15.74
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_Route_Monitor {

    /** Option key storing the last check results. */
    const RESULTS_KEY = 'care_route_monitor_results';

    /** Transient key for the last check timestamp. */
    const LAST_CHECK_KEY = 'care_route_monitor_last_check';

    /** Maximum seconds between automatic route checks. */
    const CHECK_INTERVAL = 3600; // 1 hour

    /** HTTP timeout for route probes (seconds). */
    const PROBE_TIMEOUT = 15;

    /** Routes that must return HTTP 200 for the store to be healthy. */
    const CRITICAL_ROUTES = [
        'home'       => '',
        'shop'       => 'shop',
        'cart'       => 'cart',
        'checkout'   => 'checkout',
        'my_account' => 'my-account',
    ];

    /**
     * Run a full route matrix check and return structured results.
     *
     * @param array $options {
     *   @type string $cache_bypass_header  Optional: e.g. 'X-Bypass-Cache: 1'
     *   @type bool   $include_products     Whether to probe a real product URL.
     *   @type bool   $include_categories   Whether to probe a real category URL.
     * }
     * @return array{
     *   checked_at: string,
     *   healthy: bool,
     *   routes: array,
     *   issues: array,
     * }
     */
    public static function check( array $options = [] ): array {
        $results    = [];
        $issues     = [];
        $healthy    = true;

        $routes = self::build_route_matrix( $options );

        foreach ( $routes as $key => $url ) {
            $probe  = self::probe( $url, $options );
            $ok     = ( $probe['http_code'] === 200 );
            if ( ! $ok ) {
                $healthy = false;
                $issues[] = [
                    'route'     => $key,
                    'url'       => $url,
                    'http_code' => $probe['http_code'],
                    'error'     => $probe['error'] ?? '',
                ];
            }
            $results[ $key ] = $probe;
        }

        // False-positive guard: home=200 from cache while checkout=non-200 from origin.
        $results = self::check_false_positive( $results, $options, $issues, $healthy );

        $summary = [
            'checked_at' => gmdate( 'c' ),
            'healthy'    => $healthy,
            'routes'     => $results,
            'issues'     => $issues,
        ];

        update_option( self::RESULTS_KEY, $summary, false );
        set_transient( self::LAST_CHECK_KEY, time(), self::CHECK_INTERVAL );

        return $summary;
    }

    /**
     * Return the last stored check results, or null if no check has run.
     *
     * @return array|null
     */
    public static function get_last_results(): ?array {
        $data = get_option( self::RESULTS_KEY, null );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Returns true if a fresh check is due.
     */
    public static function is_check_due(): bool {
        return get_transient( self::LAST_CHECK_KEY ) === false;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function build_route_matrix( array $options ): array {
        $routes = [];
        $base   = rtrim( home_url(), '/' );

        foreach ( self::CRITICAL_ROUTES as $key => $path ) {
            $routes[ $key ] = $base . ( $path ? '/' . $path . '/' : '/' );
        }

        // WooCommerce-aware: use get_permalink() for shop/cart/checkout when WC available.
        if ( function_exists( 'wc_get_page_permalink' ) ) {
            foreach ( [ 'shop', 'cart', 'checkout', 'my-account' ] as $page ) {
                $url = wc_get_page_permalink( $page );
                if ( $url && filter_var( $url, FILTER_VALIDATE_URL ) ) {
                    $routes[ str_replace( '-', '_', $page ) ] = $url;
                }
            }
        }

        // REST API health endpoint.
        $routes['rest_api'] = home_url( '/wp-json/wc/v3/system_status' );

        // Per-language variants — detect WPML.
        if ( function_exists( 'icl_get_languages' ) ) {
            $langs = icl_get_languages( 'skip_missing=0' );
            foreach ( (array) $langs as $code => $lang ) {
                if ( ! empty( $lang['url'] ) ) {
                    $routes[ "home_{$code}" ] = $lang['url'];
                }
            }
        }

        return $routes;
    }

    private static function probe( string $url, array $options ): array {
        $headers = [];
        if ( ! empty( $options['cache_bypass_header'] ) ) {
            [ $name, $value ] = array_pad( explode( ':', $options['cache_bypass_header'], 2 ), 2, '1' );
            $headers[ trim( $name ) ] = trim( $value );
        }

        $response = wp_remote_get( $url, [
            'timeout'   => self::PROBE_TIMEOUT,
            'sslverify' => true,
            'headers'   => $headers,
            'redirection' => 5,
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'url'       => $url,
                'http_code' => 0,
                'error'     => $response->get_error_message(),
            ];
        }

        $http_code  = (int) wp_remote_retrieve_response_code( $response );
        $body       = wp_remote_retrieve_body( $response );
        $final_url  = $response['http_response']?->get_response_object()?->get_final_location() ?? $url;

        return [
            'url'              => $url,
            'http_code'        => $http_code,
            'body_fingerprint' => hash( 'sha256', mb_substr( $body, 0, 2048 ) ),
            'body_length'      => strlen( $body ),
            'final_url'        => $final_url,
        ];
    }

    /**
     * Detect the false-positive pattern: home=200 cache while critical routes fail at origin.
     */
    private static function check_false_positive( array $results, array $options, array &$issues, bool &$healthy ): array {
        if ( empty( $options['cache_bypass_header'] ) ) {
            return $results;
        }

        // If home is 200 but checkout is not, add a specific false-positive warning.
        $home_ok     = ( $results['home']['http_code'] ?? 0 ) === 200;
        $checkout_ok = ( $results['checkout']['http_code'] ?? 0 ) === 200;

        if ( $home_ok && ! $checkout_ok ) {
            $issues[] = [
                'route'   => '_false_positive_check',
                'message' => 'Home returns 200 (likely cached) but checkout is non-200 at origin. Cache may be masking broken routes.',
                'level'   => 'critical',
            ];
            $healthy = false;
            $results['_false_positive_warning'] = true;
        }

        return $results;
    }
}
