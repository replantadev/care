<?php
// Minimal bootstrap for offline unit tests — no WP or DB required.
define( 'ABSPATH', __DIR__ . '/../' );
define( 'RPCARE_VERSION', '1.15.68' );
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

// ── WP core shims ──────────────────────────────────────────────────────────
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( string $s ): string { return rtrim( $s, '/\\' ) . '/'; }
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
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( string $tag, $cb, int $pri = 10, int $args = 1 ): bool { return true; }
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
