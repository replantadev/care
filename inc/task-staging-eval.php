<?php
/**
 * Staging evaluation — DOM fingerprint + Elementor structure diff.
 *
 * Compares production vs staging via HTTP requests to detect layout regressions
 * before applying updates to production. No headless browser required.
 *
 * Algorithm:
 *  1. Fetch production homepage → extract DOM fingerprint (section counts, widget types, CSS list, etc.)
 *  2. Fetch staging homepage  → same extraction
 *  3. diff_http_snapshots()   → severity-rated diff: ok / warning / critical
 *
 * Also captures Elementor DB structure from the local WP DB (production only) as an
 * independent second signal — useful when staging access is restricted.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Task_StagingEval {

    const OPT_BASELINE = 'rpcare_staging_eval_baseline';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Capture a DOM fingerprint from any URL via HTTP.
     * Used for both production (self) and staging (remote).
     */
    public static function capture_http_snapshot( string $url ): array {
        $response = wp_remote_get( $url, [
            'timeout'   => 30,
            'sslverify' => false,
            'headers'   => [
                'User-Agent' => 'ReplantaCare-StagingEval/1.0',
                'Accept'     => 'text/html,application/xhtml+xml',
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => $response->get_error_message(), 'url' => $url, 'captured_at' => time() ];
        }

        $status = (int) wp_remote_retrieve_response_code( $response );
        if ( $status >= 400 ) {
            return [ 'error' => "HTTP {$status}", 'status_code' => $status, 'url' => $url, 'captured_at' => time() ];
        }

        $html = wp_remote_retrieve_body( $response );
        return array_merge(
            self::parse_dom_fingerprint( $html ),
            [ 'url' => $url, 'status_code' => $status, 'captured_at' => time() ]
        );
    }

    /**
     * Capture Elementor tree structure from the local WP DB.
     * Strips per-instance UUIDs so the hash is stable across clones.
     */
    public static function capture_local_elementor_snapshot(): array {
        global $wpdb;

        $rows = $wpdb->get_results( "
            SELECT p.ID, p.post_title, pm.meta_value
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_ID
            WHERE p.post_status = 'publish'
              AND p.post_type   IN ('page', 'post')
              AND pm.meta_key   = '_elementor_data'
              AND pm.meta_value NOT IN ('', '[]')
            ORDER BY p.menu_order ASC, p.post_date DESC
            LIMIT 10
        " );

        $pages = [];
        foreach ( $rows as $row ) {
            // Remove per-instance UUIDs (they regenerate on every clone) → stable structure hash
            $normalized          = preg_replace( '/"id":"[a-zA-Z0-9]+"/', '"id":"X"', $row->meta_value );
            $pages[ $row->ID ]   = [
                'title'      => $row->post_title,
                'tree_hash'  => md5( $normalized ),
                'node_count' => substr_count( $row->meta_value, '"elType"' ),
            ];
        }

        return $pages;
    }

    /**
     * Full production snapshot: HTTP fingerprint + Elementor DB.
     * Stored as OPT_BASELINE for later comparison by PC.
     */
    public static function capture_production_snapshot(): array {
        $http      = self::capture_http_snapshot( home_url( '/' ) );
        $elementor = self::capture_local_elementor_snapshot();

        $snapshot = array_merge( $http, [
            'elementor_db' => $elementor,
            'captured_at'  => time(),
        ] );

        update_option( self::OPT_BASELINE, $snapshot, false );

        return $snapshot;
    }

    /**
     * Compare two HTTP snapshots (production vs staging).
     * Returns severity-rated diff report.
     *
     * @param array $before  Production snapshot
     * @param array $after   Staging snapshot (post-update)
     */
    public static function diff_http_snapshots( array $before, array $after ): array {
        $issues   = [];
        $severity = 'ok';

        // ── Staging fetch errors ──────────────────────────────────────────────
        if ( ! empty( $after['error'] ) ) {
            return [
                'severity' => 'critical',
                'issues'   => [ [ 'type' => 'fetch_error', 'sev' => 'critical',
                    'msg' => 'No se pudo cargar el staging: ' . $after['error'] ] ],
                'summary'  => 'Error al cargar staging',
                'before'   => $before,
                'after'    => $after,
            ];
        }

        // ── PHP errors visible in HTML ────────────────────────────────────────
        if ( ! empty( $after['has_fatal'] ) ) {
            $issues[] = [ 'type' => 'php_fatal', 'sev' => 'critical',
                'msg' => 'Error fatal de PHP detectado en staging (visible en HTML)' ];
            $severity = 'critical';
        }
        if ( ! empty( $after['has_php_warning'] ) && empty( $before['has_php_warning'] ) ) {
            $issues[] = [ 'type' => 'php_warning', 'sev' => 'warning',
                'msg' => 'Warnings de PHP en staging que no existen en producción' ];
            $severity = self::max_sev( $severity, 'warning' );
        }

        // ── Elementor sections (structural layout) ────────────────────────────
        $s_b = $before['elementor_sections'] ?? 0;
        $s_a = $after['elementor_sections']  ?? 0;
        if ( $s_b > 0 || $s_a > 0 ) {
            $delta = $s_a - $s_b;
            if ( abs( $delta ) >= 2 ) {
                $sev      = abs( $delta ) >= 5 ? 'critical' : 'warning';
                $issues[] = [ 'type' => 'elementor_sections', 'sev' => $sev,
                    'msg' => sprintf( 'Secciones Elementor: %d → %d (%+d)', $s_b, $s_a, $delta ) ];
                $severity = self::max_sev( $severity, $sev );
            }
        }

        // ── Elementor widget count ────────────────────────────────────────────
        $w_b = $before['elementor_widgets'] ?? 0;
        $w_a = $after['elementor_widgets']  ?? 0;
        if ( $w_b > 0 || $w_a > 0 ) {
            $delta = $w_a - $w_b;
            if ( abs( $delta ) >= 5 ) {
                $issues[] = [ 'type' => 'elementor_widgets', 'sev' => 'warning',
                    'msg' => sprintf( 'Widgets Elementor: %d → %d (%+d)', $w_b, $w_a, $delta ) ];
                $severity = self::max_sev( $severity, 'warning' );
            }
        }

        // ── CSS stylesheets removed ───────────────────────────────────────────
        $css_removed = array_diff( $before['stylesheet_urls'] ?? [], $after['stylesheet_urls'] ?? [] );
        $n_removed   = count( $css_removed );
        if ( $n_removed >= 2 ) {
            $issues[] = [ 'type' => 'css_removed', 'sev' => 'critical',
                'msg' => "{$n_removed} hojas de estilo eliminadas — posible pérdida de diseño" ];
            $severity = 'critical';
        } elseif ( $n_removed === 1 ) {
            $issues[] = [ 'type' => 'css_removed', 'sev' => 'warning',
                'msg' => '1 hoja de estilo eliminada' ];
            $severity = self::max_sev( $severity, 'warning' );
        }

        // ── Navigation items lost ─────────────────────────────────────────────
        $nav_b = $before['menu_items'] ?? 0;
        $nav_a = $after['menu_items']  ?? 0;
        if ( $nav_b > 3 && ( $nav_b - $nav_a ) > 2 ) {
            $issues[] = [ 'type' => 'nav_change', 'sev' => 'warning',
                'msg' => sprintf( 'Ítems de menú: %d → %d', $nav_b, $nav_a ) ];
            $severity = self::max_sev( $severity, 'warning' );
        }

        // ── Page size regression ──────────────────────────────────────────────
        $size_b = $before['page_size'] ?? 0;
        $size_a = $after['page_size']  ?? 0;
        if ( $size_b > 5000 && $size_a > 0 ) {
            $ratio = $size_a / $size_b;
            if ( $ratio < 0.65 ) {
                $issues[] = [ 'type' => 'content_loss', 'sev' => 'critical',
                    'msg' => sprintf( 'Contenido reducido al %d%% — posible error de renderizado', (int) round( $ratio * 100 ) ) ];
                $severity = 'critical';
            } elseif ( $ratio < 0.80 ) {
                $issues[] = [ 'type' => 'content_loss', 'sev' => 'warning',
                    'msg' => sprintf( 'Contenido reducido al %d%%', (int) round( $ratio * 100 ) ) ];
                $severity = self::max_sev( $severity, 'warning' );
            }
        }

        $n = count( $issues );
        return [
            'severity' => $severity,
            'issues'   => $issues,
            'summary'  => $n === 0
                ? 'Sin cambios detectados — staging parece estable'
                : "{$n} cambio(s) detectado(s)",
            'before'   => $before,
            'after'    => $after,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private static function parse_dom_fingerprint( string $html ): array {
        preg_match_all( '/class="[^"]*elementor-section[^"]*"/i', $html, $m_sec );
        preg_match_all( '/class="[^"]*elementor-container[^"]*"/i', $html, $m_cont );
        preg_match_all( '/class="[^"]*elementor-widget[^"]*"/i', $html, $m_wid );
        preg_match_all( '/data-widget_type="([^"]+)"/i', $html, $m_wtypes );
        preg_match_all( '/<li[^>]*class="[^"]*menu-item[^"]*"/i', $html, $m_menu );
        preg_match_all( '/<img\b/i', $html, $m_img );
        preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*href="([^"#?]+)/i', $html, $m_css );

        $has_fatal   = (bool) preg_match( '/Fatal\s+error|Call\s+to\s+undefined|Parse\s+error/i', $html );
        $has_warning = (bool) preg_match( '/\bWarning\b.*?on line|\<b\>Warning\<\/b\>/i', $html );

        $widget_types = [];
        foreach ( $m_wtypes[1] as $wt ) {
            $k                = sanitize_key( $wt );
            $widget_types[$k] = ( $widget_types[$k] ?? 0 ) + 1;
        }

        return [
            'elementor_sections'   => count( $m_sec[0] ),
            'elementor_containers' => count( $m_cont[0] ),
            'elementor_widgets'    => count( $m_wid[0] ),
            'widget_types'         => $widget_types,
            'menu_items'           => count( $m_menu[0] ),
            'image_count'          => count( $m_img[0] ),
            'stylesheet_count'     => count( $m_css[0] ),
            'stylesheet_urls'      => array_slice( $m_css[1], 0, 20 ),
            'has_fatal'            => $has_fatal,
            'has_php_warning'      => $has_warning,
            'page_size'            => strlen( $html ),
        ];
    }

    private static function max_sev( string $a, string $b ): string {
        $map = [ 'ok' => 0, 'warning' => 1, 'critical' => 2 ];
        return ( $map[$b] ?? 0 ) > ( $map[$a] ?? 0 ) ? $b : $a;
    }
}
