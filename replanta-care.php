<?php
/**
 * Plugin Name: Replanta Care
 * Plugin URI: https://replanta.dev
 * Description: Plugin de mantenimiento WordPress automático para clientes de Replanta
 * Version: 1.0.9
 * Author: Replanta
 * Author URI: https://replanta.dev
 * License: GPL v2 or later
 * Text Domain: replanta-care
 * Network: false
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('RPCARE_VERSION', '1.0.9');
define('RPCARE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RPCARE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('RPCARE_PLUGIN_FILE', __FILE__);

// Auto-updates from GitHub
if (file_exists(RPCARE_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once RPCARE_PLUGIN_PATH . 'vendor/autoload.php';
    
    try {
        $updateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/replantadev/care/',
            __FILE__,
            'replanta-care'
        );
    } catch (Exception $e) {
        // Silently fail if update checker can't be initialized
        error_log('Replanta Care: Update checker failed to initialize - ' . $e->getMessage());
    }
}

// Main plugin class
class ReplantaCare {
    
    private static $instance = null;
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    public function init() {
        // Hook into WordPress
        add_action('init', [$this, 'load_textdomain']);
        add_action('init', [$this, 'init_components']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('admin_bar_menu', [$this, 'add_admin_bar_menu'], 999);
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Load required files
        $this->load_dependencies();
    }
    
    public function load_textdomain() {
        load_plugin_textdomain('replanta-care', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    public function load_dependencies() {
        $required_files = [
            // Core classes
            'inc/class-plan.php',
            'inc/class-scheduler.php',
            'inc/class-tasks.php',
            'inc/class-security.php',
            'inc/class-rest.php',
            'inc/class-utils.php',
            
            // Task classes
            'inc/task-updates.php',
            'inc/task-wpo.php',
            'inc/task-seo.php',
            'inc/task-404.php',
            'inc/task-health.php',
            'inc/task-report.php',
            'inc/task-security.php',
            
            // Integration classes
            'inc/integrations-cache.php',
            'inc/integrations-backup.php',
            
            // Admin page
            'inc/settings-page.php'
        ];
        
        foreach ($required_files as $file) {
            $file_path = RPCARE_PLUGIN_PATH . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                error_log("Replanta Care: Missing required file - {$file}");
            }
        }
    }
    
    public function init_components() {
        try {
            // Initialize scheduler based on plan
            if (class_exists('RP_Care_Plan')) {
                $plan = RP_Care_Plan::get_current();
                if ($plan && $this->is_activated() && class_exists('RP_Care_Scheduler')) {
                    $scheduler = new RP_Care_Scheduler($plan);
                    $scheduler->ensure();
                }
            }
            
            // Initialize REST API
            if (class_exists('RP_Care_REST')) {
                new RP_Care_REST();
            }
            
            // Initialize 404 logger
            if (class_exists('RP_Care_Task_404')) {
                new RP_Care_Task_404();
            }
            
            // Initialize admin settings page
            if (is_admin() && class_exists('RP_Care_Settings_Page')) {
                RP_Care_Settings_Page::get_instance();
            }
        } catch (Exception $e) {
            error_log('Replanta Care: Component initialization error - ' . $e->getMessage());
        }
    }
    
    public function enqueue_admin_assets($hook) {
        if ($hook !== 'settings_page_replanta-care') {
            return;
        }
        
        wp_enqueue_style(
            'replanta-care-admin',
            RPCARE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            RPCARE_VERSION
        );
        
        wp_enqueue_script(
            'replanta-care-admin',
            RPCARE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            RPCARE_VERSION,
            true
        );
        
        wp_localize_script('replanta-care-admin', 'rpcare_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rpcare_ajax'),
            'strings' => [
                'testing_connection' => 'Probando conexión...',
                'connection_success' => 'Conexión exitosa',
                'connection_failed' => 'Error de conexión',
                'running_task' => 'Ejecutando tarea...',
                'task_completed' => 'Tarea completada',
                'task_failed' => 'Error en la tarea'
            ]
        ]);
    }
    
    public function enqueue_frontend_assets() {
        if (is_admin_bar_showing()) {
            wp_add_inline_style('admin-bar', '
                #wp-admin-bar-replanta-care .ab-icon {
                    float: left !important;
                    margin-right: 8px !important;
                }
                #wp-admin-bar-replanta-care .ab-label {
                    color: #4CAF50 !important;
                    font-weight: 600 !important;
                }
                #wp-admin-bar-replanta-care:hover .ab-label {
                    color: #81C784 !important;
                }
                #wp-admin-bar-replanta-care-dashboard .ab-item {
                    background: #4CAF50 !important;
                    color: white !important;
                    border-radius: 3px !important;
                    margin: 2px !important;
                }
                #wp-admin-bar-replanta-care-dashboard:hover .ab-item {
                    background: #2E7D32 !important;
                }
            ');
        }
    }
    
    public function add_admin_bar_menu($wp_admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $plan = get_option('rpcare_plan', '');
        $is_activated = $this->is_activated();
        
        if (!$is_activated) {
            return;
        }
        
        // Get plan display name
        $plan_names = [
            'basic' => 'Básico',
            'advanced' => 'Avanzado', 
            'premium' => 'Premium'
        ];
        $plan_name = isset($plan_names[$plan]) ? $plan_names[$plan] : 'Activo';
        
        // Main menu item
        $wp_admin_bar->add_menu([
            'id' => 'replanta-care',
            'title' => '<span class="ab-icon" style="background: url(' . RPCARE_PLUGIN_URL . 'assets/img/ico.png) center/16px no-repeat; width: 20px; height: 20px; margin-top: 6px;"></span><span class="ab-label">Mantenimiento Activo</span>',
            'href' => admin_url('options-general.php?page=replanta-care'),
            'meta' => [
                'title' => 'Replanta Care - Mantenimiento Automático'
            ]
        ]);
        
        // Submenu items
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-status',
            'title' => '✅ Protegido por Replanta',
            'href' => false
        ]);
        
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-plan',
            'title' => "📋 Plan: {$plan_name}",
            'href' => false
        ]);
        
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-features',
            'title' => '🔧 Características activas:',
            'href' => false
        ]);
        
        // Plan features
        $features = $this->get_plan_features($plan);
        foreach ($features as $feature) {
            $wp_admin_bar->add_menu([
                'parent' => 'replanta-care',
                'id' => 'replanta-care-feature-' . sanitize_title($feature),
                'title' => "  • {$feature}",
                'href' => false
            ]);
        }
        
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-dashboard',
            'title' => '🎛️ Acceder al Dashboard',
            'href' => admin_url('options-general.php?page=replanta-care'),
            'meta' => [
                'class' => 'rpcare-dashboard-link'
            ]
        ]);
    }
    
    private function get_plan_features($plan) {
        $features = [
            'basic' => [
                'Actualizaciones automáticas',
                'Monitoreo de seguridad básico',
                'Reportes mensuales'
            ],
            'advanced' => [
                'Actualizaciones automáticas',
                'Monitoreo de seguridad avanzado', 
                'Optimización de rendimiento',
                'Backups automáticos',
                'Reportes semanales'
            ],
            'premium' => [
                'Actualizaciones automáticas',
                'Monitoreo de seguridad completo',
                'Optimización avanzada',
                'Backups diarios',
                'Soporte prioritario',
                'Reportes en tiempo real'
            ]
        ];
        
        return isset($features[$plan]) ? $features[$plan] : ['Mantenimiento básico'];
    }
    
    public function activate() {
        // Create necessary database tables if needed
        $this->create_tables();
        
        // Set default options
        add_option('rpcare_version', RPCARE_VERSION);
        add_option('rpcare_activated', false);
        add_option('rpcare_plan', '');
        add_option('rpcare_token', '');
        add_option('rpcare_hub_url', 'https://sitios.replanta.dev');
        
        // Schedule initial check
        if (!wp_next_scheduled('rpcare_daily_check')) {
            wp_schedule_event(time() + 3600, 'daily', 'rpcare_daily_check');
        }
    }
    
    public function deactivate() {
        // Clear all scheduled tasks
        wp_clear_scheduled_hook('rpcare_task_updates');
        wp_clear_scheduled_hook('rpcare_task_backup');
        wp_clear_scheduled_hook('rpcare_task_wpo');
        wp_clear_scheduled_hook('rpcare_task_review');
        wp_clear_scheduled_hook('rpcare_task_monitor');
        wp_clear_scheduled_hook('rpcare_daily_check');
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Task logs table
        $table_name = $wpdb->prefix . 'rpcare_logs';
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            task_type varchar(50) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            data longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY task_type (task_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // 404 logs table
        $table_404 = $wpdb->prefix . 'rpcare_404_logs';
        $sql404 = "CREATE TABLE $table_404 (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            url varchar(500) NOT NULL,
            hits int(11) DEFAULT 1,
            first_seen datetime DEFAULT CURRENT_TIMESTAMP,
            last_seen datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            suggested_redirect varchar(500),
            status varchar(20) DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY url (url),
            KEY hits (hits),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql404);
    }
    
    public function is_activated() {
        return get_option('rpcare_activated', false) && 
               get_option('rpcare_token', '') !== '' && 
               get_option('rpcare_plan', '') !== '';
    }
}

// Initialize the plugin
ReplantaCare::getInstance();
