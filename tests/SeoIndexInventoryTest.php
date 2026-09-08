<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'get_permalink' ) ) {
    function get_permalink( $id ) { return 'http://localhost/post-' . (int) $id . '/'; }
}
if ( ! function_exists( 'get_post_type' ) ) {
    function get_post_type( $id ) { return 2 === (int) $id ? 'page' : 'post'; }
}
if ( ! function_exists( 'get_post_modified_time' ) ) {
    function get_post_modified_time( $format, $gmt, $id ) { return '2026-09-08T10:00:0' . (int) $id . '+00:00'; }
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
}

if ( ! class_exists( 'RP_Care_Pipeline_Client' ) ) {
    class RP_Care_Pipeline_Client { public const OPT_ENVIRONMENT = 'rpcare_pipeline_environment'; }
}

require_once __DIR__ . '/../inc/class-seo-index-inventory.php';

class SeoIndexInventoryTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['_wp_options'] = [ 'blog_public' => 1, 'rpcare_pipeline_environment' => 'production' ];
        RP_Care_SEO_Index_Inventory::$post_reader = static fn( $limit ) => [ 'ids' => [ 1, 2, 3 ], 'total' => 12 ];
        RP_Care_SEO_Index_Inventory::$http_reader = static function ( $url ) {
            if ( str_ends_with( $url, '/sitemap_index.xml' ) ) {
                return [
                    'code' => 200,
                    'body' => '<sitemapindex><sitemap><loc>http://localhost/post-sitemap.xml</loc></sitemap></sitemapindex>',
                    'error' => '',
                ];
            }
            return [ 'code' => 404, 'body' => '', 'error' => '' ];
        };
    }

    public function test_production_inventory_is_bounded_and_canonical(): void {
        $inventory = RP_Care_SEO_Index_Inventory::build( 3 );
        $this->assertSame( 1, $inventory['schema_version'] );
        $this->assertTrue( $inventory['indexing']['allowed'] );
        $this->assertSame( 13, $inventory['urls']['total'] );
        $this->assertSame( 3, $inventory['urls']['returned'] );
        $this->assertTrue( $inventory['urls']['truncated'] );
        $this->assertCount( 1, $inventory['sitemaps'] );
        $this->assertSame( 'http://localhost/post-sitemap.xml', $inventory['sitemaps'][0]['children'][0] );
    }

    public function test_staging_is_never_indexable(): void {
        $GLOBALS['_wp_options']['rpcare_pipeline_environment'] = 'staging';
        $inventory = RP_Care_SEO_Index_Inventory::build( 2 );
        $this->assertFalse( $inventory['indexing']['allowed'] );
        $this->assertSame( 'staging_excluded', $inventory['indexing']['reason'] );
    }

    public function test_blog_public_off_is_reported_fail_closed(): void {
        $GLOBALS['_wp_options']['blog_public'] = 0;
        $inventory = RP_Care_SEO_Index_Inventory::build( 2 );
        $this->assertFalse( $inventory['indexing']['allowed'] );
        $this->assertSame( 'discouraged', $inventory['indexing']['reason'] );
    }
}
