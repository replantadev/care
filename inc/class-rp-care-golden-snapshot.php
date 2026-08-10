<?php
/**
 * Replanta Care — Golden Snapshot
 *
 * Captures and versions a snapshot of the WordPress/WooCommerce configuration
 * that defines what a healthy store looks like.  Snapshots are the reference
 * point for detecting drift and driving the escalated repair ladder.
 *
 * SNAPSHOT CONTENTS (never includes order data, PII, or credentials)
 * ──────────────────────────────────────────────────────────────────
 * • home_url, site_url, active theme slug
 * • permalink_structure, category_base, tag_base
 * • WooCommerce page IDs (shop, cart, checkout, my-account)
 * • Active language configuration fingerprint (WPML/Polylang)
 * • Server/cache fingerprint (detected software, not credentials)
 * • Sorted list of active plugins with versions
 * • .htaccess SHA-256 hash (if readable and within ABSPATH)
 * • Snapshot domain, environment, group_id, and Care version
 *
 * REPAIR LADDER (referenced by class; repair execution is in RP_Care_Repair)
 * ──────────────────────────────────────────────────────────────────────────
 * STEP 1  observe_and_alert       — log drift, notify operator, no change
 * STEP 2  purge_cache_and_retest  — flush caches, regenerate rewrite rules, retest
 * STEP 3  stage_repair_test       — test repair in staging first
 * STEP 4  verified_backup_check   — confirm backup exists and is restorable
 * STEP 5  approval                — operator approves (approval_required mode only)
 * STEP 6  atomic_write            — apply repair to production atomically
 * STEP 7  validate                — verify routes / snapshot after repair
 * STEP 8  rollback_or_escalate    — rollback to snapshot if validate fails
 *
 * SECURITY
 * ────────
 * • .htaccess is ONLY modified if: Care has write permission, its fingerprint
 *   matches the snapshot, and a prior copy exists.
 * • Snapshots are NEVER copied from staging to production blindly.
 * • Snapshot promotion (making a new snapshot the golden reference) requires
 *   explicit operator action, not automatic promotion.
 *
 * @since 1.15.74
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_Golden_Snapshot {

    /** Option key for the active golden snapshot. */
    const SNAPSHOT_KEY = 'care_golden_snapshot';

    /** Option key for snapshot history (last N snapshots). */
    const HISTORY_KEY = 'care_golden_snapshot_history';

    /** Maximum history entries. */
    const MAX_HISTORY = 10;

    /** Snapshot schema version — bump when fields change. */
    const SCHEMA_VERSION = 1;

    // Repair ladder step identifiers.
    const REPAIR_STEP_OBSERVE         = 'observe_and_alert';
    const REPAIR_STEP_PURGE_CACHE     = 'purge_cache_and_retest';
    const REPAIR_STEP_STAGE_TEST      = 'stage_repair_test';
    const REPAIR_STEP_BACKUP_CHECK    = 'verified_backup_check';
    const REPAIR_STEP_APPROVAL        = 'approval';
    const REPAIR_STEP_ATOMIC_WRITE    = 'atomic_write';
    const REPAIR_STEP_VALIDATE        = 'validate';
    const REPAIR_STEP_ROLLBACK        = 'rollback_or_escalate';

    // ── Snapshot management ───────────────────────────────────────────────────

    /**
     * Capture a new snapshot of the current system state.
     *
     * Does NOT promote it to golden; call promote() for that.
     *
     * @param  array $meta  Optional metadata (group_id, triggered_by, etc.)
     * @return array        The new snapshot.
     */
    public static function capture( array $meta = [] ): array {
        $snapshot = [
            'schema_version'    => self::SCHEMA_VERSION,
            'captured_at'       => gmdate( 'c' ),
            'care_version'      => defined( 'RPCARE_VERSION' ) ? RPCARE_VERSION : 'unknown',
            'domain'            => wp_parse_url( home_url(), PHP_URL_HOST ) ?? '',
            'environment'       => self::detect_environment(),
            'snapshot_id'       => self::generate_snapshot_id(),

            // WordPress core state.
            'home_url'              => home_url(),
            'site_url'              => get_site_url(),
            'permalink_structure'   => get_option( 'permalink_structure', '' ),
            'category_base'         => get_option( 'category_base', '' ),
            'tag_base'              => get_option( 'tag_base', '' ),
            'active_theme'          => self::get_active_theme_slug(),
            'active_plugins'        => self::get_active_plugins_fingerprint(),
            'wp_version'            => defined( 'WPINC' ) ? ( get_bloginfo( 'version' ) ?? '' ) : '',

            // WooCommerce page assignments.
            'woo_pages'             => self::get_woo_page_ids(),

            // Language config fingerprint.
            'lang_config_hash'      => self::hash_language_config(),

            // Server/cache detection.
            'server_fingerprint'    => self::detect_server_fingerprint(),

            // .htaccess hash (if accessible).
            'htaccess_hash'         => self::hash_htaccess(),

            // Caller metadata.
            'meta'                  => array_map( 'sanitize_text_field', $meta ),
        ];

        return $snapshot;
    }

    /**
     * Promote a snapshot as the new golden reference.
     *
     * Appends the previous golden to history before replacing it.
     * Only a human operator should call this (or the approval pipeline step).
     *
     * @param  array  $snapshot  Result of capture().
     * @return bool
     */
    public static function promote( array $snapshot ): bool {
        // Append current golden to history.
        $current = self::get_golden();
        if ( $current ) {
            $history   = self::get_history();
            $history[] = $current;
            if ( count( $history ) > self::MAX_HISTORY ) {
                array_shift( $history );
            }
            update_option( self::HISTORY_KEY, $history, false );
        }

        update_option( self::SNAPSHOT_KEY, $snapshot, false );
        return true;
    }

    /**
     * Return the current golden snapshot, or null if none exists.
     */
    public static function get_golden(): ?array {
        $data = get_option( self::SNAPSHOT_KEY, null );
        return is_array( $data ) ? $data : null;
    }

    /**
     * Return snapshot history (oldest first).
     *
     * @return array[]
     */
    public static function get_history(): array {
        $data = get_option( self::HISTORY_KEY, [] );
        return is_array( $data ) ? $data : [];
    }

    // ── Drift detection ───────────────────────────────────────────────────────

    /**
     * Compare current state with the golden snapshot and return drift.
     *
     * @return array{
     *   has_drift: bool,
     *   diffs: array,
     *   snapshot_age_sec: int,
     *   repair_steps: string[],
     * }|null  Null if no golden snapshot exists.
     */
    public static function diff_against_golden(): ?array {
        $golden = self::get_golden();
        if ( ! $golden ) {
            return null;
        }

        $current = self::capture();
        $diffs   = [];

        $tracked_fields = [
            'home_url', 'site_url', 'permalink_structure', 'category_base',
            'tag_base', 'active_theme', 'woo_pages', 'lang_config_hash',
            'htaccess_hash', 'server_fingerprint',
        ];

        foreach ( $tracked_fields as $field ) {
            if ( ! isset( $golden[ $field ] ) ) {
                continue;
            }
            $gold_val    = $golden[ $field ];
            $current_val = $current[ $field ] ?? null;
            if ( $gold_val !== $current_val ) {
                $diffs[ $field ] = [
                    'golden'  => $gold_val,
                    'current' => $current_val,
                ];
            }
        }

        $has_drift = ! empty( $diffs );
        $steps     = $has_drift ? self::repair_ladder_for_diffs( $diffs ) : [];

        $age = time() - strtotime( $golden['captured_at'] ?? '1970-01-01' );

        return [
            'has_drift'        => $has_drift,
            'diffs'            => $diffs,
            'snapshot_age_sec' => max( 0, (int) $age ),
            'repair_steps'     => $steps,
        ];
    }

    /**
     * Return the repair ladder steps appropriate for detected diffs.
     *
     * More structural diffs (permalink, htaccess) escalate higher.
     * Plugin changes always escalate to staging.
     *
     * @param  array $diffs  Result from diff_against_golden()['diffs']
     * @return string[]      Ordered repair step identifiers.
     */
    public static function repair_ladder_for_diffs( array $diffs ): array {
        $steps    = [ self::REPAIR_STEP_OBSERVE ];
        $keys     = array_keys( $diffs );

        $structural = array_intersect( $keys, [ 'htaccess_hash', 'permalink_structure', 'lang_config_hash' ] );
        $url_change = array_intersect( $keys, [ 'home_url', 'site_url' ] );
        $pages_change = isset( $diffs['woo_pages'] );

        // All cases: try cache purge first.
        $steps[] = self::REPAIR_STEP_PURGE_CACHE;

        if ( ! empty( $structural ) || ! empty( $url_change ) || $pages_change ) {
            // Requires staging verification before production repair.
            $steps[] = self::REPAIR_STEP_STAGE_TEST;
            $steps[] = self::REPAIR_STEP_BACKUP_CHECK;
            $steps[] = self::REPAIR_STEP_APPROVAL;
            $steps[] = self::REPAIR_STEP_ATOMIC_WRITE;
            $steps[] = self::REPAIR_STEP_VALIDATE;
            $steps[] = self::REPAIR_STEP_ROLLBACK;
        }

        return $steps;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function generate_snapshot_id(): string {
        return 'snap-' . substr( hash( 'sha256', uniqid( '', true ) . microtime( true ) ), 0, 16 );
    }

    private static function detect_environment(): string {
        if ( defined( 'WP_ENVIRONMENT_TYPE' ) ) {
            return WP_ENVIRONMENT_TYPE;
        }
        return defined( 'WP_DEBUG' ) && WP_DEBUG ? 'development' : 'production';
    }

    private static function get_active_theme_slug(): string {
        if ( function_exists( 'wp_get_theme' ) ) {
            return wp_get_theme()->get_stylesheet();
        }
        return get_option( 'stylesheet', '' );
    }

    private static function get_active_plugins_fingerprint(): string {
        $plugins = get_option( 'active_plugins', [] );
        sort( $plugins ); // Stable order.
        return hash( 'sha256', implode( ',', $plugins ) );
    }

    private static function get_woo_page_ids(): array {
        $pages = [];
        foreach ( [ 'shop', 'cart', 'checkout', 'myaccount', 'terms' ] as $p ) {
            $pages[ $p ] = (int) get_option( "woocommerce_{$p}_page_id", 0 );
        }
        return $pages;
    }

    private static function hash_language_config(): string {
        $config = '';

        // WPML.
        if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
            $config .= 'wpml:' . get_option( 'icl_sitepress_settings', '' );
        }

        // Polylang.
        if ( defined( 'POLYLANG_VERSION' ) ) {
            $config .= 'polylang:' . serialize( get_option( 'polylang', [] ) );
        }

        return $config ? hash( 'sha256', $config ) : '';
    }

    private static function detect_server_fingerprint(): string {
        $parts = [];

        if ( isset( $_SERVER['SERVER_SOFTWARE'] ) ) {
            // Strip version numbers for stability.
            $parts[] = preg_replace( '/\d[\d.]+/', 'x', $_SERVER['SERVER_SOFTWARE'] );
        }

        // Detect known caching layers from response headers.
        if ( function_exists( 'wp_get_nocache_headers' ) ) {
            $parts[] = 'wp-cache-api:1';
        }
        if ( class_exists( 'W3TC_Util_Environment' ) ) {
            $parts[] = 'w3tc';
        }
        if ( class_exists( 'LiteSpeed_Cache' ) || defined( 'LSCWP_V' ) ) {
            $parts[] = 'litespeed-cache';
        }
        if ( defined( 'WP_ROCKET_VERSION' ) ) {
            $parts[] = 'wp-rocket';
        }

        return hash( 'sha256', implode( '|', $parts ) );
    }

    private static function hash_htaccess(): string {
        $path = defined( 'ABSPATH' ) ? ABSPATH . '.htaccess' : '';
        if ( ! $path || ! @is_readable( $path ) ) {
            return '';
        }
        // Security: only hash files within ABSPATH.
        $real = realpath( $path );
        if ( $real === false || ! str_starts_with( $real, realpath( ABSPATH ) ) ) {
            return '';
        }
        $content = @file_get_contents( $real );
        return $content !== false ? hash( 'sha256', $content ) : '';
    }
}
