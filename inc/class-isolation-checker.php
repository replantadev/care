<?php
/**
 * Replanta Care — Staging Isolation Checker
 *
 * Verifies that a staging environment meets minimum isolation requirements
 * before any update batch is applied.
 *
 * A failed CRITICAL check blocks the pipeline (staging_isolation_failed).
 * A failed WARNING check is logged but does not block.
 *
 * Run as: RP_Care_Isolation_Checker::run()
 *
 * Compatible with PHP 7.4+ (Care's minimum requirement).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RP_Care_Isolation_Checker {

    const LEVEL_CRITICAL = 'critical';
    const LEVEL_WARNING  = 'warning';
    const LEVEL_OK       = 'ok';
    const LEVEL_SKIPPED  = 'skipped';

    /**
     * Run all isolation checks.
     *
     * @return array{
     *   passed: bool,
     *   checks: array[],
     * }
     */
    public static function run(): array {
        $checks = [
            self::check_noindex(),
            self::check_robots_header(),
            self::check_email_suppressed(),
            self::check_not_production_url(),
            self::check_maintenance_mode_available(),
            self::check_woo_emails_disabled(),
            self::check_pipelines_not_live(),
            self::check_wp_environment_type(),
            self::check_redis_isolated(),
            self::check_db_isolated(),
        ];

        $failed_critical = array_filter( $checks, fn( $c ) => $c['level'] === self::LEVEL_CRITICAL && $c['status'] !== self::LEVEL_OK );
        $passed          = empty( $failed_critical );

        return [
            'passed' => $passed,
            'checks' => $checks,
        ];
    }

    // ── Individual checks ─────────────────────────────────────────────────────

    private static function check_noindex(): array {
        $blog_public = get_option( 'blog_public', 1 );
        if ( ! $blog_public ) {
            return self::ok( 'noindex', 'Site is set to discourage search engines (blog_public=0).' );
        }
        return self::fail( 'noindex', 'blog_public=1 detected on staging. Set to discourage search engines.', self::LEVEL_CRITICAL );
    }

    private static function check_robots_header(): array {
        // We can only verify this if we can make a loopback request.
        $url      = home_url( '/' );
        $response = wp_remote_head( $url, [ 'timeout' => 5, 'redirection' => 2 ] );

        if ( is_wp_error( $response ) ) {
            return self::skip( 'robots_header', 'Could not verify X-Robots-Tag header (loopback unavailable).' );
        }

        $headers  = wp_remote_retrieve_headers( $response );
        $robots   = strtolower( (string) ( $headers['x-robots-tag'] ?? '' ) );

        if ( strpos( $robots, 'noindex' ) !== false ) {
            return self::ok( 'robots_header', 'X-Robots-Tag: noindex present.' );
        }

        return self::fail( 'robots_header', 'X-Robots-Tag: noindex not found in response headers.', self::LEVEL_WARNING );
    }

    private static function check_email_suppressed(): array {
        // Check for common email suppression plugins / options.
        $suppressed = defined( 'RPCARE_STAGING_SUPPRESS_EMAIL' ) && RPCARE_STAGING_SUPPRESS_EMAIL;
        $suppressed = $suppressed || class_exists( 'WP_Mail_SMTP' ); // SMTP plugins often redirect

        // Check for rpcare-specific suppression flag.
        $suppressed = $suppressed || (bool) get_option( 'rpcare_staging_suppress_email', false );

        // Email sink active means suppression/redirect is in effect.
        $suppressed = $suppressed || (
            class_exists( 'RP_Care_Staging_Email_Sink' ) && RP_Care_Staging_Email_Sink::is_active()
        );

        if ( $suppressed ) {
            return self::ok( 'email_suppressed', 'Email suppression detected.' );
        }

        return self::fail(
            'email_suppressed',
            'No email suppression detected on staging. Set RPCARE_STAGING_SUPPRESS_EMAIL=true in wp-config.php or install a mail suppression plugin.',
            self::LEVEL_WARNING
        );
    }

    private static function check_not_production_url(): array {
        $current   = home_url();
        $canonical = (string) get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );

        if ( $canonical === 'production' ) {
            return self::fail(
                'not_production_url',
                'This instance is configured as production. Do not run staging checks on a production instance.',
                self::LEVEL_CRITICAL
            );
        }

        $env = get_option( RP_Care_Pipeline_Client::OPT_ENVIRONMENT, '' );
        if ( $env === 'production' ) {
            return self::fail(
                'not_production_url',
                'Environment option is "production" — this is not a staging site.',
                self::LEVEL_CRITICAL
            );
        }

        return self::ok( 'not_production_url', "Instance environment: $env." );
    }

    private static function check_maintenance_mode_available(): array {
        // Verify that the maintenance file can be written (permissions check).
        $maintenance = ABSPATH . '.maintenance';
        $test_file   = ABSPATH . '.rpcare-isolation-test-' . time();

        $writable = @file_put_contents( $test_file, '1' ) !== false;
        if ( $writable ) {
            @unlink( $test_file );
            return self::ok( 'maintenance_mode', 'ABSPATH is writable; maintenance mode can be activated.' );
        }

        return self::fail(
            'maintenance_mode',
            'ABSPATH is not writable. Maintenance mode may not be activatable during updates.',
            self::LEVEL_WARNING
        );
    }

    private static function check_woo_emails_disabled(): array {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return self::skip( 'woo_emails', 'WooCommerce not active.' );
        }

        // Check WC mailer suppression.
        $mailer = WC()->mailer();
        if ( ! $mailer ) {
            return self::skip( 'woo_emails', 'WC mailer not available.' );
        }

        // Check if 'woocommerce_email_enabled_*' options are all set to 'no'.
        // Or if a staging plugin has hooked 'woocommerce_email_classes' to return [].
        $option      = get_option( 'rpcare_staging_woo_email_suppressed', false );
        $email_sink  = class_exists( 'RP_Care_Staging_Email_Sink' ) && RP_Care_Staging_Email_Sink::is_active();
        if ( $option || $email_sink ) {
            return self::ok( 'woo_emails', 'WooCommerce emails are covered by the staging email sink.' );
        }

        return self::fail(
            'woo_emails',
            'WooCommerce is active and email suppression is not confirmed. Ensure emails are disabled on staging.',
            self::LEVEL_WARNING
        );
    }

    private static function check_pipelines_not_live(): array {
        // Check that known payment gateway test modes are active (or gateways disabled).
        if ( ! class_exists( 'WooCommerce' ) ) {
            return self::skip( 'payment_gateways', 'WooCommerce not active.' );
        }

        $live_gateways = [];
        $payment_gateways = WC()->payment_gateways();
        if ( $payment_gateways ) {
            foreach ( $payment_gateways->payment_gateways() as $gateway ) {
                if ( $gateway->enabled !== 'yes' ) {
                    continue;
                }
                // If gateway has testmode property and it's off, flag it.
                if ( isset( $gateway->testmode ) && ! $gateway->testmode ) {
                    $live_gateways[] = $gateway->id;
                } elseif ( isset( $gateway->settings['testmode'] ) && $gateway->settings['testmode'] === 'no' ) {
                    $live_gateways[] = $gateway->id;
                }
            }
        }

        if ( ! empty( $live_gateways ) ) {
            return self::fail(
                'payment_gateways',
                'Live payment gateways detected: ' . implode( ', ', $live_gateways ) . '. Switch to sandbox/test mode on staging.',
                self::LEVEL_CRITICAL
            );
        }

        return self::ok( 'payment_gateways', 'No live payment gateways detected.' );
    }

    private static function check_wp_environment_type(): array {
        if ( ! function_exists( 'wp_get_environment_type' ) ) {
            return self::skip( 'wp_environment_type', 'wp_get_environment_type() not available.' );
        }
        $env_type = wp_get_environment_type();
        if ( $env_type === 'production' ) {
            return self::fail(
                'wp_environment_type',
                'WP_ENVIRONMENT_TYPE is "production". Set it to "staging" in wp-config.php on this staging instance.',
                self::LEVEL_CRITICAL
            );
        }
        return self::ok( 'wp_environment_type', "WP_ENVIRONMENT_TYPE is \"$env_type\"." );
    }

    private static function check_redis_isolated(): array {
        // WP_Object_Cache is WordPress core and is not evidence of Redis.
        // Only Redis-specific configuration/drop-ins should activate this gate.
        $redis_configured = defined( 'WP_REDIS_HOST' )
            || defined( 'WP_REDIS_SCHEME' )
            || defined( 'WP_REDIS_SERVERS' )
            || class_exists( 'Redis_Object_Cache' );
        if ( ! $redis_configured ) {
            return self::skip( 'redis_isolated', 'Redis not detected.' );
        }

        if ( ! defined( 'WP_REDIS_PREFIX' ) || (string) WP_REDIS_PREFIX === '' ) {
            return self::fail(
                'redis_isolated',
                'Redis is configured but WP_REDIS_PREFIX is not set. Staging and production may share cache keys if they use the same Redis instance.',
                self::LEVEL_CRITICAL
            );
        }

        $prefix = (string) WP_REDIS_PREFIX;
        return self::ok( 'redis_isolated', "WP_REDIS_PREFIX is set (\"$prefix\"). Cache keys are namespaced." );
    }

    private static function check_db_isolated(): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return self::skip( 'db_isolated', 'wpdb not available.' );
        }

        $current_db = (string) ( $wpdb->dbname ?? ( defined( 'DB_NAME' ) ? DB_NAME : '' ) );
        if ( $current_db === '' ) {
            return self::skip( 'db_isolated', 'DB_NAME could not be determined.' );
        }

        $prod_db = (string) get_option( 'rpcare_production_db_name', '' );
        if ( $prod_db !== '' ) {
            if ( $current_db === $prod_db ) {
                return self::fail(
                    'db_isolated',
                    "Staging DB (\"$current_db\") matches the registered production DB. This staging instance shares the production database.",
                    self::LEVEL_CRITICAL
                );
            }
            return self::ok( 'db_isolated', "Staging DB \"$current_db\" differs from production DB \"$prod_db\"." );
        }

        // Without a stored reference, use naming convention as a heuristic.
        $lower = strtolower( $current_db );
        if ( str_contains( $lower, 'staging' ) || str_contains( $lower, 'stage' ) || str_contains( $lower, '_stg' ) ) {
            return self::ok( 'db_isolated', "Database name \"$current_db\" appears to be staging-specific." );
        }

        return self::fail(
            'db_isolated',
            "Database name \"$current_db\" does not identify as staging. " .
            'Set the rpcare_production_db_name option to the production DB name to enable comparison, ' .
            'or use a staging-specific DB name.',
            self::LEVEL_WARNING
        );
    }

    // ── Result builders ──────────────────────────────────────────────────────

    private static function ok( string $name, string $message ): array {
        return [ 'name' => $name, 'status' => self::LEVEL_OK, 'level' => self::LEVEL_OK, 'message' => $message ];
    }

    private static function fail( string $name, string $message, string $level ): array {
        return [ 'name' => $name, 'status' => 'failed', 'level' => $level, 'message' => $message ];
    }

    private static function skip( string $name, string $message ): array {
        return [ 'name' => $name, 'status' => self::LEVEL_SKIPPED, 'level' => self::LEVEL_SKIPPED, 'message' => $message ];
    }
}
