<?php
/**
 * Read-only SEO/indexing inventory exposed to Plugin Center.
 *
 * This class never asks Google to index a URL and never writes posts, robots,
 * sitemaps or SEO metadata. It only reports the site's current public surface.
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_SEO_Index_Inventory {

    public const SCHEMA_VERSION = 1;
    public const MAX_URLS       = 100;
    public const MAX_SITEMAPS   = 20;

    /** @var callable|null Test seam returning ['ids'=>[], 'total'=>int]. */
    public static $post_reader = null;

    /** @var callable|null Test seam returning ['code'=>int, 'body'=>string, 'error'=>string]. */
    public static $http_reader = null;

    public static function build( int $limit = 50 ): array {
        $limit       = max( 1, min( self::MAX_URLS, $limit ) );
        $environment = self::environment();
        $is_staging  = 'staging' === $environment;
        $blog_public = (int) get_option( 'blog_public', 1 ) === 1;
        $posts       = self::read_posts( $limit - 1 );

        $urls = [[
            'url'         => esc_url_raw( home_url( '/' ) ),
            'canonical'   => esc_url_raw( home_url( '/' ) ),
            'object_type' => 'home',
            'modified_at' => '',
        ]];

        foreach ( array_slice( (array) ( $posts['ids'] ?? [] ), 0, max( 0, $limit - 1 ) ) as $post_id ) {
            $url = esc_url_raw( (string) get_permalink( (int) $post_id ) );
            if ( '' === $url ) continue;
            $urls[] = [
                'url'         => $url,
                'canonical'   => $url,
                'object_type' => sanitize_key( (string) get_post_type( (int) $post_id ) ),
                'modified_at' => (string) get_post_modified_time( 'c', true, (int) $post_id ),
            ];
        }

        $urls = array_values( array_reduce( $urls, static function ( array $carry, array $item ): array {
            if ( ! empty( $item['url'] ) ) $carry[ $item['url'] ] = $item;
            return $carry;
        }, [] ) );

        $post_total = max( count( (array) ( $posts['ids'] ?? [] ) ), (int) ( $posts['total'] ?? 0 ) );
        $total      = 1 + $post_total;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => gmdate( 'c' ),
            'site_url'       => esc_url_raw( home_url( '/' ) ),
            'environment'    => $environment,
            'indexing'       => [
                'allowed'     => ! $is_staging && $blog_public,
                'blog_public' => $blog_public,
                'reason'      => $is_staging ? 'staging_excluded' : ( $blog_public ? 'public' : 'discouraged' ),
            ],
            'robots_url'     => esc_url_raw( home_url( '/robots.txt' ) ),
            'sitemaps'       => self::discover_sitemaps(),
            'urls'           => [
                'total'     => $total,
                'returned'  => count( $urls ),
                'truncated' => $total > count( $urls ),
                'items'     => $urls,
            ],
        ];
    }

    private static function environment(): string {
        if ( class_exists( 'RP_Care_Pipeline_Client' ) ) {
            $value = sanitize_key( (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' ) );
            if ( in_array( $value, [ 'production', 'staging' ], true ) ) return $value;
        }
        if ( function_exists( 'wp_get_environment_type' ) ) {
            $value = sanitize_key( (string) wp_get_environment_type() );
            if ( in_array( $value, [ 'production', 'staging' ], true ) ) return $value;
        }
        return 'production';
    }

    private static function read_posts( int $limit ): array {
        if ( $limit < 1 ) return [ 'ids' => [], 'total' => 0 ];
        if ( is_callable( self::$post_reader ) ) return (array) call_user_func( self::$post_reader, $limit );
        if ( ! class_exists( 'WP_Query' ) ) return [ 'ids' => [], 'total' => 0 ];

        $types = function_exists( 'get_post_types' ) ? (array) get_post_types( [ 'public' => true ], 'names' ) : [ 'post', 'page' ];
        $types = array_values( array_diff( $types, [ 'attachment' ] ) );
        $query = new WP_Query( [
            'post_type'              => $types ?: [ 'post', 'page' ],
            'post_status'            => 'publish',
            'posts_per_page'         => $limit,
            'fields'                 => 'ids',
            'orderby'                => 'modified',
            'order'                  => 'DESC',
            'ignore_sticky_posts'    => true,
            'no_found_rows'          => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ] );
        return [ 'ids' => array_map( 'intval', (array) $query->posts ), 'total' => (int) $query->found_posts ];
    }

    private static function discover_sitemaps(): array {
        $candidates = [ '/sitemap_index.xml', '/wp-sitemap.xml', '/sitemap.xml' ];
        $results    = [];
        foreach ( $candidates as $path ) {
            $url   = esc_url_raw( home_url( $path ) );
            $probe = self::http_get( $url );
            $code  = (int) ( $probe['code'] ?? 0 );
            $body  = (string) ( $probe['body'] ?? '' );
            $entry = [
                'url'       => $url,
                'http_code' => $code,
                'reachable' => $code >= 200 && $code < 300,
                'error'     => sanitize_key( (string) ( $probe['error'] ?? '' ) ),
                'children'  => [],
            ];
            if ( $entry['reachable'] && preg_match_all( '/<loc>\s*(.*?)\s*<\/loc>/is', $body, $matches ) ) {
                foreach ( array_slice( $matches[1], 0, self::MAX_SITEMAPS ) as $child ) {
                    $child = esc_url_raw( html_entity_decode( wp_strip_all_tags( (string) $child ), ENT_QUOTES, 'UTF-8' ) );
                    if ( $child ) $entry['children'][] = $child;
                }
                $entry['children'] = array_values( array_unique( $entry['children'] ) );
            }
            $results[] = $entry;
            if ( $entry['reachable'] ) break;
        }
        return $results;
    }

    private static function http_get( string $url ): array {
        if ( is_callable( self::$http_reader ) ) return (array) call_user_func( self::$http_reader, $url );
        $args = [ 'timeout' => 8, 'redirection' => 3, 'limit_response_size' => 1048576 ];
        $res  = function_exists( 'wp_safe_remote_get' ) ? wp_safe_remote_get( $url, $args ) : wp_remote_get( $url, $args );
        if ( is_wp_error( $res ) ) {
            return [ 'code' => 0, 'body' => '', 'error' => sanitize_key( (string) $res->get_error_code() ) ];
        }
        return [
            'code'  => (int) wp_remote_retrieve_response_code( $res ),
            'body'  => (string) wp_remote_retrieve_body( $res ),
            'error' => '',
        ];
    }
}
