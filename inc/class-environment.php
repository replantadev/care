<?php
/**
 * Server environment detection.
 *
 * Identifies the hosting context so Care can adapt its behaviour:
 * - cyberpanel  → Cedro/CyberPanel (full server control)
 * - wptoolkit   → WP Toolkit Pro active (defer updates to toolkit)
 * - cpanel      → cPanel/WHM without WP Toolkit (e.g. StablePoint manual)
 * - local       → localhost / dev environment
 * - external    → any other hosting
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RP_Care_Environment {

	const OPT_KEY = 'rpcare_server_type';

	/**
	 * Returns the detected server type. Result is cached in a WP option
	 * (not a transient) so it survives across requests without an HTTP call.
	 *
	 * Pass $fresh = true to force re-detection (e.g. after migrations).
	 */
	public static function detect( bool $fresh = false ): string {
		if ( ! $fresh ) {
			$cached = get_option( self::OPT_KEY, '' );
			if ( $cached !== '' ) {
				return $cached;
			}
		}

		$type = self::run_detection();
		update_option( self::OPT_KEY, $type, false );
		return $type;
	}

	/** Force re-detection and update the cached value. */
	public static function refresh(): string {
		return self::detect( true );
	}

	private static function run_detection(): string {
		if ( self::is_local() )       return 'local';
		if ( self::is_cyberpanel() )  return 'cyberpanel';
		if ( self::is_wptoolkit() )   return 'wptoolkit';
		if ( self::is_cpanel() )      return 'cpanel';
		return 'external';
	}

	// ── Individual detectors ─────────────────────────────────────────────

	public static function is_cyberpanel(): bool {
		static $result = null;
		if ( $result !== null ) return $result;

		$result = (
			file_exists( '/usr/local/CyberCP' )    ||
			file_exists( '/usr/local/CyberPanel' ) ||
			file_exists( '/etc/cyberpanel' )        ||
			getenv( 'CYBERPANEL_DOMAIN' ) !== false ||
			defined( 'CYBERPANEL_VERSION' )
		);
		return $result;
	}

	/**
	 * WP Toolkit Pro sets identifiable options when it manages a site.
	 * If active, Care must NOT run its own update task.
	 */
	public static function is_wptoolkit(): bool {
		static $result = null;
		if ( $result !== null ) return $result;

		$result = (
			class_exists( 'Plesk_WP_Toolkit' )           ||
			defined( 'WPSTK_PLUGIN_VERSION' )             ||
			get_option( 'wpstk_last_scan' ) !== false     ||
			get_option( 'wpstk_settings' ) !== false
		);
		return $result;
	}

	public static function is_cpanel(): bool {
		static $result = null;
		if ( $result !== null ) return $result;

		$result = (
			defined( 'CPANEL_ENV' )                      ||
			function_exists( 'cpanel_api' )              ||
			file_exists( '/usr/local/cpanel' )           ||
			file_exists( '/var/cpanel' )                 ||
			getenv( 'CPANEL_USER' ) !== false
		);
		return $result;
	}

	private static function is_local(): bool {
		$host = $_SERVER['HTTP_HOST'] ?? '';
		return (
			str_contains( $host, 'localhost' ) ||
			str_ends_with( $host, '.local' )   ||
			str_ends_with( $host, '.test' )    ||
			$host === '127.0.0.1'
		);
	}

	// ── Helpers for consuming code ───────────────────────────────────────

	/**
	 * Whether an external tool (WP Toolkit Pro) manages updates for this site.
	 * When true, Care must skip its own update task entirely.
	 */
	public static function updates_externally_managed(): bool {
		return self::detect() === 'wptoolkit';
	}

	/** Human-readable label for UI display. */
	public static function label( string $type = null ): string {
		$type = $type ?? self::detect();
		return match ( $type ) {
			'cyberpanel' => 'CyberPanel',
			'wptoolkit'  => 'WP Toolkit',
			'cpanel'     => 'cPanel',
			'local'      => 'Local',
			default      => 'Externo',
		};
	}
}
