<?php
// Minimal bootstrap for offline unit tests — no WP or DB required.
define( 'ABSPATH', __DIR__ . '/../' );
define( 'RPCARE_VERSION', '1.15.29' );

// Shim WP functions used by tested code.
if ( ! function_exists( 'trailingslashit' ) ) {
    function trailingslashit( string $s ): string { return rtrim( $s, '/\\' ) . '/'; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $s ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $s ) ); }
}
if ( ! function_exists( 'get_option' ) ) {
    $GLOBALS['_wp_options'] = [];
    function get_option( string $k, $default = false ) { return $GLOBALS['_wp_options'][$k] ?? $default; }
    function update_option( string $k, $v, $autoload = true ): bool { $GLOBALS['_wp_options'][$k] = $v; return true; }
    function delete_option( string $k ): bool { unset( $GLOBALS['_wp_options'][$k] ); return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $k ) { return $GLOBALS['_wp_options']['_transient_' . $k] ?? false; }
    function set_transient( string $k, $v, int $expiration = 0 ): bool { $GLOBALS['_wp_options']['_transient_' . $k] = $v; return true; }
    function delete_transient( string $k ): bool { unset( $GLOBALS['_wp_options']['_transient_' . $k] ); return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public $code;
        public $message;
        public function __construct( $code = '', $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }
        public function get_error_message() { return $this->message; }
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $v ) { return $v instanceof WP_Error; }
}
