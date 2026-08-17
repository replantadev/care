<?php
/**
 * Update Control System for Replanta Care
 * Controls plugin updates based on plan features
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Update_Control {

    // Set to true by task-updates.php before calling get_plugin_updates() so the
    // read filter doesn't hide free plugins from Care's own update loop.
    public static $bypass_for_task = false;

    // Test-only seam: inject a callable to replace get_site_transient in read_inventory().
    // Must be null in production. Allows testing try/finally restore on exception.
    public static $transient_reader = null;

    // Test-only seam: inject a callable (string $plugin_file_relative) => array{Name:string, Version:string}
    // to replace get_plugin_data() in hub_updates_inventory(). Must be null in production.
    public static $plugin_data_reader = null;

    public function __construct() {
        add_action('init', [$this, 'init']);
    }
    
    public function init() {
        // Get current plan
        $plan = RP_Care_Plan::get_current();
        
        if (!$plan) {
            return;
        }
        
        // Check if update control is enabled for this plan
        $features = RP_Care_Plan::get_features($plan);
        
        if (!isset($features['update_control']) || !$features['update_control']) {
            return;
        }
        
        // Plugin action links: adds an informational label only.
        // Never removes the 'update' link — the administrator can always update manually.
        add_filter('plugin_action_links', [$this, 'modify_plugin_action_links'], 10, 2);
        add_filter('network_admin_plugin_action_links', [$this, 'modify_plugin_action_links'], 10, 2);

        // Add admin notices
        add_action('admin_notices', [$this, 'add_update_control_notice']);

        // Add AJAX handler for licensed plugin detection
        add_action('wp_ajax_rpcare_check_licensed_plugin', [$this, 'ajax_check_licensed_plugin']);
    }
    
    /**
     * Read the raw update inventory from the WP transient, bypassing any active policy filter.
     *
     * Returns total plugin update count and metadata independent of plan, licence, or policy.
     * Uses try/finally so the global state is always restored even if get_site_transient throws.
     *
     * @return array{total: int, checked_at: string|null, inventory_stale: bool}
     */
    public static function read_inventory(): array {
        $prev = self::$bypass_for_task;
        try {
            self::$bypass_for_task = true;
            $reader     = self::$transient_reader ?? 'get_site_transient';
            $update_obj = $reader( 'update_plugins' );
        } finally {
            self::$bypass_for_task = $prev;
        }

        // Absent or non-object: null signals "no data", not "zero pending".
        if ( ! is_object( $update_obj ) ) {
            return [
                'total'           => null,
                'checked_at'      => null,
                'inventory_stale' => true,
            ];
        }

        // Extract checked_at from last_checked — reject zero/negative timestamps.
        $checked = null;
        $stale   = true;
        $ts      = isset( $update_obj->last_checked ) ? (int) $update_obj->last_checked : 0;
        if ( $ts > 0 ) {
            $checked = gmdate( 'c', $ts );
            $stale   = ( time() - $ts ) > 26 * HOUR_IN_SECONDS;
        }

        // Malformed transient: object present but no response array.
        if ( ! is_array( $update_obj->response ?? null ) ) {
            return [
                'total'           => null,
                'checked_at'      => $checked,
                'inventory_stale' => true,   // malformed = stale regardless of age
            ];
        }

        // Valid transient: count may be 0 (no updates pending) or N.
        return [
            'total'           => count( $update_obj->response ),
            'checked_at'      => $checked,
            'inventory_stale' => $stale,
        ];
    }

    /**
     * @deprecated heurística legacy — no usar como fuente de verdad para política de actualizaciones.
     *
     * Conocidas limitaciones:
     * - slug mismatch: si la clave del transient (p.ej. "advanced-db-cleaner/...") no coincide con
     *   el directorio instalado ("advanced-db-cleaner-pro/"), get_plugin_data() devuelve vacío y
     *   todos los indicadores de texto fallan.
     * - License header: get_plugin_data() no incluye 'License' en sus default_headers; ese check
     *   nunca dispara (código muerto).
     * - Falsos positivos: "pro" en el texto libre produce falsos positivos para plugins libres.
     * A futuro reemplazar por un registro explícito de producto/licencia.
     */
    public function filter_plugin_updates($transient) {
        if (self::$bypass_for_task) {
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            return $transient;
        }

        foreach ($transient->response as $plugin_file => $plugin_data) {
            if (!$this->is_plugin_update_allowed($plugin_file)) {
                unset($transient->response[$plugin_file]);
            }
        }

        return $transient;
    }


    /**
     * Check if a plugin update is allowed
     */
    public function is_plugin_update_allowed($plugin_file) {
        // Always allow updates for our own plugins
        if (strpos($plugin_file, 'replanta-') === 0) {
            return true;
        }
        
        // Check if it's a licensed/premium plugin
        if ($this->is_licensed_plugin($plugin_file)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Heurística legacy: intenta determinar si un plugin es de pago/licenciado.
     *
     * @deprecated usar solo para etiquetado de UI, no para decisiones de inventario.
     *   Ver docblock de filter_plugin_updates() para limitaciones conocidas.
     */
    public function is_licensed_plugin($plugin_file) {
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);

        $premium_indicators = ['license', 'premium', 'pro', 'commercial', 'paid', 'subscription'];
        $search_text = strtolower($plugin_data['Name'] . ' ' . $plugin_data['Description'] . ' ' . $plugin_data['Author']);

        foreach ($premium_indicators as $indicator) {
            if (strpos($search_text, $indicator) !== false) {
                return true;
            }
        }

        // Note: 'License' header check removed — get_plugin_data() does not return
        // that field (not in WP default_headers), so the check was dead code.

        $premium_plugins = [
            'elementor-pro', 'gravityforms', 'wpml', 'acf-pro', 'wp-rocket',
            'updraftplus', 'wordfence-premium', 'yoast-seo-premium', 'wp-all-in-one-seo-pack-pro',
        ];

        foreach ($premium_plugins as $premium_plugin) {
            if (strpos($plugin_file, $premium_plugin) !== false) {
                return true;
            }
        }

        if ($this->has_license_management($plugin_file)) {
            return true;
        }

        return false;
    }
    
    /**
     * Check if plugin has license management
     */
    private function has_license_management($plugin_file) {
        $plugin_dir = dirname(WP_PLUGIN_DIR . '/' . $plugin_file);
        
        // Common license file names
        $license_files = [
            'license.php',
            'license-manager.php',
            'license-check.php',
            'licensing.php',
            'updater.php'
        ];
        
        foreach ($license_files as $file) {
            if (file_exists($plugin_dir . '/' . $file)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Add an informational label for plugins managed by Care's automatic pipeline.
     * Does NOT remove the 'update' action link — the administrator can always update manually.
     */
    public function modify_plugin_action_links($actions, $plugin_file) {
        if (!$this->is_plugin_update_allowed($plugin_file)) {
            $actions['rpcare_controlled'] = '<span style="display:inline-flex;align-items:center;gap:4px;background:#eaf4ee;color:#1a5e36;border:1px solid #c3e6cd;border-radius:3px;padding:2px 8px;font-size:11px;font-weight:500;line-height:1.6;">&#9679; Gestionado por Replanta</span>';
        }
        return $actions;
    }
    
    /**
     * Add update control notice
     */
    public function add_update_control_notice() {
        $screen = get_current_screen();
        
        if ($screen->id === 'plugins') {
            $plan = RP_Care_Plan::get_current();
            $plan_name = RP_Care_Plan::get_plan_name($plan);
            
            echo '<div class="notice notice-info">
                <p><span class="dashicons dashicons-shield-alt" style="color:#00a32a;vertical-align:middle;"></span> <strong>Replanta Care — Gestión de Actualizaciones</strong></p>
                <p>Replanta Care gestiona las actualizaciones automáticas de plugins según tu plan <strong>' . esc_html($plan_name) . '</strong>. Puedes actualizar cualquier plugin manualmente desde esta pantalla en cualquier momento.</p>
            </div>';
        }
    }
    
    /**
     * AJAX handler to check if a plugin is licensed
     */
    public function ajax_check_licensed_plugin() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']); return;
        }
        
        $plugin_file = sanitize_text_field($_POST['plugin_file'] ?? '');
        
        if (!$plugin_file) {
            wp_send_json_error('Plugin file not provided');
        }
        
        $is_licensed = $this->is_licensed_plugin($plugin_file);
        $is_allowed = $this->is_plugin_update_allowed($plugin_file);
        
        wp_send_json_success([
            'is_licensed' => $is_licensed,
            'is_allowed' => $is_allowed,
            'message' => $is_allowed ? 'Actualizaciones permitidas' : 'Actualizaciones gestionadas por Replanta'
        ]);
    }
    
    /**
     * Get controlled plugins list
     */
    public function get_controlled_plugins() {
        $all_plugins = get_plugins();
        $controlled = [];
        
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if (!$this->is_plugin_update_allowed($plugin_file)) {
                $controlled[$plugin_file] = [
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                    'is_licensed' => $this->is_licensed_plugin($plugin_file)
                ];
            }
        }
        
        return $controlled;
    }
    
    /**
     * Get allowed plugins list
     */
    public function get_allowed_plugins() {
        $all_plugins = get_plugins();
        $allowed = [];
        
        foreach ($all_plugins as $plugin_file => $plugin_data) {
            if ($this->is_plugin_update_allowed($plugin_file)) {
                $allowed[$plugin_file] = [
                    'name' => $plugin_data['Name'],
                    'version' => $plugin_data['Version'],
                    'is_licensed' => $this->is_licensed_plugin($plugin_file)
                ];
            }
        }
        
        return $allowed;
    }
}

// Initialize the update control system
new RP_Care_Update_Control();
