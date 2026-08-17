<?php
/**
 * Care 1.16.2 — /updates/inventory endpoint contract
 *
 * Documents the invariants that Plugin Center depends on for 4-way count
 * reconciliation (WP admin / Care /ping / PC / inventory endpoint).
 *
 * Auth gate:
 *   IN-01  No site_token → 403 (unpaired site cannot query inventory)
 *   IN-02  Token configured, X-Hub-Token header absent → 403
 *   IN-03  Token configured, wrong header → 403
 *   IN-04  Valid token → 200
 *
 * Transient state:
 *   IN-05  Transient absent (false) → plugins_total=null, stale=true, checked_at=null
 *   IN-06  Transient malformed (non-object) → same as absent
 *   IN-07  Transient present but response not an array → plugins_total=null
 *   IN-08  Transient empty (response=[]) → plugins_total=0, plugins=[]
 *
 * Plugin enumeration:
 *   IN-09  Five plugins in response → plugins_total=5, count(plugins)=5
 *   IN-10  Malformed entry (scalar value) in response → skipped, not counted
 *   IN-11  Entry with empty string key → skipped
 *   IN-12  plugins_total always equals count(plugins) when transient is valid
 *
 * Field accuracy:
 *   IN-13  package_available=false when package field empty/absent
 *   IN-14  package_available=true when package field non-empty
 *   IN-15  available_version from new_version field
 *   IN-16  slug from explicit slug field when present
 *   IN-17  slug falls back to dirname(plugin_file) when slug absent
 *   IN-18  name and installed_version populated from plugin_data_reader
 *   IN-19  Orphaned entry (file absent) has installed_version='' and name=''
 *
 * Security:
 *   IN-20  Response never includes package URL value
 *   IN-21  schema_version=1 always present in 200 response
 *
 * What is NOT counted in plugins_total:
 *   IN-22  no_update entries are NOT counted
 *   IN-23  translations entries are NOT counted
 *
 * Ordering and hashing:
 *   IN-24  plugins are sorted alphabetically by plugin_file (deterministic)
 *   IN-25  inventory_hash is 64-char hex (sha256)
 *   IN-26  Same content → same hash (deterministic)
 *   IN-27  Different plugin list → different hash
 *
 * Theme handling:
 *   IN-28  Themes with malformed entries (non-array value) are skipped
 *   IN-29  Valid theme entry fields: theme_file, slug, installed_version, available_version, package_available
 */

declare( strict_types=1 );

require_once __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $s ): string { return htmlspecialchars( $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
    function rest_ensure_response( $response ) {
        if ( $response instanceof WP_REST_Response ) return $response;
        return new WP_REST_Response( $response );
    }
}

require_once __DIR__ . '/../inc/class-update-control.php';
require_once __DIR__ . '/../inc/class-rest.php';

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'RP_Care_Plan' ) ) {
    class RP_Care_Plan {
        public static function get_current(): string { return 'raiz'; }
        public static function get_features( string $plan ): array { return []; }
        public static function get_plan_name( string $plan ): string { return 'Raíz'; }
        public static function get_plan_config( string $plan ): array { return [ 'backup_stale_threshold_h' => 192 ]; }
        public static function count_as_failures_24h(): int { return 0; }
    }
}

class UpdatesInventoryTest extends TestCase {

    private const RAW_TOKEN = 'inventory-test-token-abc123';

    private RP_Care_REST $rest;

    protected function setUp(): void {
        $GLOBALS['_wp_options']  = [];
        $GLOBALS['_rest_routes'] = [];
        RP_Care_Update_Control::$bypass_for_task  = false;
        RP_Care_Update_Control::$transient_reader = null;
        RP_Care_Update_Control::$plugin_data_reader = null;

        $this->rest = new RP_Care_REST();
    }

    protected function tearDown(): void {
        RP_Care_Update_Control::$transient_reader   = null;
        RP_Care_Update_Control::$plugin_data_reader  = null;
        RP_Care_Update_Control::$bypass_for_task     = false;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function validHeader(): string {
        return hash( 'sha256', self::RAW_TOKEN );
    }

    private function makeRequest( ?string $headerToken = null ): WP_REST_Request {
        $req = new WP_REST_Request();
        if ( $headerToken !== null ) {
            $req->set_header( 'X-Hub-Token', $headerToken );
        }
        return $req;
    }

    private function callInventory( ?string $headerToken = null ): WP_REST_Response {
        return $this->rest->hub_updates_inventory( $this->makeRequest( $headerToken ) );
    }

    /** Minimal valid plugin transient object with the given response entries. */
    private function makePluginTransient( array $response, int $lastChecked = 0 ): object {
        $obj               = new stdClass();
        $obj->response     = $response;
        $obj->no_update    = [];
        $obj->translations = [];
        $obj->last_checked = $lastChecked > 0 ? $lastChecked : ( time() - 3600 );
        return $obj;
    }

    /** Minimal valid plugin entry (object form, one update available). */
    private function makeEntry( string $slug, string $newVersion = '2.0', string $package = '' ): object {
        $e              = new stdClass();
        $e->slug        = $slug;
        $e->new_version = $newVersion;
        $e->package     = $package;
        return $e;
    }

    private function setTransient( ?object $plugins, ?object $themes = null ): void {
        $transients = [
            'update_plugins' => $plugins,
            'update_themes'  => $themes,
        ];
        RP_Care_Update_Control::$transient_reader = function( string $key ) use ( $transients ) {
            return $transients[ $key ] ?? false;
        };
    }

    private function setPluginDataReader( array $map ): void {
        RP_Care_Update_Control::$plugin_data_reader = function( string $plugin_file ) use ( $map ) {
            return $map[ $plugin_file ] ?? [ 'Name' => '', 'Version' => '' ];
        };
    }

    // ── IN-01..04: Auth gate ──────────────────────────────────────────────────

    public function test_in01_no_site_token_returns_403(): void {
        // No site_token configured — unpaired site.
        $resp = $this->callInventory( $this->validHeader() );
        $this->assertSame( 403, $resp->get_status(),
            'Unpaired site (no site_token) must return 403 on inventory endpoint' );
    }

    public function test_in02_paired_absent_header_returns_403(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $resp = $this->callInventory(); // no X-Hub-Token header
        $this->assertSame( 403, $resp->get_status() );
    }

    public function test_in03_paired_wrong_header_returns_403(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $resp = $this->callInventory( 'not-the-right-hash' );
        $this->assertSame( 403, $resp->get_status() );
    }

    public function test_in04_valid_token_returns_200(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $resp = $this->callInventory( $this->validHeader() );
        $this->assertSame( 200, $resp->get_status() );
    }

    // ── IN-05..08: Transient state ────────────────────────────────────────────

    public function test_in05_absent_transient_returns_null_total_and_null_checked(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( null ); // reader returns null (not a WP transient false)
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertNull( $data['plugins_total'],  'plugins_total must be null when transient absent' );
        $this->assertNull( $data['checked_at'],     'checked_at must be null when transient absent' );
        $this->assertTrue( $data['stale'],          'stale must be true when transient absent' );
        $this->assertSame( [], $data['plugins'],    'plugins must be empty array' );
    }

    public function test_in06_non_object_transient_treated_as_absent(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        RP_Care_Update_Control::$transient_reader = fn( string $k ) => 'malformed-string';
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertNull( $data['plugins_total'],
            'Non-object transient must be treated same as absent' );
    }

    public function test_in07_transient_without_response_array_returns_null_total(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $obj = new stdClass(); // valid object but no response property
        $this->setTransient( $obj );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertNull( $data['plugins_total'],
            'Transient object without response array must produce plugins_total=null' );
    }

    public function test_in08_empty_response_array_returns_zero_total(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 0, $data['plugins_total'],
            'Empty response[] must produce plugins_total=0' );
        $this->assertSame( [], $data['plugins'] );
    }

    // ── IN-09..12: Plugin enumeration ─────────────────────────────────────────

    public function test_in09_five_plugins_counted_correctly(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'woo/woo.php'       => $this->makeEntry( 'woo' ),
            'yoast/yoast.php'   => $this->makeEntry( 'yoast' ),
            'rocket/rocket.php' => $this->makeEntry( 'rocket' ),
            'acf/acf.php'       => $this->makeEntry( 'acf' ),
            'adbc/adbc.php'     => $this->makeEntry( 'adbc' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 5, $data['plugins_total'] );
        $this->assertCount( 5, $data['plugins'] );
    }

    public function test_in10_malformed_scalar_entry_skipped(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'good/good.php'   => $this->makeEntry( 'good' ),
            'bad/bad.php'     => 'this-is-a-scalar-not-an-object', // malformed
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['plugins_total'],
            'Malformed scalar entry must be skipped and not counted' );
    }

    public function test_in11_empty_string_key_entry_skipped(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        // PHP arrays cannot have literal '' as a string key, but we can force it via cast.
        $entries = [ 'good/good.php' => $this->makeEntry( 'good' ) ];
        $transient = $this->makePluginTransient( $entries );
        // Inject an empty-key entry at the object level to bypass array validation.
        $transient->response[''] = $this->makeEntry( 'empty-key' );
        $this->setTransient( $transient );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['plugins_total'],
            'Entry with empty string key must be skipped' );
    }

    public function test_in12_plugins_total_always_equals_count_plugins(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'aaa/aaa.php' => $this->makeEntry( 'aaa' ),
            'bbb/bbb.php' => $this->makeEntry( 'bbb' ),
            'ccc/ccc.php' => 'bad-scalar',          // skipped
            'ddd/ddd.php' => $this->makeEntry( 'ddd' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame(
            $data['plugins_total'],
            count( $data['plugins'] ),
            'plugins_total must always equal count(plugins)'
        );
    }

    // ── IN-13..19: Field accuracy ─────────────────────────────────────────────

    public function test_in13_package_available_false_when_package_absent(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = $this->makeEntry( 'myplugin', '2.0', '' ); // empty package
        $this->setTransient( $this->makePluginTransient( [ 'mp/mp.php' => $entry ] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertFalse( $data['plugins'][0]['package_available'],
            'package_available must be false when package field is empty' );
    }

    public function test_in14_package_available_true_when_package_present(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = $this->makeEntry( 'freeplugin', '1.1', 'https://downloads.wp.org/plugin/freeplugin.1.1.zip' );
        $this->setTransient( $this->makePluginTransient( [ 'fp/fp.php' => $entry ] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertTrue( $data['plugins'][0]['package_available'],
            'package_available must be true when package field is non-empty' );
    }

    public function test_in15_available_version_from_new_version_field(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = $this->makeEntry( 'plugin', '5.3.1' );
        $this->setTransient( $this->makePluginTransient( [ 'p/p.php' => $entry ] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( '5.3.1', $data['plugins'][0]['available_version'] );
    }

    public function test_in16_slug_from_explicit_field(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry       = $this->makeEntry( 'exact-slug' );
        $this->setTransient( $this->makePluginTransient( [ 'dir/file.php' => $entry ] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 'exact-slug', $data['plugins'][0]['slug'],
            'slug must come from explicit entry->slug field when present' );
    }

    public function test_in17_slug_fallback_to_dirname_when_slug_field_absent(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = new stdClass();
        $entry->new_version = '1.0';
        $entry->package     = '';
        // No slug property set.
        $this->setTransient( $this->makePluginTransient( [ 'myplugin-dir/main.php' => $entry ] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 'myplugin-dir', $data['plugins'][0]['slug'],
            'slug must fall back to dirname(plugin_file) when slug field is absent' );
    }

    public function test_in18_name_and_version_from_plugin_data_reader(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'wc/wc.php' => $this->makeEntry( 'woocommerce' ) ] ) );
        $this->setPluginDataReader( [
            'wc/wc.php' => [ 'Name' => 'WooCommerce', 'Version' => '8.2.1' ],
        ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $plugin = $data['plugins'][0];
        $this->assertSame( 'WooCommerce', $plugin['name'],
            'name must come from plugin_data_reader[Name]' );
        $this->assertSame( '8.2.1', $plugin['installed_version'],
            'installed_version must come from plugin_data_reader[Version]' );
    }

    public function test_in19_orphaned_entry_has_empty_name_and_version(): void {
        // Orphaned = file no longer on disk → plugin_data_reader returns empty strings.
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'orphan/orphan.php' => $this->makeEntry( 'orphan' ) ] ) );
        $this->setPluginDataReader( [] ); // nothing registered → returns all-empty
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $plugin = $data['plugins'][0];
        $this->assertSame( '', $plugin['name'],
            'Orphaned plugin must have name=""' );
        $this->assertSame( '', $plugin['installed_version'],
            'Orphaned plugin must have installed_version=""' );
        $this->assertSame( 1, $data['plugins_total'],
            'Orphaned entry is still counted in plugins_total (raw transient)' );
    }

    // ── IN-20..21: Security ───────────────────────────────────────────────────

    public function test_in20_response_never_contains_package_url(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = $this->makeEntry( 'fp', '1.0', 'https://secret-download-url.example.com/fp.zip' );
        $this->setTransient( $this->makePluginTransient( [ 'fp/fp.php' => $entry ] ) );
        $body = json_encode( $this->callInventory( $this->validHeader() )->get_data() );
        $this->assertStringNotContainsString(
            'https://secret-download-url.example.com/fp.zip',
            $body,
            'Package download URL must never appear in the response body'
        );
    }

    public function test_in21_schema_version_is_1(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['schema_version'],
            'schema_version must always be 1' );
    }

    // ── IN-22..23: What is NOT counted ────────────────────────────────────────

    public function test_in22_no_update_entries_not_counted(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $obj = $this->makePluginTransient( [ 'update/update.php' => $this->makeEntry( 'update' ) ] );
        // Add no_update entries — these must NOT inflate plugins_total.
        $obj->no_update = [
            'noupdate1/n1.php' => (object) [ 'slug' => 'n1' ],
            'noupdate2/n2.php' => (object) [ 'slug' => 'n2' ],
            'noupdate3/n3.php' => (object) [ 'slug' => 'n3' ],
        ];
        $this->setTransient( $obj );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['plugins_total'],
            'no_update entries must not be counted in plugins_total' );
    }

    public function test_in23_translations_not_counted_in_plugins_total(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $obj = $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p' ) ] );
        $obj->translations = [
            [ 'slug' => 'p', 'language' => 'es_ES', 'version' => '1.0', 'package' => 'http://...' ],
            [ 'slug' => 'p', 'language' => 'ca',    'version' => '1.0', 'package' => 'http://...' ],
        ];
        $this->setTransient( $obj );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['plugins_total'],
            'translations[] must not be counted in plugins_total' );
    }

    // ── IN-24..27: Ordering and hashing ──────────────────────────────────────

    public function test_in24_plugins_sorted_alphabetically_by_plugin_file(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        // Insert in reverse alpha order; expect sorted output.
        $entries = [
            'zzz/z.php' => $this->makeEntry( 'zzz' ),
            'aaa/a.php' => $this->makeEntry( 'aaa' ),
            'mmm/m.php' => $this->makeEntry( 'mmm' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $files = array_column( $data['plugins'], 'plugin_file' );
        $this->assertSame( [ 'aaa/a.php', 'mmm/m.php', 'zzz/z.php' ], $files,
            'plugins must be sorted alphabetically by plugin_file' );
    }

    public function test_in25_inventory_hash_is_64_char_hex(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{64}$/',
            $data['inventory_hash'],
            'inventory_hash must be a 64-character lowercase hex string (sha256)'
        );
    }

    public function test_in26_same_content_produces_same_hash(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $transient = $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p', '1.0' ) ] );
        $this->setTransient( $transient );
        $hash1 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];
        $this->setTransient( $transient );
        $hash2 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];
        $this->assertSame( $hash1, $hash2,
            'Same plugin content must produce identical inventory_hash (deterministic)' );
    }

    public function test_in27_different_content_produces_different_hash(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'p1/p1.php' => $this->makeEntry( 'p1' ) ] ) );
        $hash1 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];

        $this->setTransient( $this->makePluginTransient( [ 'p2/p2.php' => $this->makeEntry( 'p2' ) ] ) );
        $hash2 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];

        $this->assertNotSame( $hash1, $hash2,
            'Different plugin lists must produce different inventory_hash values' );
    }

    // ── IN-28..29: Theme handling ──────────────────────────────────────────────

    public function test_in28_malformed_theme_entries_skipped(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $plugins = $this->makePluginTransient( [] );
        $themes  = new stdClass();
        $themes->response = [
            'good-theme' => [ 'new_version' => '2.0', 'Version' => '1.0', 'package' => '' ],
            'bad-theme'  => 'scalar-not-array', // malformed
        ];
        $this->setTransient( $plugins, $themes );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['themes_total'],
            'Malformed theme entries (non-array) must be skipped' );
    }

    public function test_in29_valid_theme_entry_has_required_fields(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $plugins = $this->makePluginTransient( [] );
        $themes  = new stdClass();
        $themes->response = [
            'storefront' => [
                'Version'     => '4.5.0',
                'new_version' => '4.6.0',
                'package'     => 'https://downloads.wp.org/theme/storefront.4.6.0.zip',
            ],
        ];
        $this->setTransient( $plugins, $themes );
        $data  = $this->callInventory( $this->validHeader() )->get_data();
        $theme = $data['themes'][0];

        $this->assertSame( 1, $data['themes_total'] );
        $this->assertSame( 'storefront', $theme['theme_file'] );
        $this->assertSame( 'storefront', $theme['slug'] );
        $this->assertSame( '4.5.0', $theme['installed_version'] );
        $this->assertSame( '4.6.0', $theme['available_version'] );
        $this->assertTrue( $theme['package_available'],
            'Theme with non-empty package must have package_available=true' );
        // The actual URL must NOT appear in the response.
        $body = json_encode( $data );
        $this->assertStringNotContainsString(
            'https://downloads.wp.org/theme/storefront.4.6.0.zip',
            $body,
            'Theme package URL must never appear in the response body'
        );
    }
}
