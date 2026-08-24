<?php
// Minimal bootstrap for offline unit tests — no WP or DB required.
define( 'ABSPATH', __DIR__ . '/../' );
define( 'RPCARE_VERSION', '1.16.3' );
define( 'RPCARE_TESTING', true );

// ── WP option store ────────────────────────────────────────────────────────
$GLOBALS['_wp_options'] = [];

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $k, $default = false ) { return $GLOBALS['_wp_options'][ $k ] ?? $default; }
    function update_option( string $k, $v, $autoload = true ): bool { $GLOBALS['_wp_options'][ $k ] = $v; return true; }
    function delete_option( string $k ): bool { unset( $GLOBALS['_wp_options'][ $k ] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $k ) { return $GLOBALS['_wp_options'][ '_transient_' . $k ] ?? false; }
    function set_transient( string $k, $v, int $expiration = 0 ): bool { $GLOBALS['_wp_options'][ '_transient_' . $k ] = $v; return true; }
    function delete_transient( string $k ): bool { unset( $GLOBALS['_wp_options'][ '_transient_' . $k ] ); return true; }
}
if ( ! function_exists( 'get_site_transient' ) ) {
    function get_site_transient( string $k ) { return $GLOBALS['_wp_options'][ '_site_transient_' . $k ] ?? false; }
    function set_site_transient( string $k, $v, int $expiration = 0 ): bool { $GLOBALS['_wp_options'][ '_site_transient_' . $k ] = $v; return true; }
    function delete_site_transient( string $k ): bool { unset( $GLOBALS['_wp_options'][ '_site_transient_' . $k ] ); return true; }
}

// ── WP core shims ──────────────────────────────────────────────────────────
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( string $s ): string { return rtrim( $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'get_temp_dir' ) ) {
    function get_temp_dir(): string { return trailingslashit( sys_get_temp_dir() ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $s ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
}
if ( ! function_exists( 'current_time' ) ) {
    function current_time( string $type ): string { return date( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'do_action' ) ) {
    function do_action( string $tag, ...$args ): void {}
}
if ( ! function_exists( 'apply_filters' ) ) {
    function apply_filters( string $tag, $value, ...$args ) { return $value; }
}
if ( ! function_exists( 'is_admin' ) ) {
    function is_admin(): bool { return false; }
}
if ( ! function_exists( 'plugin_dir_path' ) ) {
    function plugin_dir_path( string $file ): string { return rtrim( dirname( $file ), '/\\' ) . '/'; }
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
    function plugin_dir_url( string $file ): string { return 'http://localhost/wp-content/plugins/' . basename( dirname( $file ) ) . '/'; }
}
if ( ! function_exists( 'plugin_basename' ) ) {
    function plugin_basename( string $file ): string { return basename( dirname( $file ) ) . '/' . basename( $file ); }
}
if ( ! function_exists( 'load_plugin_textdomain' ) ) {
    function load_plugin_textdomain( string $domain, bool $deprecated = false, string $plugin_rel_path = '' ): bool { return true; }
}
if ( ! function_exists( 'add_action' ) ) {
    function add_action( string $tag, $cb, int $pri = 10, int $args = 1 ): bool { return true; }
}
$GLOBALS['_registered_filters'] = [];
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $tag, $cb, int $pri = 10, int $args = 1 ): bool {
        $GLOBALS['_registered_filters'][] = $tag;
        return true;
    }
}
if ( ! function_exists( 'remove_filter' ) ) {
    function remove_filter( string $tag, $cb, int $pri = 10 ): bool { return true; }
}
if ( ! function_exists( 'register_activation_hook' ) ) {
    function register_activation_hook( string $file, callable $cb ): void {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
    function register_deactivation_hook( string $file, callable $cb ): void {}
}
if ( ! function_exists( 'register_uninstall_hook' ) ) {
    function register_uninstall_hook( string $file, $cb ): void {}
}
if ( ! function_exists( 'home_url' ) ) {
    function home_url( string $path = '' ): string { return 'http://localhost' . $path; }
}
if ( ! function_exists( 'get_site_url' ) ) {
    function get_site_url( ?int $blog_id = null, string $path = '', string $scheme = '' ): string {
        return 'http://localhost' . $path;
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = [] ): array|\WP_Error {
        if ( isset( $GLOBALS['_wp_remote_get_mock'] ) ) {
            $m = $GLOBALS['_wp_remote_get_mock'];
            return is_callable( $m ) ? $m( $url, $args ) : $m;
        }
        return [ 'response' => [ 'code' => 200 ], 'body' => '' ];
    }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $data ): string|false { return json_encode( $data ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( string $url, array $args = [] ): array|\WP_Error {
        if ( isset( $GLOBALS['_wp_remote_post_mock'] ) ) {
            $m = $GLOBALS['_wp_remote_post_mock'];
            return is_callable( $m ) ? $m( $url, $args ) : $m;
        }
        return new \WP_Error( 'not_implemented', 'wp_remote_post stub — configure $GLOBALS[_wp_remote_post_mock]' );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ): int {
        return is_array( $response ) ? (int) ( $response['response']['code'] ?? 200 ) : 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string {
        return is_array( $response ) ? (string) ( $response['body'] ?? '' ) : '';
    }
}
if ( ! function_exists( 'wp_remote_retrieve_headers' ) ) {
    function wp_remote_retrieve_headers( $response ) {
        return is_array( $response ) ? ( $response['headers'] ?? [] ) : [];
    }
}
if ( ! function_exists( 'wp_remote_head' ) ) {
    function wp_remote_head( string $url, array $args = [] ): array|\WP_Error {
        return [ 'response' => [ 'code' => 200 ], 'headers' => [], 'body' => '' ];
    }
}
if ( ! function_exists( 'add_query_arg' ) ) {
    function add_query_arg( $args, string $url = '' ): string {
        if ( is_array( $args ) ) {
            $query = http_build_query( $args );
            return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
        }
        return $url;
    }
}

// ── WP_Error ───────────────────────────────────────────────────────────────
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message(): string { return (string) $this->message; }
        public function get_error_code()            { return $this->code; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $v ): bool { return $v instanceof WP_Error; }
}

// ── add_option (atomic INSERT — returns false if key already exists) ──────────
if ( ! function_exists( 'add_option' ) ) {
    function add_option( string $k, $v, string $deprecated = '', $autoload = 'yes' ): bool {
        if ( array_key_exists( $k, $GLOBALS['_wp_options'] ) ) {
            return false;
        }
        $GLOBALS['_wp_options'][ $k ] = $v;
        return true;
    }
}

// ── Action Scheduler stubs ─────────────────────────────────────────────────
$GLOBALS['_as_pending']  = [];
$GLOBALS['_as_enqueued'] = [];
if ( ! function_exists( 'as_next_scheduled_action' ) ) {
    function as_next_scheduled_action( string $hook, array $args = [], string $group = '' ): int|bool {
        return $GLOBALS['_as_pending'][ $hook ] ?? false;
    }
    function as_schedule_recurring_action( int $ts, int $interval, string $hook, array $args = [], string $group = '', bool $unique = false ): int {
        $GLOBALS['_as_pending'][ $hook ] = $ts;
        return 1;
    }
    function as_unschedule_all_actions( string $hook, array $args = [], string $group = '' ): void {
        unset( $GLOBALS['_as_pending'][ $hook ] );
    }
}
if ( ! function_exists( 'as_schedule_single_action' ) ) {
    function as_schedule_single_action( int $ts, string $hook, array $args = [], string $group = '', bool $unique = false ): int {
        $GLOBALS['_as_pending'][ $hook ] = [ 'ts' => $ts, 'args' => $args, 'group' => $group ];
        return 1;
    }
}
if ( ! function_exists( 'as_enqueue_async_action' ) ) {
    function as_enqueue_async_action( string $hook, array $args = [], string $group = '', bool $unique = false ): int {
        $GLOBALS['_as_enqueued'][] = [ 'hook' => $hook, 'args' => $args, 'group' => $group ];
        return 1;
    }
}

// ── WP Cron stubs ──────────────────────────────────────────────────────────
$GLOBALS['_wp_cron'] = [];
if ( ! function_exists( 'wp_next_scheduled' ) ) {
    function wp_next_scheduled( string $hook, array $args = [] ): int|false {
        return $GLOBALS['_wp_cron'][ $hook ] ?? false;
    }
    function wp_schedule_event( int $ts, string $recurrence, string $hook, array $args = [], bool $wp_error = false ): bool {
        $GLOBALS['_wp_cron'][ $hook ] = $ts;
        return true;
    }
    function wp_clear_scheduled_hook( string $hook, array $args = [], bool $wp_error = false ): int|false {
        $had = isset( $GLOBALS['_wp_cron'][ $hook ] );
        unset( $GLOBALS['_wp_cron'][ $hook ] );
        return $had ? 1 : false;
    }
}

// ── WP plugin stubs ────────────────────────────────────────────────────────
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    // Create a real temp directory with a dummy file so that copy_directory() in
    // PipelineContractTest and other filesystem tests can succeed.
    // Each test file may override this with a more specific path IF it is loaded
    // before this bootstrap runs — they guard with if (!defined('WP_PLUGIN_DIR')).
    $_bootstrap_plugin_dir = sys_get_temp_dir() . '/care-test-plugins-' . getmypid();
    if ( ! is_dir( $_bootstrap_plugin_dir ) ) {
        mkdir( $_bootstrap_plugin_dir, 0755, true );
    }
    file_put_contents( $_bootstrap_plugin_dir . '/dummy-bootstrap-plugin.php', '<?php // test placeholder' );
    define( 'WP_PLUGIN_DIR', $_bootstrap_plugin_dir );
    unset( $_bootstrap_plugin_dir );
}
if ( ! function_exists( 'get_plugin_data' ) ) {
    // Configurable via $GLOBALS['_wp_plugin_data_mock'][$full_path] = [...].
    // Unregistered paths return all-empty headers (simulates file-not-found or
    // headers-absent scenario, the "slug-mismatch" case for is_licensed_plugin).
    function get_plugin_data( string $plugin_file, bool $markup = true, bool $translate = true ): array {
        $defaults = [ 'Name' => '', 'Description' => '', 'Author' => '', 'Version' => '' ];
        if ( isset( $GLOBALS['_wp_plugin_data_mock'][ $plugin_file ] ) ) {
            return array_merge( $defaults, $GLOBALS['_wp_plugin_data_mock'][ $plugin_file ] );
        }
        return $defaults;
    }
}
if ( ! function_exists( 'get_plugins' ) ) {
    // Configurable via $GLOBALS['_wp_installed_plugins_mock']:
    //   [ 'plugin-dir/plugin.php' => ['Name' => 'Plugin Name', 'Version' => '1.0', ...] ]
    // Defaults to empty (no installed plugins — simulates a clean install or full orphan scenario).
    // RP_Care_Update_Control::$get_plugins_reader overrides this at the seam level per-test.
    function get_plugins(): array {
        return $GLOBALS['_wp_installed_plugins_mock'] ?? [];
    }
}

// ── WP constants ───────────────────────────────────────────────────────────
if ( ! defined( 'MINUTE_IN_SECONDS' ) )  define( 'MINUTE_IN_SECONDS',  60 );
if ( ! defined( 'HOUR_IN_SECONDS' ) )    define( 'HOUR_IN_SECONDS',    3600 );
if ( ! defined( 'DAY_IN_SECONDS' ) )     define( 'DAY_IN_SECONDS',     86400 );
if ( ! defined( 'WEEK_IN_SECONDS' ) )    define( 'WEEK_IN_SECONDS',    604800 );
if ( ! defined( 'WP_DEBUG_LOG' ) )       define( 'WP_DEBUG_LOG',       false );
if ( ! defined( 'DOING_CRON' ) )         define( 'DOING_CRON',         false );
if ( ! defined( 'DOING_AJAX' ) )         define( 'DOING_AJAX',         false );
// wpdb output-type constants
if ( ! defined( 'OBJECT' ) )             define( 'OBJECT',   'OBJECT' );
if ( ! defined( 'ARRAY_A' ) )            define( 'ARRAY_A',  'ARRAY_A' );
if ( ! defined( 'ARRAY_N' ) )            define( 'ARRAY_N',  'ARRAY_N' );

// ── WP crypto stubs ───────────────────────────────────────────────────────
if ( ! function_exists( 'wp_salt' ) ) {
    function wp_salt( string $scheme = 'auth' ): string {
        return 'test-wp-salt-for-unit-tests-only';
    }
}

// ── Minimal $wpdb stub (no real DB — inserts return false) ────────────────
if ( ! class_exists( 'wpdb' ) ) {
    class wpdb {
        public string $prefix      = 'wp_';
        public string $dbname      = 'test_db';
        public ?string $last_error = null;

        public function insert( string $table, array $data, $format = null ): int|false { return false; }
        public function update( string $table, array $data, array $where, $format = null, $where_format = null ): int|false { return 0; }
        public function query( string $sql ): int|bool { return true; }
        public function get_var( string $sql, int $col_offset = 0, int $row_offset = 0 ): ?string { return null; }
        public function get_row( string $sql, string $output = 'OBJECT', int $row_offset = 0 ) { return null; }
        public function get_results( string $sql, string $output = 'OBJECT' ): array { return []; }
        public function get_col( string $sql, int $col_offset = 0 ): array { return []; }
        public function prepare( string $sql, ...$args ): string {
            // Basic positional substitution — for test assertions only.
            $idx = 0;
            return preg_replace_callback( '/%[sdfs]/', function() use ( &$idx, $args ) {
                return "'" . addslashes( (string) ( $args[ $idx++ ] ?? '' ) ) . "'";
            }, $sql );
        }
        public function _real_escape( string $s ): string { return addslashes( $s ); }
        public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
    }
}
if ( ! isset( $GLOBALS['wpdb'] ) || ! $GLOBALS['wpdb'] ) {
    $GLOBALS['wpdb'] = new wpdb();
}
$wpdb = $GLOBALS['wpdb']; // make available at global scope via direct assignment

// ── RP_Care_Utils stub ─────────────────────────────────────────────────────
if ( ! class_exists( 'RP_Care_Utils' ) ) {
    class RP_Care_Utils {
        public static function log( string $cat, string $level, string $msg, array $ctx = [] ): void {}
    }
}

// ── REST API stubs ─────────────────────────────────────────────────────────
// Minimal stubs for testing REST endpoint handlers offline (no WP loaded).

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        private array $headers = [];
        private array $params  = [];

        /**
         * Set a header on the request.
         * Key format mirrors what WP stores internally: lowercase, hyphens → underscores.
         * e.g. 'X-Hub-Token' → 'x_hub_token'
         */
        public function set_header( string $key, string $value ): void {
            $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
        }

        /**
         * Retrieve a header by WP-normalized key (lowercase, underscored).
         */
        public function get_header( string $key ): ?string {
            return $this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] ?? null;
        }

        public function set_param( string $key, $value ): void {
            $this->params[ $key ] = $value;
        }

        public function get_param( string $key ) {
            return $this->params[ $key ] ?? null;
        }

        public function get_json_params(): array {
            return $this->params;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        private $data;
        private int $status;

        public function __construct( $data = null, int $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data() { return $this->data; }
        public function get_status(): int { return $this->status; }
    }
}

// Track routes registered during test; reset in setUp() via
// $GLOBALS['_rest_routes'] = [].
$GLOBALS['_rest_routes'] = [];
if ( ! function_exists( 'register_rest_route' ) ) {
    function register_rest_route( string $namespace, string $route, array $args, bool $override = false ): bool {
        $GLOBALS['_rest_routes'][] = [
            'namespace' => $namespace,
            'route'     => $route,
            'args'      => $args,
        ];
        return true;
    }
}

// gmdate stub — returns a fixed deterministic timestamp for tests.
if ( ! function_exists( 'gmdate' ) ) {
    function gmdate( string $format, ?int $timestamp = null ): string {
        return \gmdate( $format, $timestamp ?? time() );
    }
}

// esc_url_raw stub (used by bootstrap handler in class-rest.php).
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url ): string { return $url; }
}

// Additional WP stubs required by RP_Care_Golden_Snapshot and class-rest.php
// when test files are run in isolation (not as part of the full suite).
if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ): array|string|int|false|null {
        return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ): string { return trim( (string) $s ); }
}
if ( ! function_exists( 'get_bloginfo' ) ) {
    function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
        return match ( $show ) {
            'version'  => '6.5',
            'name'     => 'Test Site',
            'url'      => 'http://localhost',
            default    => '',
        };
    }
}
if ( ! function_exists( 'wp_generate_password' ) ) {
    function wp_generate_password( int $length = 12, bool $special_chars = true, bool $extra_special_chars = false ): string {
        return str_pad( '', $length, 'abcdefghij', STR_PAD_RIGHT );
    }
}
if ( ! function_exists( 'wc_get_page_id' ) ) {
    function wc_get_page_id( string $page ): int { return -1; }
}
if ( ! function_exists( 'wp_check_filetype' ) ) {
    function wp_check_filetype( string $filename, ?array $mimes = null ): array {
        $ext = pathinfo( $filename, PATHINFO_EXTENSION );
        return [ 'ext' => $ext, 'type' => "text/$ext" ];
    }
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
    function wp_upload_dir( ?string $time = null, bool $create_dir = true, bool $refresh_cache = false ): array {
        $base = ABSPATH . 'wp-content/uploads';
        return [
            'path'    => $base,
            'url'     => 'http://localhost/wp-content/uploads',
            'subdir'  => '',
            'basedir' => $base,
            'baseurl' => 'http://localhost/wp-content/uploads',
            'error'   => false,
        ];
    }
}
if ( ! function_exists( 'glob' ) ) {
    // glob is a PHP built-in — this guard is defensive only.
    function glob( string $pattern, int $flags = 0 ): array|false { return []; }
}
