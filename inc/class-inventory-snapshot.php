<?php
/**
 * Replanta Care — Inventory Snapshot
 *
 * Captures a deterministic snapshot of the current WordPress installation:
 * core version, PHP version, all active plugins (file, version, name),
 * all installed themes (active + inactive), and active translations.
 *
 * The snapshot is used to:
 *  - Populate the batch manifest at batch_created time.
 *  - Compute the production_inventory_hash bound to an approval.
 *  - Compare inventories for drift detection before production updates.
 *
 * Compatible with PHP 7.4+ (Care's minimum requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Inventory_Snapshot {

    /**
     * Capture the full current-site inventory.
     *
     * @return array{
     *   captured_at: string,
     *   wp_version: string,
     *   php_version: string,
     *   woo_version: string|null,
     *   plugins: array,
     *   themes: array,
     *   translations: array,
     * }
     */
    public static function capture(): array {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if ( ! function_exists( 'get_plugin_updates' ) ) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }

        return [
            'captured_at'  => gmdate( 'c' ),
            'wp_version'   => self::get_wp_version(),
            'php_version'  => PHP_VERSION,
            'woo_version'  => self::get_woo_version(),
            'plugins'      => self::get_plugins_snapshot(),
            'themes'       => self::get_themes_snapshot(),
            'translations' => self::get_translations_snapshot(),
        ];
    }

    /**
     * Compute the deterministic inventory hash (same algorithm as PC_Manifest).
     * Used to detect drift between the approval hash and the current state.
     */
    public static function hash( array $inventory ): string {
        $canonical = [
            'wp_version'  => $inventory['wp_version'] ?? '',
            'php_version' => $inventory['php_version'] ?? '',
            'plugins'     => self::normalise_plugin_list( $inventory['plugins'] ?? [] ),
            'themes'      => self::normalise_theme_list( $inventory['themes'] ?? [] ),
        ];
        ksort( $canonical );
        return hash(
            'sha256',
            (string) wp_json_encode(
                self::sort_recursive( $canonical ),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            )
        );
    }

    /**
     * Returns true if the current inventory hash matches $expected_hash.
     * Used by the production drift-check step.
     */
    public static function matches_hash( string $expected_hash ): bool {
        $inv    = self::capture();
        $actual = self::hash( $inv );
        return hash_equals( $expected_hash, $actual );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private static function get_wp_version(): string {
        global $wp_version;
        return (string) ( $wp_version ?? get_bloginfo( 'version' ) );
    }

    private static function get_woo_version(): ?string {
        if ( defined( 'WC_VERSION' ) ) {
            return WC_VERSION;
        }
        if ( class_exists( 'WooCommerce' ) ) {
            return WooCommerce::instance()->version ?? null;
        }
        return null;
    }

    /**
     * Returns a normalised map of plugin_file → { name, version, slug, active }.
     */
    private static function get_plugins_snapshot(): array {
        $all    = get_plugins();
        $active = get_option( 'active_plugins', [] );

        $result = [];
        foreach ( $all as $file => $data ) {
            $slug            = dirname( $file );
            $result[ $file ] = [
                'name'    => $data['Name'] ?? '',
                'version' => $data['Version'] ?? '',
                'slug'    => $slug === '.' ? basename( $file, '.php' ) : $slug,
                'active'  => in_array( $file, $active, true ),
            ];
        }

        ksort( $result, SORT_STRING );
        return $result;
    }

    /**
     * Returns a normalised map of theme_slug → { name, version, active }.
     */
    private static function get_themes_snapshot(): array {
        $themes         = wp_get_themes();
        $active_slug    = get_stylesheet();

        $result = [];
        foreach ( $themes as $slug => $theme ) {
            $result[ $slug ] = [
                'name'    => $theme->get( 'Name' ),
                'version' => $theme->get( 'Version' ),
                'active'  => $slug === $active_slug,
            ];
        }

        ksort( $result, SORT_STRING );
        return $result;
    }

    /**
     * Returns installed translation slugs (language codes, no credentials).
     */
    private static function get_translations_snapshot(): array {
        $locale = get_locale();
        if ( 'en_US' === $locale ) {
            return [];
        }

        $available = wp_get_available_translations();
        if ( empty( $available ) ) {
            return [ $locale ];
        }

        return array_keys( array_filter( $available, fn( $t ) => ! empty( $t['language'] ) ) );
    }

    private static function normalise_plugin_list( array $plugins ): array {
        $result = [];
        foreach ( $plugins as $file => $info ) {
            $result[ $file ] = [
                'name'    => $info['name'] ?? '',
                'version' => $info['version'] ?? '',
                'slug'    => $info['slug'] ?? dirname( $file ),
            ];
        }
        ksort( $result, SORT_STRING );
        return $result;
    }

    private static function normalise_theme_list( array $themes ): array {
        $result = [];
        foreach ( $themes as $slug => $info ) {
            $result[ $slug ] = [
                'name'    => $info['name'] ?? '',
                'version' => $info['version'] ?? '',
            ];
        }
        ksort( $result, SORT_STRING );
        return $result;
    }

    private static function sort_recursive( array $arr ): array {
        ksort( $arr, SORT_STRING );
        foreach ( $arr as $k => $v ) {
            if ( is_array( $v ) ) {
                $arr[ $k ] = self::sort_recursive( $v );
            }
        }
        return $arr;
    }
}
