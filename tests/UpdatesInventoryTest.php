<?php
/**
 * Care 1.16.2 — /updates/inventory endpoint contract (schema_version=2)
 *
 * Validates the canonical inventory endpoint that cross-references the raw
 * update_plugins transient with get_plugins() to separate actionable entries
 * (plugin still installed on disk) from orphaned entries (stale transient / slug mismatch).
 *
 * The 4-way count reconciliation test (IN-30..IN-35) reproduces exactly the
 * observed discrepancy on dev.banbancosmetics.com:
 *   WP admin=4  Care /ping=5 (old) → Care /ping=4 (fixed) → PC=4
 *
 * Auth gate:
 *   IN-01  No site_token → 403
 *   IN-02  Token present, header absent → 403
 *   IN-03  Token present, wrong header → 403
 *   IN-04  Valid token → 200
 *
 * Transient state:
 *   IN-05  Transient absent → raw_total=null, stale=true, checked_at=null
 *   IN-06  Non-object transient → same as absent
 *   IN-07  Object without response array → raw_total=null
 *   IN-08  Empty response[] → raw_total=0, plugins=[], orphaned_plugins=[]
 *
 * Plugin enumeration:
 *   IN-09  5 entries, all installed → raw_total=5, actionable_total=5, orphaned_total=0
 *   IN-10  Malformed (scalar) entry → skipped, not counted
 *   IN-11  Empty-string key → skipped
 *   IN-12  actionable_total always equals count(plugins)
 *   IN-13  orphaned_total always equals count(orphaned_plugins)
 *
 * Field accuracy — installed plugin:
 *   IN-14  package_available=false when package absent
 *   IN-15  package_available=true when package present
 *   IN-16  available_version from new_version field
 *   IN-17  slug from explicit field; dirname fallback when absent
 *   IN-18  name and installed_version from get_plugins() map
 *   IN-19  installed=true, orphaned=false for installed entries
 *
 * Field accuracy — orphaned plugin:
 *   IN-20  Orphaned entry: installed=false, orphaned=true
 *   IN-21  Orphaned entry: name='', installed_version=''
 *   IN-22  Orphaned entry still counted in raw_total
 *   IN-23  Orphaned entry NOT in plugins[], IS in orphaned_plugins[]
 *
 * Security:
 *   IN-24  Response never includes package download URL value
 *   IN-25  schema_version=2 always present
 *
 * What is NOT counted in actionable_total:
 *   IN-26  no_update[] entries excluded from all totals
 *   IN-27  translations[] excluded from all totals
 *
 * Ordering and hashing:
 *   IN-28  plugins[] sorted alphabetically by plugin_file
 *   IN-29  inventory_hash is 64-char hex; same content → same hash; different → different
 *
 * Theme handling:
 *   IN-30 (was IN-28)  Malformed theme entry skipped
 *
 * ── CANONICAL 4-WAY COUNT RECONCILIATION (reproduces dev.banban 5→4 scenario) ──
 *   IN-31  5 response[] entries, 4 installed (1 orphaned): raw=5, actionable=4, orphaned=1
 *   IN-32  hub_ping() returns updates_pending_total=4 (actionable, not raw)
 *   IN-33  hub_ping() returns updates_pending=4 (alias, same value as total)
 *   IN-34  hub_ping() returns updates_orphaned=1
 *   IN-35  inventory endpoint separates correctly: 4 in plugins[], 1 in orphaned_plugins[]
 *
 * Installed identity list (premium providers without a standard transient):
 *   IN-36  all installed plugins are exposed even when response[] is empty
 *   IN-37  installed list is sorted and contains only safe identity metadata
 *   IN-38  inventory hash changes when an installed-only plugin version changes
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
require_once __DIR__ . '/../inc/class-inventory-snapshot.php';
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
        $GLOBALS['_wp_options']                = [];
        $GLOBALS['_rest_routes']               = [];
        $GLOBALS['_wp_installed_plugins_mock'] = [];
        RP_Care_Update_Control::$bypass_for_task   = false;
        RP_Care_Update_Control::$transient_reader  = null;
        RP_Care_Update_Control::$plugin_data_reader = null;
        RP_Care_Update_Control::$get_plugins_reader = null;
        RP_Care_REST::$inventory_refresher = null;

        $this->rest = new RP_Care_REST();
    }

    protected function tearDown(): void {
        RP_Care_Update_Control::$transient_reader  = null;
        RP_Care_Update_Control::$plugin_data_reader = null;
        RP_Care_Update_Control::$get_plugins_reader = null;
        RP_Care_Update_Control::$bypass_for_task    = false;
        RP_Care_REST::$inventory_refresher = null;
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

    private function callPing( ?string $headerToken = null ): WP_REST_Response {
        return $this->rest->hub_ping( $this->makeRequest( $headerToken ) );
    }

    /** Returns a minimal valid plugin transient object. */
    private function makePluginTransient( array $response, int $lastChecked = 0 ): object {
        $obj               = new stdClass();
        $obj->response     = $response;
        $obj->no_update    = [];
        $obj->translations = [];
        $obj->last_checked = $lastChecked > 0 ? $lastChecked : ( time() - 3600 );
        return $obj;
    }

    /** Returns a minimal valid plugin transient entry. */
    private function makeEntry( string $slug, string $newVersion = '2.0', string $package = '' ): object {
        $e              = new stdClass();
        $e->slug        = $slug;
        $e->new_version = $newVersion;
        $e->package     = $package;
        return $e;
    }

    /**
     * Configure the transient reader seam.
     * Pass null for themes to return false (absent).
     */
    private function setTransient( ?object $plugins, ?object $themes = null ): void {
        $transients = [
            'update_plugins' => $plugins,
            'update_themes'  => $themes,
        ];
        RP_Care_Update_Control::$transient_reader = function( string $key ) use ( $transients ) {
            return $transients[ $key ] ?? false;
        };
    }

    /**
     * Configure the get_plugins seam.
     * $map = [ 'plugin/plugin.php' => ['Name' => '...', 'Version' => '...'] ]
     */
    private function setInstalledPlugins( array $map ): void {
        RP_Care_Update_Control::$get_plugins_reader = fn() => $map;
    }

    // ── IN-01..04: Auth gate ──────────────────────────────────────────────────

    public function test_in01_no_site_token_returns_403(): void {
        $resp = $this->callInventory( $this->validHeader() );
        $this->assertSame( 403, $resp->get_status() );
    }

    public function test_in02_paired_absent_header_returns_403(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->assertSame( 403, $this->callInventory()->get_status() );
    }

    public function test_in03_paired_wrong_header_returns_403(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->assertSame( 403, $this->callInventory( 'not-the-right-hash' )->get_status() );
    }

    public function test_in04_valid_token_returns_200(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->assertSame( 200, $this->callInventory( $this->validHeader() )->get_status() );
    }

    // ── IN-05..08: Transient state ────────────────────────────────────────────

    public function test_in05_absent_transient_returns_null_totals(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( null );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertNull( $data['raw_total'],        'raw_total must be null when transient absent' );
        $this->assertNull( $data['actionable_total'], 'actionable_total must be null' );
        $this->assertNull( $data['checked_at'],       'checked_at must be null' );
        $this->assertTrue( $data['stale'],            'stale must be true' );
        $this->assertSame( [], $data['plugins'],      'plugins must be []' );
        $this->assertSame( [], $data['orphaned_plugins'], 'orphaned_plugins must be []' );
    }

    public function test_in06_non_object_transient_treated_as_absent(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        RP_Care_Update_Control::$transient_reader = fn( string $k ) => 'malformed-string';
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertNull( $data['raw_total'] );
    }

    public function test_in07_transient_object_without_response_array_returns_null(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $transient = new stdClass();
        $transient->last_checked = time();
        $this->setTransient( $transient );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertNull( $data['raw_total'] );
        $this->assertNull( $data['actionable_total'] );
        $this->assertTrue( $data['stale'], 'A recent timestamp cannot make a missing response[] inventory fresh.' );
    }

    public function test_in08_empty_response_returns_zero_totals(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $this->setInstalledPlugins( [] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 0, $data['raw_total'] );
        $this->assertSame( 0, $data['actionable_total'] );
        $this->assertSame( 0, $data['orphaned_total'] );
        $this->assertSame( [], $data['plugins'] );
        $this->assertSame( [], $data['orphaned_plugins'] );
    }

    // ── IN-09..13: Plugin enumeration ─────────────────────────────────────────

    public function test_in09_five_installed_plugins_all_actionable(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'a/a.php' => $this->makeEntry( 'a' ),
            'b/b.php' => $this->makeEntry( 'b' ),
            'c/c.php' => $this->makeEntry( 'c' ),
            'd/d.php' => $this->makeEntry( 'd' ),
            'e/e.php' => $this->makeEntry( 'e' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $this->setInstalledPlugins( [
            'a/a.php' => [ 'Name' => 'A', 'Version' => '1.0' ],
            'b/b.php' => [ 'Name' => 'B', 'Version' => '1.0' ],
            'c/c.php' => [ 'Name' => 'C', 'Version' => '1.0' ],
            'd/d.php' => [ 'Name' => 'D', 'Version' => '1.0' ],
            'e/e.php' => [ 'Name' => 'E', 'Version' => '1.0' ],
        ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 5, $data['raw_total'] );
        $this->assertSame( 5, $data['actionable_total'] );
        $this->assertSame( 0, $data['orphaned_total'] );
        $this->assertCount( 5, $data['plugins'] );
        $this->assertCount( 0, $data['orphaned_plugins'] );
    }

    public function test_in10_malformed_scalar_entry_skipped(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'good/good.php' => $this->makeEntry( 'good' ),
            'bad/bad.php'   => 'this-is-a-scalar',
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $this->setInstalledPlugins( [ 'good/good.php' => [ 'Name' => 'Good', 'Version' => '1.0' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['raw_total'],        'Malformed entry must be skipped' );
        $this->assertSame( 1, $data['actionable_total'] );
    }

    public function test_in11_empty_string_key_skipped(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $obj           = $this->makePluginTransient( [ 'good/g.php' => $this->makeEntry( 'good' ) ] );
        $obj->response[''] = $this->makeEntry( 'empty-key' );
        $this->setTransient( $obj );
        $this->setInstalledPlugins( [ 'good/g.php' => [ 'Name' => 'Good', 'Version' => '1.0' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['raw_total'], 'Empty-string key must be skipped' );
    }

    public function test_in12_actionable_total_equals_count_plugins(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'aaa/a.php' => $this->makeEntry( 'aaa' ),
            'bbb/b.php' => $this->makeEntry( 'bbb' ),
            'ccc/c.php' => 'bad-scalar',
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $this->setInstalledPlugins( [
            'aaa/a.php' => [ 'Name' => 'AAA', 'Version' => '1' ],
            'bbb/b.php' => [ 'Name' => 'BBB', 'Version' => '1' ],
        ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( $data['actionable_total'], count( $data['plugins'] ),
            'actionable_total must equal count(plugins)' );
    }

    public function test_in13_orphaned_total_equals_count_orphaned_plugins(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'installed/i.php' => $this->makeEntry( 'installed' ),
            'orphan/o.php'    => $this->makeEntry( 'orphan' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $this->setInstalledPlugins( [ 'installed/i.php' => [ 'Name' => 'Installed', 'Version' => '1' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( $data['orphaned_total'], count( $data['orphaned_plugins'] ),
            'orphaned_total must equal count(orphaned_plugins)' );
    }

    // ── IN-14..19: Field accuracy — installed ─────────────────────────────────

    public function test_in14_package_available_false_when_absent(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p', '2.0', '' ) ] ) );
        $this->setInstalledPlugins( [ 'p/p.php' => [ 'Name' => 'P', 'Version' => '1.0' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertFalse( $data['plugins'][0]['package_available'] );
    }

    public function test_in15_package_available_true_when_present(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = $this->makeEntry( 'fp', '1.1', 'https://downloads.wp.org/plugin/fp.zip' );
        $this->setTransient( $this->makePluginTransient( [ 'fp/fp.php' => $entry ] ) );
        $this->setInstalledPlugins( [ 'fp/fp.php' => [ 'Name' => 'Free Plugin', 'Version' => '1.0' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertTrue( $data['plugins'][0]['package_available'] );
    }

    public function test_in16_available_version_from_new_version(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p', '5.3.1' ) ] ) );
        $this->setInstalledPlugins( [ 'p/p.php' => [ 'Name' => 'P', 'Version' => '5.0.0' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( '5.3.1', $data['plugins'][0]['available_version'] );
    }

    public function test_in17_slug_explicit_field_and_dirname_fallback(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $withSlug    = $this->makeEntry( 'explicit-slug' );
        $withoutSlug = new stdClass();
        $withoutSlug->new_version = '1.0';
        $withoutSlug->package     = '';
        $entries = [ 'dir-a/main.php' => $withSlug, 'dir-b/main.php' => $withoutSlug ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $this->setInstalledPlugins( [
            'dir-a/main.php' => [ 'Name' => 'A', 'Version' => '1' ],
            'dir-b/main.php' => [ 'Name' => 'B', 'Version' => '1' ],
        ] );
        $data    = $this->callInventory( $this->validHeader() )->get_data();
        $slugMap = array_column( $data['plugins'], 'slug', 'plugin_file' );
        $this->assertSame( 'explicit-slug', $slugMap['dir-a/main.php'],
            'Explicit slug field must be used when present' );
        $this->assertSame( 'dir-b', $slugMap['dir-b/main.php'],
            'Slug must fall back to dirname when absent' );
    }

    public function test_in18_name_and_version_from_installed_map(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'wc/wc.php' => $this->makeEntry( 'woocommerce' ) ] ) );
        $this->setInstalledPlugins( [ 'wc/wc.php' => [ 'Name' => 'WooCommerce', 'Version' => '8.2.1' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 'WooCommerce', $data['plugins'][0]['name'] );
        $this->assertSame( '8.2.1', $data['plugins'][0]['installed_version'] );
    }

    public function test_in19_installed_flags_on_actionable_entry(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p' ) ] ) );
        $this->setInstalledPlugins( [ 'p/p.php' => [ 'Name' => 'P', 'Version' => '1' ] ] );
        $data   = $this->callInventory( $this->validHeader() )->get_data();
        $plugin = $data['plugins'][0];
        $this->assertTrue( $plugin['installed'], 'installed must be true for actionable entry' );
        $this->assertFalse( $plugin['orphaned'], 'orphaned must be false for actionable entry' );
    }

    // ── IN-20..23: Field accuracy — orphaned ──────────────────────────────────

    public function test_in20_orphaned_flags_on_orphaned_entry(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'orphan/o.php' => $this->makeEntry( 'orphan' ) ] ) );
        $this->setInstalledPlugins( [] ); // nothing installed
        $data    = $this->callInventory( $this->validHeader() )->get_data();
        $orphan  = $data['orphaned_plugins'][0];
        $this->assertFalse( $orphan['installed'], 'installed must be false for orphaned entry' );
        $this->assertTrue( $orphan['orphaned'],   'orphaned must be true for orphaned entry' );
    }

    public function test_in21_orphaned_entry_has_empty_name_and_version(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'orphan/o.php' => $this->makeEntry( 'orphan' ) ] ) );
        $this->setInstalledPlugins( [] );
        $data   = $this->callInventory( $this->validHeader() )->get_data();
        $orphan = $data['orphaned_plugins'][0];
        $this->assertSame( '', $orphan['name'],              'Orphaned name must be empty' );
        $this->assertSame( '', $orphan['installed_version'], 'Orphaned installed_version must be empty' );
    }

    public function test_in22_orphaned_entry_counted_in_raw_total(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'orphan/o.php' => $this->makeEntry( 'orphan' ) ] ) );
        $this->setInstalledPlugins( [] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['raw_total'],       'Orphaned entry IS counted in raw_total' );
        $this->assertSame( 0, $data['actionable_total'], 'Orphaned entry is NOT in actionable_total' );
        $this->assertSame( 1, $data['orphaned_total'] );
    }

    public function test_in23_orphaned_entry_in_orphaned_plugins_not_plugins(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [ 'orphan/o.php' => $this->makeEntry( 'orphan' ) ] ) );
        $this->setInstalledPlugins( [] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertCount( 0, $data['plugins'],          'Orphaned entry must NOT appear in plugins[]' );
        $this->assertCount( 1, $data['orphaned_plugins'], 'Orphaned entry must appear in orphaned_plugins[]' );
    }

    // ── IN-24..25: Security ───────────────────────────────────────────────────

    public function test_in24_package_url_never_in_response(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entry = $this->makeEntry( 'fp', '1.0', 'https://secret-download-url.example.com/fp.zip' );
        $this->setTransient( $this->makePluginTransient( [ 'fp/fp.php' => $entry ] ) );
        $this->setInstalledPlugins( [ 'fp/fp.php' => [ 'Name' => 'Free Plugin', 'Version' => '1.0' ] ] );
        $body = json_encode( $this->callInventory( $this->validHeader() )->get_data() );
        $this->assertStringNotContainsString(
            'https://secret-download-url.example.com/fp.zip',
            $body,
            'Package URL must never appear in response body'
        );
    }

    public function test_in25_schema_version_is_2(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $this->setInstalledPlugins( [] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 2, $data['schema_version'],
            'schema_version must be 2 (added orphaned_plugins separation)' );
    }

    // ── IN-26..27: Exclusions ─────────────────────────────────────────────────

    public function test_in26_no_update_entries_not_counted(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $obj           = $this->makePluginTransient( [ 'real/r.php' => $this->makeEntry( 'real' ) ] );
        $obj->no_update = [
            'n1/n1.php' => (object) [ 'slug' => 'n1' ],
            'n2/n2.php' => (object) [ 'slug' => 'n2' ],
        ];
        $this->setTransient( $obj );
        $this->setInstalledPlugins( [ 'real/r.php' => [ 'Name' => 'Real', 'Version' => '1' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['raw_total'], 'no_update entries must not be counted' );
    }

    public function test_in27_translations_not_counted(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $obj = $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p' ) ] );
        $obj->translations = [
            [ 'slug' => 'p', 'language' => 'es_ES', 'package' => 'http://...' ],
            [ 'slug' => 'p', 'language' => 'ca',    'package' => 'http://...' ],
        ];
        $this->setTransient( $obj );
        $this->setInstalledPlugins( [ 'p/p.php' => [ 'Name' => 'P', 'Version' => '1' ] ] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['raw_total'], 'translations must not be counted' );
    }

    // ── IN-28..29: Ordering and hashing ───────────────────────────────────────

    public function test_in28_plugins_sorted_by_plugin_file(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $entries = [
            'zzz/z.php' => $this->makeEntry( 'zzz' ),
            'aaa/a.php' => $this->makeEntry( 'aaa' ),
            'mmm/m.php' => $this->makeEntry( 'mmm' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );
        $this->setInstalledPlugins( [
            'zzz/z.php' => [ 'Name' => 'Z', 'Version' => '1' ],
            'aaa/a.php' => [ 'Name' => 'A', 'Version' => '1' ],
            'mmm/m.php' => [ 'Name' => 'M', 'Version' => '1' ],
        ] );
        $data  = $this->callInventory( $this->validHeader() )->get_data();
        $files = array_column( $data['plugins'], 'plugin_file' );
        $this->assertSame( [ 'aaa/a.php', 'mmm/m.php', 'zzz/z.php' ], $files );
    }

    public function test_in29_inventory_hash_deterministic_and_unique(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );

        // Same content → same hash
        $t = $this->makePluginTransient( [ 'p/p.php' => $this->makeEntry( 'p', '1.0' ) ] );
        $this->setTransient( $t );
        $this->setInstalledPlugins( [ 'p/p.php' => [ 'Name' => 'P', 'Version' => '1' ] ] );
        $hash1 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];
        $this->setTransient( $t );
        $this->setInstalledPlugins( [ 'p/p.php' => [ 'Name' => 'P', 'Version' => '1' ] ] );
        $hash2 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];
        $this->assertSame( $hash1, $hash2, 'Same content → same hash (deterministic)' );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $hash1, 'Hash must be 64-char hex' );

        // Different content → different hash
        $this->setTransient( $this->makePluginTransient( [ 'q/q.php' => $this->makeEntry( 'q', '2.0' ) ] ) );
        $this->setInstalledPlugins( [ 'q/q.php' => [ 'Name' => 'Q', 'Version' => '1' ] ] );
        $hash3 = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];
        $this->assertNotSame( $hash1, $hash3, 'Different content → different hash' );
    }

    public function test_in36_installed_plugins_include_entries_without_standard_update(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $this->setInstalledPlugins( [
            'advanced-database-cleaner-pro/advanced-db-cleaner.php' => [
                'Name' => 'Advanced Database Cleaner PRO', 'Version' => '3.2.10',
            ],
            'plain/plain.php' => [ 'Name' => 'Plain', 'Version' => '1.0.0' ],
        ] );

        $data = $this->callInventory( $this->validHeader() )->get_data();

        $this->assertSame( 0, $data['actionable_total'] );
        $this->assertSame( 2, $data['installed_plugins_total'] );
        $this->assertCount( 2, $data['installed_plugins'] );
        $this->assertSame(
            'advanced-database-cleaner-pro/advanced-db-cleaner.php',
            $data['installed_plugins'][0]['plugin_file']
        );
    }

    public function test_in37_installed_plugins_are_sorted_and_metadata_is_minimal(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $this->setInstalledPlugins( [
            'z/z.php' => [ 'Name' => 'Zulu', 'Version' => '9.0', 'Author' => 'Secret-ish extra' ],
            'a/a.php' => [ 'Name' => 'Alpha', 'Version' => '1.0' ],
        ] );

        $items = $this->callInventory( $this->validHeader() )->get_data()['installed_plugins'];
        $this->assertSame( [ 'a/a.php', 'z/z.php' ], array_column( $items, 'plugin_file' ) );
        $this->assertSame(
            [ 'plugin_file', 'slug', 'name', 'installed_version' ],
            array_keys( $items[0] )
        );
        $this->assertStringNotContainsString( 'Secret-ish', wp_json_encode( $items ) );
    }

    public function test_in38_inventory_hash_binds_installed_only_version(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( $this->makePluginTransient( [] ) );
        $this->setInstalledPlugins( [ 'premium/premium.php' => [ 'Name' => 'Premium', 'Version' => '1.0.0' ] ] );
        $first = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];

        $this->setInstalledPlugins( [ 'premium/premium.php' => [ 'Name' => 'Premium', 'Version' => '1.0.1' ] ] );
        $second = $this->callInventory( $this->validHeader() )->get_data()['inventory_hash'];

        $this->assertNotSame( $first, $second );
    }

    public function test_installed_state_hash_ignores_update_transient_lifecycle(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        update_option( 'active_plugins', [ 'premium/premium.php' ] );
        $this->setInstalledPlugins( [ 'premium/premium.php' => [ 'Name' => 'Premium', 'Version' => '1.0.0' ] ] );

        $this->setTransient( $this->makePluginTransient( [
            'premium/premium.php' => $this->makeEntry( 'premium', '1.1.0' ),
        ] ) );
        $fresh = $this->callInventory( $this->validHeader() )->get_data();

        $this->setTransient( null, null );
        $expired = $this->callInventory( $this->validHeader() )->get_data();

        $this->assertNotSame( $fresh['inventory_hash'], $expired['inventory_hash'] );
        $this->assertSame( $fresh['installed_state_hash'], $expired['installed_state_hash'] );
    }

    public function test_installed_state_hash_changes_with_version_or_activation(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( null, null );
        $this->setInstalledPlugins( [ 'premium/premium.php' => [ 'Name' => 'Premium', 'Version' => '1.0.0' ] ] );
        update_option( 'active_plugins', [] );
        $inactive = $this->callInventory( $this->validHeader() )->get_data()['installed_state_hash'];

        update_option( 'active_plugins', [ 'premium/premium.php' ] );
        $active = $this->callInventory( $this->validHeader() )->get_data()['installed_state_hash'];
        $this->assertNotSame( $inactive, $active );

        $this->setInstalledPlugins( [ 'premium/premium.php' => [ 'Name' => 'Premium', 'Version' => '1.0.1' ] ] );
        $updated = $this->callInventory( $this->validHeader() )->get_data()['installed_state_hash'];
        $this->assertNotSame( $active, $updated );
    }

    public function test_installed_state_hash_excludes_care_control_plane_version(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setTransient( null, null );
        update_option( 'active_plugins', [ 'replanta-care/replanta-care.php', 'app/app.php' ] );
        $this->setInstalledPlugins( [
            'replanta-care/replanta-care.php' => [ 'Name' => 'Replanta Care', 'Version' => '1.16.35' ],
            'app/app.php'                     => [ 'Name' => 'App', 'Version' => '1.0.0' ],
        ] );
        $before = $this->callInventory( $this->validHeader() )->get_data()['installed_state_hash'];

        $this->setInstalledPlugins( [
            'replanta-care/replanta-care.php' => [ 'Name' => 'Replanta Care', 'Version' => '1.16.36' ],
            'app/app.php'                     => [ 'Name' => 'App', 'Version' => '1.0.0' ],
        ] );
        $after = $this->callInventory( $this->validHeader() )->get_data()['installed_state_hash'];
        $this->assertSame( $before, $after );
    }

    // ── IN-30: Theme handling ─────────────────────────────────────────────────

    public function test_in30_malformed_theme_entry_skipped(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $plugins = $this->makePluginTransient( [] );
        $themes  = new stdClass();
        $themes->response = [
            'good-theme' => [ 'new_version' => '2.0', 'Version' => '1.0', 'package' => '' ],
            'bad-theme'  => 'scalar-not-array',
        ];
        $this->setTransient( $plugins, $themes );
        $this->setInstalledPlugins( [] );
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['themes_total'], 'Malformed theme entry must be skipped' );
    }

    // ── IN-31..35: CANONICAL 4-WAY COUNT RECONCILIATION ──────────────────────
    //
    // Reproduces the exact dev.banbancosmetics.com scenario:
    //   transient response[] = 5 entries
    //   get_plugins() = 4 entries (one slug is orphaned — slug-mismatch / uninstalled)
    //   Expected: WP admin=4, Care /ping=4, PC=4, inventory raw=5/actionable=4/orphaned=1

    private function setupBanbanScenario(): void {
        $entries = [
            'woocommerce/woocommerce.php'                         => $this->makeEntry( 'woocommerce', '9.0' ),
            'elementor-pro/elementor-pro.php'                     => $this->makeEntry( 'elementor-pro', '3.25' ),
            'yoast-seo-premium/yoast-seo-premium.php'            => $this->makeEntry( 'yoast-seo-premium', '23.0' ),
            'wpml-multilingual-cms/sitepress.php'                 => $this->makeEntry( 'wpml-multilingual-cms', '4.7' ),
            // ORPHANED: transient registered this slug but it is NOT installed on disk
            'advanced-db-cleaner/advanced-db-cleaner.php'        => $this->makeEntry( 'advanced-db-cleaner', '3.5' ),
        ];
        $this->setTransient( $this->makePluginTransient( $entries ) );

        // get_plugins() only returns the 4 actually installed directories
        $this->setInstalledPlugins( [
            'woocommerce/woocommerce.php'                         => [ 'Name' => 'WooCommerce',       'Version' => '8.9' ],
            'elementor-pro/elementor-pro.php'                     => [ 'Name' => 'Elementor Pro',     'Version' => '3.24' ],
            'yoast-seo-premium/yoast-seo-premium.php'            => [ 'Name' => 'Yoast SEO Premium', 'Version' => '22.9' ],
            'wpml-multilingual-cms/sitepress.php'                 => [ 'Name' => 'WPML',              'Version' => '4.6' ],
            // advanced-db-cleaner NOT here — orphaned entry
        ] );
    }

    public function test_in31_canonical_5_transient_4_installed_1_orphaned(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setupBanbanScenario();
        $data = $this->callInventory( $this->validHeader() )->get_data();
        $this->assertSame( 5, $data['raw_total'],        'raw_total must count all 5 transient entries' );
        $this->assertSame( 4, $data['actionable_total'], 'actionable_total must count only installed (4)' );
        $this->assertSame( 4, $data['installed_total'],  'installed_total must be 4' );
        $this->assertSame( 1, $data['orphaned_total'],   'orphaned_total must be 1 (advanced-db-cleaner)' );
        $this->assertCount( 4, $data['plugins'],          'plugins[] must have 4 entries' );
        $this->assertCount( 1, $data['orphaned_plugins'], 'orphaned_plugins[] must have 1 entry' );
        $this->assertSame(
            'advanced-db-cleaner/advanced-db-cleaner.php',
            $data['orphaned_plugins'][0]['plugin_file'],
            'Orphaned entry must be advanced-db-cleaner (the slug-mismatched plugin)'
        );
    }

    public function test_in32_ping_returns_updates_pending_total_4_not_5(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setupBanbanScenario();
        $data = $this->callPing( $this->validHeader() )->get_data();
        $this->assertSame( 4, $data['updates_pending_total'],
            'hub_ping must report actionable_total=4, not raw total=5' );
    }

    public function test_in33_ping_updates_pending_alias_equals_actionable(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setupBanbanScenario();
        $data = $this->callPing( $this->validHeader() )->get_data();
        $this->assertSame( 4, $data['updates_pending'],
            'updates_pending alias must equal actionable_total (4)' );
        $this->assertSame(
            $data['updates_pending_total'],
            $data['updates_pending'],
            'updates_pending must be identical to updates_pending_total'
        );
    }

    public function test_in34_ping_includes_updates_orphaned_count(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setupBanbanScenario();
        $data = $this->callPing( $this->validHeader() )->get_data();
        $this->assertSame( 1, $data['updates_orphaned'],
            'hub_ping must report updates_orphaned=1 for the slug-mismatched entry' );
    }

    public function test_in35_inventory_separates_4_actionable_1_orphaned_correctly(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $this->setupBanbanScenario();
        $data  = $this->callInventory( $this->validHeader() )->get_data();

        $pluginFiles  = array_column( $data['plugins'], 'plugin_file' );
        $orphanFiles  = array_column( $data['orphaned_plugins'], 'plugin_file' );

        // Installed 4 must appear in plugins[], not orphaned_plugins[]
        foreach ( [ 'woocommerce/woocommerce.php', 'elementor-pro/elementor-pro.php',
                    'yoast-seo-premium/yoast-seo-premium.php', 'wpml-multilingual-cms/sitepress.php' ] as $f ) {
            $this->assertContains( $f, $pluginFiles,  "{$f} must be in plugins[]" );
            $this->assertNotContains( $f, $orphanFiles, "{$f} must NOT be in orphaned_plugins[]" );
        }

        // Orphaned one must appear only in orphaned_plugins[]
        $this->assertContains(
            'advanced-db-cleaner/advanced-db-cleaner.php',
            $orphanFiles,
            'Orphaned entry must be in orphaned_plugins[]'
        );
        $this->assertNotContains(
            'advanced-db-cleaner/advanced-db-cleaner.php',
            $pluginFiles,
            'Orphaned entry must NOT appear in plugins[]'
        );
    }

    public function test_in36_authenticated_refresh_rebuilds_metadata_before_reading(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $calls = 0;
        RP_Care_REST::$inventory_refresher = static function () use ( &$calls ): void {
            $calls++;
        };
        $request = $this->makeRequest( $this->validHeader() );
        $request->set_param( 'refresh', true );

        $response = $this->rest->hub_updates_inventory( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 1, $calls );
    }

    public function test_in37_unauthenticated_refresh_never_calls_provider_checks(): void {
        update_option( 'rpcare_options', [ 'site_token' => self::RAW_TOKEN ] );
        $calls = 0;
        RP_Care_REST::$inventory_refresher = static function () use ( &$calls ): void {
            $calls++;
        };
        $request = $this->makeRequest();
        $request->set_param( 'refresh', true );

        $response = $this->rest->hub_updates_inventory( $request );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 0, $calls );
    }
}
