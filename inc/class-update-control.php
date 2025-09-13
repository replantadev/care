<?php
/**
 * Update Control System for Replanta Care
 * Controls plugin updates based on plan features
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Update_Control {
    
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
        
        // Hook into update checks
        add_filter('site_transient_update_plugins', [$this, 'filter_plugin_updates']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'filter_plugin_updates']);
        
        // Hook into plugin actions
        add_filter('plugin_action_links', [$this, 'modify_plugin_action_links'], 10, 2);
        add_filter('network_admin_plugin_action_links', [$this, 'modify_plugin_action_links'], 10, 2);
        
        // Hook into bulk actions
        add_filter('bulk_actions-plugins', [$this, 'remove_bulk_update_action']);
        
        // Add admin notices
        add_action('admin_notices', [$this, 'add_update_control_notice']);
        
        // Hide update notices for controlled plugins
        add_action('admin_head', [$this, 'hide_update_notices']);
        
        // Add AJAX handler for licensed plugin detection
        add_action('wp_ajax_rpcare_check_licensed_plugin', [$this, 'ajax_check_licensed_plugin']);
    }
    
    /**
     * Filter plugin updates to hide them for non-licensed plugins
     */
    public function filter_plugin_updates($transient) {
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
     * Check if a plugin is licensed/premium
     */
    public function is_licensed_plugin($plugin_file) {
        // Get plugin data
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
        
        // Common indicators of premium/licensed plugins
        $premium_indicators = [
            'license',
            'premium',
            'pro',
            'commercial',
            'paid',
            'subscription'
        ];
        
        // Check plugin name, description, and author
        $search_text = strtolower($plugin_data['Name'] . ' ' . $plugin_data['Description'] . ' ' . $plugin_data['Author']);
        
        foreach ($premium_indicators as $indicator) {
            if (strpos($search_text, $indicator) !== false) {
                return true;
            }
        }
        
        // Check for license fields in plugin headers
        if (isset($plugin_data['License']) && $plugin_data['License'] !== 'GPL' && $plugin_data['License'] !== 'GPLv2' && $plugin_data['License'] !== 'GPLv3') {
            return true;
        }
        
        // Check for common premium plugin patterns
        $premium_plugins = [
            'elementor-pro',
            'gravityforms',
            'wpml',
            'acf-pro',
            'wp-rocket',
            'updraftplus',
            'wordfence-premium',
            'yoast-seo-premium',
            'wp-all-in-one-seo-pack-pro'
        ];
        
        foreach ($premium_plugins as $premium_plugin) {
            if (strpos($plugin_file, $premium_plugin) !== false) {
                return true;
            }
        }
        
        // Check if plugin has a license management system
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
     * Modify plugin action links
     */
    public function modify_plugin_action_links($actions, $plugin_file) {
        if (!$this->is_plugin_update_allowed($plugin_file)) {
            // Remove update link
            unset($actions['update']);
            
            // Add controlled update notice
            if ($this->is_licensed_plugin($plugin_file)) {
                $actions['rpcare_licensed'] = '<span style="color: #00a32a;">✓ Plugin con licencia - Actualizaciones permitidas</span>';
            } else {
                $actions['rpcare_controlled'] = '<span style="color: #d63638;">🔒 Actualizaciones gestionadas por Replanta</span>';
            }
        }
        
        return $actions;
    }
    
    /**
     * Remove bulk update action
     */
    public function remove_bulk_update_action($actions) {
        if (isset($actions['update-selected'])) {
            unset($actions['update-selected']);
            $actions['rpcare_note'] = 'Las actualizaciones están gestionadas por Replanta Care';
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
                <p><strong>🛡️ Replanta Care - Control de Actualizaciones</strong></p>
                <p>Las actualizaciones de plugins están gestionadas automáticamente por Replanta según tu plan <strong>' . esc_html($plan_name) . '</strong>. Los plugins con licencia pueden actualizarse libremente.</p>
                <p><em>Esto garantiza la estabilidad y seguridad de tu sitio web.</em></p>
            </div>';
        }
    }
    
    /**
     * Hide update notices for controlled plugins
     */
    public function hide_update_notices() {
        $screen = get_current_screen();
        
        if ($screen->id === 'plugins') {
            echo '<style>
                .update-message:not(.rpcare-allowed) {
                    display: none !important;
                }
                tr.plugin-update-tr:not(.rpcare-allowed) {
                    display: none !important;
                }
                .plugins-php .notice.notice-warning,
                .plugins-php .error {
                    display: none !important;
                }
            </style>';
        }
    }
    
    /**
     * AJAX handler to check if a plugin is licensed
     */
    public function ajax_check_licensed_plugin() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('update_plugins')) {
            wp_die('Insufficient permissions');
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
