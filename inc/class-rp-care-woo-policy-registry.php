<?php
/**
 * Replanta Care — WooCommerce Option Policy Registry
 *
 * Defines a closed, versioned allowlist of WooCommerce options and their
 * governance classification. Care NEVER writes a generic update_option(); every
 * option that Care may act on must appear here with an explicit policy.
 *
 * CLASSIFICATION
 * ──────────────
 * AUTOCORREGIBLE  May be corrected automatically by Care, idempotently, with
 *                 no staging or approval needed.  Examples: expired transients,
 *                 housekeeping flags, legacy obsolete locks.
 *
 * SENSIBLE        May NEVER be changed automatically in production without full
 *                 staging pipeline + explicit operator approval.  Includes all
 *                 payment, tax, currency, stock, order, checkout, account,
 *                 privacy, email, webhook, tracking, and third-party integration
 *                 options.  Drift in these is reported and escalated; Care never
 *                 writes them autonomously.
 *
 * INFORMATIVA     Read-only observation. Care reads the value to build telemetry
 *                 and audit records but never writes it.
 *
 * POLICY MODES  (per-site, set by Plugin Center)
 * ───────────────────────────────────────────────
 * observe           — read and report, never write
 * enforce_safe      — read, report, and auto-correct AUTOCORREGIBLE options only
 * approval_required — like enforce_safe but all corrections require operator sign-off
 *
 * SECURITY INVARIANTS
 * ───────────────────
 * • No option may be reclassified from SENSIBLE to AUTOCORREGIBLE programmatically.
 * • Care MUST check is_sensible() before any write; sensible options always return
 *   false from is_autocorrigible().
 * • A policy registry update requires a bumped SCHEMA_VERSION — no hot-swap.
 * • This registry is the ONLY source of truth; callers must not maintain their own lists.
 *
 * @since 1.15.74
 */

defined( 'ABSPATH' ) || exit;

class RP_Care_Woo_Policy_Registry {

    /** Schema version — bump when option list or classification changes. */
    const SCHEMA_VERSION = 1;

    const CLASS_AUTOCORRIGIBLE = 'autocorrigible';
    const CLASS_SENSIBLE       = 'sensible';
    const CLASS_INFORMATIVA    = 'informativa';

    const MODE_OBSERVE           = 'observe';
    const MODE_ENFORCE_SAFE      = 'enforce_safe';
    const MODE_APPROVAL_REQUIRED = 'approval_required';

    /**
     * Complete option policy registry.
     *
     * Format: 'option_name' => [ 'class' => CLASS_*, 'description' => '...' ]
     *
     * This list is intentionally closed: adding to it requires a deliberate,
     * code-reviewed change — Care does not auto-discover WooCommerce options.
     *
     * @var array<string, array{class: string, description: string}>
     */
    private const REGISTRY = [

        // ── AUTOCORRIGIBLE: housekeeping, expiry, obsolete locks ─────────────

        'woocommerce_queue_flush_rewrite_rules' => [
            'class'       => self::CLASS_AUTOCORRIGIBLE,
            'description' => 'Pending rewrite rule flush flag set during plugin activation.',
        ],
        'wc_scheduler_db_version' => [
            'class'       => self::CLASS_AUTOCORRIGIBLE,
            'description' => 'Action Scheduler database schema version marker.',
        ],
        'woocommerce_db_version' => [
            'class'       => self::CLASS_INFORMATIVA,
            'description' => 'Installed WooCommerce database schema version.',
        ],
        'wc_download_directory_cleaning_scheduled' => [
            'class'       => self::CLASS_AUTOCORRIGIBLE,
            'description' => 'WooCommerce download cleanup scheduled flag.',
        ],
        '_wc_session_expires' => [
            'class'       => self::CLASS_AUTOCORRIGIBLE,
            'description' => 'Ephemeral session expiry flag; safe to clear when expired.',
        ],

        // ── INFORMATIVA: WooCommerce version, feature flags ───────────────────

        'woocommerce_version' => [
            'class'       => self::CLASS_INFORMATIVA,
            'description' => 'Active WooCommerce version string.',
        ],
        'woocommerce_installed' => [
            'class'       => self::CLASS_INFORMATIVA,
            'description' => 'WooCommerce installation timestamp.',
        ],
        'woocommerce_feature_cart_checkout_blocks_enabled' => [
            'class'       => self::CLASS_INFORMATIVA,
            'description' => 'Block Cart/Checkout feature flag.',
        ],

        // ── SENSIBLE: store identity, pages, URLs ─────────────────────────────

        'woocommerce_store_address'   => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Legal store address.' ],
        'woocommerce_store_address_2' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Store address line 2.' ],
        'woocommerce_store_city'      => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Store city.' ],
        'woocommerce_default_country' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Default country/state — affects tax, shipping and payment availability.' ],
        'woocommerce_store_postcode'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Store postcode.' ],

        // ── SENSIBLE: currency and pricing ────────────────────────────────────

        'woocommerce_currency'               => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Active store currency.' ],
        'woocommerce_currency_pos'           => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Currency symbol position.' ],
        'woocommerce_price_thousand_sep'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Price thousands separator.' ],
        'woocommerce_price_decimal_sep'      => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Price decimal separator.' ],
        'woocommerce_price_num_decimals'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Number of price decimal places.' ],

        // ── SENSIBLE: tax settings ────────────────────────────────────────────

        'woocommerce_calc_taxes'             => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Tax calculation enabled.' ],
        'woocommerce_prices_include_tax'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Prices include tax (gross pricing).' ],
        'woocommerce_tax_based_on'           => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Tax based on billing/shipping/base address.' ],
        'woocommerce_shipping_tax_class'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Tax class for shipping.' ],
        'woocommerce_tax_round_at_subtotal'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Round tax at subtotal level.' ],
        'woocommerce_tax_classes'            => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Defined tax classes list.' ],
        'woocommerce_tax_display_shop'       => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Tax display in shop (incl/excl).' ],
        'woocommerce_tax_display_cart'       => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Tax display in cart and checkout.' ],
        'woocommerce_price_display_suffix'   => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Price suffix text (e.g. "incl. tax").' ],
        'woocommerce_tax_total_display'      => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'How tax totals are displayed.' ],

        // ── SENSIBLE: payment gateways ────────────────────────────────────────

        'woocommerce_gateway_order'          => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Payment gateway display order.' ],
        'woocommerce_cod_settings'           => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Cash on delivery gateway settings.' ],
        'woocommerce_bacs_settings'          => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'BACS (bank transfer) gateway settings.' ],
        'woocommerce_cheque_settings'        => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Cheque payment gateway settings.' ],
        'woocommerce_paypal_settings'        => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'PayPal gateway settings (live keys).' ],

        // ── SENSIBLE: stock and inventory ─────────────────────────────────────

        'woocommerce_manage_stock'           => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Global stock management toggle.' ],
        'woocommerce_hold_stock_minutes'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Stock hold duration for unpaid orders.' ],
        'woocommerce_notify_low_stock'       => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Low stock notification enabled.' ],
        'woocommerce_notify_no_stock'        => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Out-of-stock notification enabled.' ],
        'woocommerce_stock_email_recipient'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email address for stock alerts.' ],
        'woocommerce_notify_low_stock_amount'=> [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Low stock threshold quantity.' ],
        'woocommerce_notify_no_stock_amount' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Out-of-stock threshold quantity.' ],
        'woocommerce_hide_out_of_stock_items'=> [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Hide out-of-stock products from catalog.' ],
        'woocommerce_stock_format'           => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Stock display format.' ],

        // ── SENSIBLE: checkout and accounts ──────────────────────────────────

        'woocommerce_enable_guest_checkout'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Allow checkout without account.' ],
        'woocommerce_enable_checkout_login_reminder' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Show login reminder at checkout.' ],
        'woocommerce_enable_signup_and_login_from_checkout' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Allow account creation at checkout.' ],
        'woocommerce_enable_myaccount_registration' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Allow registration on My Account page.' ],
        'woocommerce_registration_generate_password' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Auto-generate passwords on registration.' ],
        'woocommerce_registration_generate_username' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Auto-generate usernames on registration.' ],

        // ── SENSIBLE: WooCommerce page assignments ────────────────────────────

        'woocommerce_shop_page_id'      => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Shop page ID — changing breaks shop URL.' ],
        'woocommerce_cart_page_id'      => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Cart page ID.' ],
        'woocommerce_checkout_page_id'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Checkout page ID.' ],
        'woocommerce_myaccount_page_id' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'My Account page ID.' ],
        'woocommerce_terms_page_id'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Terms and conditions page ID.' ],

        // ── SENSIBLE: email settings ──────────────────────────────────────────

        'woocommerce_email_from_name'        => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Sender name for WooCommerce emails.' ],
        'woocommerce_email_from_address'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Sender email address for WooCommerce emails.' ],
        'woocommerce_email_header_image'     => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email header image URL.' ],
        'woocommerce_email_footer_text'      => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email footer text.' ],
        'woocommerce_email_base_color'       => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email base color.' ],
        'woocommerce_email_background_color' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email background color.' ],
        'woocommerce_email_body_background_color' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email body background color.' ],
        'woocommerce_email_text_color'       => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Email body text color.' ],

        // ── SENSIBLE: privacy and GDPR ────────────────────────────────────────

        'woocommerce_erasure_request_removes_order_data' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Whether erasure requests remove order data.' ],
        'woocommerce_erasure_request_removes_download_data' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Whether erasure requests remove download data.' ],
        'woocommerce_allow_tracking' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'WooCommerce usage tracking (telemetry opt-in).' ],

        // ── SENSIBLE: webhooks and REST ───────────────────────────────────────

        'woocommerce_api_enabled' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Legacy WooCommerce REST API v1/v2 enabled.' ],

        // ── SENSIBLE: order management ────────────────────────────────────────

        'woocommerce_trash_pending_orders' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Days before pending orders are trashed.' ],
        'woocommerce_trash_failed_orders'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Days before failed orders are trashed.' ],
        'woocommerce_trash_cancelled_orders' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Days before cancelled orders are trashed.' ],
        'woocommerce_complete_order_status' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Order status after download completes.' ],

        // ── SENSIBLE: shipping ────────────────────────────────────────────────

        'woocommerce_ship_to_destination'   => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Ship to shipping or billing address.' ],
        'woocommerce_enable_shipping_calc'  => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Enable shipping calculator on cart.' ],
        'woocommerce_shipping_cost_requires_address' => [ 'class' => self::CLASS_SENSIBLE, 'description' => 'Hide shipping until address entered.' ],
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Return the schema version of this registry.
     */
    public static function schema_version(): int {
        return self::SCHEMA_VERSION;
    }

    /**
     * Returns true if the option name is in the registry.
     */
    public static function knows( string $option ): bool {
        return isset( self::REGISTRY[ $option ] );
    }

    /**
     * Returns the classification for a known option.
     *
     * @return string|null  CLASS_* constant, or null when option is unknown.
     */
    public static function classification( string $option ): ?string {
        return self::REGISTRY[ $option ]['class'] ?? null;
    }

    /**
     * Returns true when the option may be auto-corrected without approval.
     *
     * SENSIBLE options always return false, even if somehow reclassified.
     * This method is the final safety check before any write.
     */
    public static function is_autocorrigible( string $option ): bool {
        $class = self::REGISTRY[ $option ]['class'] ?? null;
        // Explicit double-check: never allow sensible options, even in edge cases.
        return $class === self::CLASS_AUTOCORRIGIBLE && $class !== self::CLASS_SENSIBLE;
    }

    /**
     * Returns true when the option requires human approval for any change.
     */
    public static function is_sensible( string $option ): bool {
        return ( self::REGISTRY[ $option ]['class'] ?? null ) === self::CLASS_SENSIBLE;
    }

    /**
     * Return all known options with their classification.
     *
     * @return array<string, array{class: string, description: string}>
     */
    public static function all(): array {
        return self::REGISTRY;
    }

    /**
     * Return all options of a given classification.
     *
     * @param string $class  One of CLASS_* constants.
     * @return string[]      Option names.
     */
    public static function by_class( string $class ): array {
        return array_keys( array_filter( self::REGISTRY, fn( $r ) => $r['class'] === $class ) );
    }

    /**
     * Validate a drift report entry before escalation.
     *
     * Ensures the option is registered and that the caller is not attempting
     * to write a sensible option without approval.
     *
     * @param  string $option        Option name.
     * @param  string $target_mode   MODE_* constant from policy.
     * @param  bool   $is_production True when the target is a production site.
     *
     * @return true|\WP_Error  WP_Error when the operation is not permitted.
     */
    public static function assert_write_permitted( string $option, string $target_mode, bool $is_production ): bool|\WP_Error {
        if ( ! self::knows( $option ) ) {
            return new \WP_Error( 'option_not_in_registry', "Option '{$option}' is not in the Care policy registry." );
        }

        if ( self::is_sensible( $option ) ) {
            return new \WP_Error(
                'option_is_sensible',
                "Option '{$option}' is classified SENSIBLE and requires staging + operator approval before any write.",
                [ 'option' => $option, 'is_production' => $is_production ]
            );
        }

        if ( ! self::is_autocorrigible( $option ) ) {
            return new \WP_Error( 'option_not_autocorrigible', "Option '{$option}' is not classified as auto-correctable." );
        }

        if ( $target_mode === self::MODE_OBSERVE ) {
            return new \WP_Error( 'mode_observe', "Policy mode 'observe' prohibits any writes." );
        }

        if ( $target_mode === self::MODE_APPROVAL_REQUIRED ) {
            return new \WP_Error( 'approval_required', "Policy mode 'approval_required': operator must approve before writing '{$option}'." );
        }

        return true;
    }

    /**
     * Describe a drift event for logging/telemetry.
     * Does NOT write anything — only produces a structured description.
     *
     * @return array{
     *   option: string,
     *   class: string,
     *   observed: mixed,
     *   expected: mixed,
     *   permitted_actions: string[],
     *   schema_version: int,
     *   detected_at: string,
     * }
     */
    public static function describe_drift( string $option, mixed $observed, mixed $expected ): array {
        $class    = self::classification( $option ) ?? 'unknown';
        $actions  = [];

        if ( self::is_autocorrigible( $option ) ) {
            $actions[] = 'autocorrect';
        }
        if ( self::is_sensible( $option ) ) {
            $actions[] = 'alert';
            $actions[] = 'approval_required';
        } else {
            $actions[] = 'alert';
        }

        return [
            'option'            => $option,
            'class'             => $class,
            'observed'          => $observed,
            'expected'          => $expected,
            'permitted_actions' => $actions,
            'schema_version'    => self::SCHEMA_VERSION,
            'detected_at'       => gmdate( 'c' ),
        ];
    }
}
