<?php
/**
 * Replanta Care — Staging Webhook Guard
 *
 * Blocks outgoing HTTP POST requests from staging environments to prevent
 * staging sites from triggering production-side webhooks (payment processors,
 * notification services, CRMs, etc.).
 *
 * Requests to hosts in the allowlist are permitted; all others are blocked and
 * logged. Internal requests (localhost, 127.x.x.x, *.local, *.test, *.invalid)
 * are always permitted regardless of the allowlist.
 *
 * Allowlist configuration:
 *   - Constant: RPCARE_STAGING_WEBHOOK_ALLOWLIST (comma-separated hostnames)
 *   - Option:   rpcare_staging_webhook_allowlist (JSON array of hostnames)
 *
 * PHP 8.0+ / WP 5.0+.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RP_Care_Staging_Webhook_Guard {

	const OPT_ALLOWLIST = 'rpcare_staging_webhook_allowlist';

	public static function is_active(): bool {
		$env = (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );
		return $env === 'staging';
	}

	public static function register(): void {
		if ( ! self::is_active() ) {
			return;
		}
		add_filter( 'pre_http_request', [ self::class, 'maybe_block' ], 99, 3 );
	}

	/**
	 * Blocks POST requests to non-allowlisted external hosts.
	 *
	 * @param false|array|\WP_Error $response Existing response (false = not yet handled).
	 * @param array                 $args     Request arguments.
	 * @param string                $url      Target URL.
	 * @return false|array|\WP_Error
	 */
	public static function maybe_block( $response, array $args, string $url ) {
		if ( false !== $response ) {
			// Already handled upstream.
			return $response;
		}

		$method = strtoupper( $args['method'] ?? 'GET' );
		if ( $method !== 'POST' ) {
			return $response;
		}

		$host = (string) ( parse_url( $url, PHP_URL_HOST ) ?? '' );
		if ( $host === '' || self::is_internal( $host ) ) {
			return $response;
		}

		if ( self::is_allowed( $host ) ) {
			return $response;
		}

		error_log( sprintf(
			'Replanta Care Staging: Blocked outgoing POST to %s (not in allowlist).',
			$url
		) );

		return new \WP_Error(
			'rpcare_staging_webhook_blocked',
			sprintf(
				'Staging webhook guard: outgoing POST to %s is blocked. ' .
				'Add the host to RPCARE_STAGING_WEBHOOK_ALLOWLIST to permit it.',
				$host
			)
		);
	}

	/**
	 * Returns true when the host is a loopback / private / lab-only address.
	 */
	public static function is_internal( string $host ): bool {
		if ( in_array( $host, [ 'localhost', '127.0.0.1', '::1' ], true ) ) {
			return true;
		}
		if (
			str_ends_with( $host, '.local' ) ||
			str_ends_with( $host, '.test' )  ||
			str_ends_with( $host, '.invalid' )
		) {
			return true;
		}
		// Private IPv4 ranges: 10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16.
		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$long = ip2long( $host );
			if (
				( $long & 0xFF000000 ) === 0x0A000000 ||
				( $long & 0xFFF00000 ) === 0xAC100000 ||
				( $long & 0xFFFF0000 ) === 0xC0A80000
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns true when the host matches a configured allowlist entry.
	 * Supports exact host match and wildcard suffix (e.g. "replanta.dev" also allows "api.replanta.dev").
	 */
	public static function is_allowed( string $host ): bool {
		foreach ( self::get_allowlist() as $entry ) {
			if ( $entry === $host || str_ends_with( $host, '.' . $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the resolved allowlist as a flat array of hostnames.
	 *
	 * @return string[]
	 */
	public static function get_allowlist(): array {
		if ( defined( 'RPCARE_STAGING_WEBHOOK_ALLOWLIST' ) ) {
			$raw = (string) constant( 'RPCARE_STAGING_WEBHOOK_ALLOWLIST' );
			return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		}
		$opt = get_option( self::OPT_ALLOWLIST, [] );
		if ( is_string( $opt ) ) {
			$decoded = json_decode( $opt, true );
			$opt     = is_array( $decoded ) ? $decoded : [];
		}
		return is_array( $opt ) ? array_values( array_filter( $opt ) ) : [];
	}
}
