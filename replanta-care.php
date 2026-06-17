<?php
/**
 * Plugin Name: Replanta Care
 * Plugin URI: https://replanta.dev
 * Description: Plugin de mantenimiento WordPress automático para clientes de Replanta con integración Hub
 * Version: 1.13.1
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
define('RPCARE_VERSION', '1.13.1');
define('RPCARE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RPCARE_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('RPCARE_PLUGIN_FILE', __FILE__);

if (!defined('RPCARE_GITHUB_REPO_URL')) {
    define('RPCARE_GITHUB_REPO_URL', 'https://github.com/replantadev/care/');
}

// Update metadata served by the Hub (no GitHub token required on client sites)
if (!defined('RPCARE_UPDATE_URL')) {
    define('RPCARE_UPDATE_URL', 'https://replanta.net/wp-json/replanta-hub/v1/updates/care');
}

if (!defined('RPCARE_GITHUB_BRANCH')) {
    define('RPCARE_GITHUB_BRANCH', 'main');
}

// Load secure configuration if available (config.php preferred, config-sample.php as fallback)
$config_file = RPCARE_PLUGIN_PATH . 'config.php';
$sample_file = RPCARE_PLUGIN_PATH . 'config-sample.php';
if (file_exists($config_file)) {
    require_once $config_file;
} elseif (file_exists($sample_file)) {
    require_once $sample_file;
}

// Load Action Scheduler (bundled; defers to WooCommerce copy if newer)
if (file_exists(RPCARE_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php')) {
    require_once RPCARE_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
}

// Auto-updates via Hub (Hub fetches from GitHub and serves the zip — no token needed on client sites)
if (file_exists(RPCARE_PLUGIN_PATH . 'vendor/autoload.php')) {
    require_once RPCARE_PLUGIN_PATH . 'vendor/autoload.php';

    try {
        if (class_exists('\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory')) {
            \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                RPCARE_UPDATE_URL,
                __FILE__,
                'replanta-care'
            );
        }
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log('Replanta Care: Update checker failed - ' . $e->getMessage());
        }
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
        
        // AJAX actions
        add_action('wp_ajax_rpcare_force_backup', [$this, 'ajax_force_backup']);
        add_action('wp_ajax_rpcare_regenerate_token', [$this, 'ajax_regenerate_token']);
        
        // Daily check hook â†’ also run maintenance cleanup
        add_action('rpcare_daily_check', ['RP_Care_Utils', 'cleanup_all']);
        
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
            'inc/class-update-control.php',
            'inc/class-dashboard.php',
            'inc/class-metrics.php',
            'inc/class-addon-manager.php',

            // Task classes
            'inc/task-updates.php',
            'inc/task-wpo.php',
            'inc/task-seo.php',
            'inc/task-404.php',
            'inc/task-health.php',
            'inc/task-report.php',
            'inc/task-security.php',
            'inc/task-cwv.php',
            'inc/task-cloudflare.php',
            'inc/task-anomaly.php',
            'inc/task-staging.php',
            'inc/task-orphan-media.php',

            // eCommerce addon tasks
            'inc/task-checkout-monitor.php',
            'inc/task-peak-scheduler.php',
            'inc/task-revenue-anomaly.php',
            
            // Integration classes
            'inc/integrations-cache.php',
            'inc/integrations-backup.php',
            
            // Admin pages (portal must load before settings-page registers its submenu)
            'inc/class-client-portal.php',
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
        
        // Enqueue admin assets (dashboard widget only — settings page has its own enqueue)
        // Note: do NOT re-add here; init() already registered this action above.
    }
    
    public function init_components() {
        try {
            // Addon Manager must boot before the scheduler (scheduler reads addon state)
            if (class_exists('RP_Care_Addon_Manager')) {
                RP_Care_Addon_Manager::get();
            }

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
            
            // Initialize Dashboard
            if (class_exists('RP_Care_Dashboard')) {
                new RP_Care_Dashboard();
            }
            
            // Initialize 404 logger
            if (class_exists('RP_Care_Task_404')) {
                new RP_Care_Task_404();
            }
            
            // Initialize client portal + admin settings page
            if (is_admin()) {
                if (class_exists('RP_Care_Client_Portal')) {
                    RP_Care_Client_Portal::getInstance();
                }
                if (class_exists('RP_Care_Settings_Page')) {
                    RP_Care_Settings_Page::get_instance();
                }
            }
        } catch (Exception $e) {
            error_log('Replanta Care: Component initialization error - ' . $e->getMessage());
        }
    }
    
    public function enqueue_admin_assets($hook) {
        // Settings page assets are handled by RP_Care_Settings_Page::enqueue_admin_scripts().
        // This function only loads assets for OTHER admin pages.

        // Load dashboard assets on main dashboard page
        if ($hook === 'index.php') {
            wp_enqueue_style(
                'rpcare-dashboard',
                RPCARE_PLUGIN_URL . 'assets/css/dashboard.css',
                array(),
                RPCARE_VERSION
            );
            
            wp_enqueue_script(
                'rpcare-dashboard',
                RPCARE_PLUGIN_URL . 'assets/js/dashboard.js',
                array('jquery'),
                RPCARE_VERSION,
                true
            );
            
            wp_localize_script('rpcare-dashboard', 'rpcare_ajax', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rpcare_ajax'),
                'strings' => array(
                    'loading' => __('Loading...', 'replanta-care'),
                    'error' => __('An error occurred', 'replanta-care'),
                    'success' => __('Operation completed successfully', 'replanta-care'),
                    'confirm' => __('Are you sure?', 'replanta-care')
                )
            ));
        }
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
        
        $is_activated = $this->is_activated();
        
        if (!$is_activated) {
            return;
        }

        // Use the same plan detection logic as settings page
        $current_plan = RP_Care_Plan::get_current();
        $plan_config = RP_Care_Plan::get_plan_config($current_plan);
        $hub_connected = get_option('rpcare_hub_connected', false);
        
        // Get plan display name
        if ($hub_connected && !empty($current_plan)) {
            $plan_name = $plan_config['name'] ?? 'Plan no detectado';
        } else {
            $plan_name = 'Detectando...';
        }
        
        // Show hub connection status
        $status_text = $hub_connected ? 'Conectado al Hub' : 'Detectando...';
        
        // Main menu item — logo + green/grey pilot dot reflecting Hub status
        $dot_color = $hub_connected ? '#34D399' : '#9CA3AF';
        $dot_shadow = $hub_connected ? '0 0 0 3px rgba(52,211,153,.25)' : '0 0 0 3px rgba(156,163,175,.18)';
        $wp_admin_bar->add_menu([
            'id' => 'replanta-care',
            'title' => '<span class="ab-icon" style="background: url(' . RPCARE_PLUGIN_URL . 'assets/img/ico.png) center/16px no-repeat; width: 20px; height: 20px; margin-top: 6px;"></span><span class="ab-label">Mantenimiento Activo</span><span class="rpc-ab-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . $dot_color . ';box-shadow:' . $dot_shadow . ';margin-left:8px;vertical-align:middle;"></span>',
            'href' => admin_url('admin.php?page=replanta-care-portal'),
            'meta' => [
                'title' => 'Replanta Care - Mantenimiento Automático'
            ]
        ]);
        
        // Submenu items
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-status',
            'title' => $status_text,
            'href' => false
        ]);
        
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-plan',
            'title' => "Plan: {$plan_name}",
            'href' => false
        ]);
        
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-features',
            'title' => 'Características activas',
            'href' => false
        ]);
        
        // Plan features
        $features = $this->get_plan_features($current_plan);
        foreach ($features as $feature) {
            $wp_admin_bar->add_menu([
                'parent' => 'replanta-care',
                'id' => 'replanta-care-feature-' . sanitize_title($feature),
                'title' => "· {$feature}",
                'href' => false
            ]);
        }
        
        $wp_admin_bar->add_menu([
            'parent' => 'replanta-care',
            'id' => 'replanta-care-dashboard',
            'title' => '<span class="ab-icon" style="background: url(' . RPCARE_PLUGIN_URL . 'assets/img/ico.png) center/16px no-repeat;width:16px;height:16px;display:inline-block;vertical-align:-3px;margin-right:6px;"></span>Abrir panel de mantenimiento',
            'href' => admin_url('admin.php?page=replanta-care-portal'),
            'meta' => [
                'class' => 'rpcare-dashboard-link'
            ]
        ]);
    }
    
    private function get_plan_features($plan) {
        if (empty($plan)) {
            return ['Mantenimiento bÃ¡sico'];
        }
        
        // Use RP_Care_Plan to get features
        $features = RP_Care_Plan::get_features($plan);
        $feature_list = [];
        
        if ($features['automatic_updates']) {
            $feature_list[] = 'Actualizaciones automÃ¡ticas';
        }
        
        if ($features['backup']) {
            $frequency = ucfirst($features['backup_frequency']);
            $feature_list[] = "Backups {$frequency}";
        }
        
        if ($features['security_monitoring']) {
            $feature_list[] = 'Monitoreo de seguridad';
        }
        
        if ($features['performance_optimization']) {
            $feature_list[] = 'OptimizaciÃ³n de rendimiento';
        }
        
        if ($features['priority_support']) {
            $feature_list[] = 'Soporte prioritario';
        }
        
        return !empty($feature_list) ? $feature_list : ['Mantenimiento bÃ¡sico'];
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
        
        // Schedule initial check via Action Scheduler (falls back to WP Cron)
        $this->rpcare_maybe_schedule('rpcare_daily_check',       'daily',  3600);
        $this->rpcare_maybe_schedule('rpcare_task_maintenance',  'daily',  7200);
    }
    
    public function deactivate() {
        // Clear all scheduled tasks (Action Scheduler + WP Cron fallback)
        $hooks = [
            'rpcare_task_updates', 'rpcare_task_backup', 'rpcare_task_wpo',
            'rpcare_task_seo_review', 'rpcare_task_seo_audit', 'rpcare_task_basic_review',
            'rpcare_task_monitor', 'rpcare_task_health', 'rpcare_task_404_cleanup',
            'rpcare_task_maintenance', 'rpcare_task_report', 'rpcare_task_cwv',
            'rpcare_task_anomaly',
            'rpcare_daily_check', 'rpcare_hourly_monitor', 'rpcare_clear_cache',
        ];
        foreach ($hooks as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, [], 'replanta-care');
            }
            wp_clear_scheduled_hook($hook);
        }
    }

    /**
     * Helper: schedule a recurring action once (AS-aware).
     */
    private function rpcare_maybe_schedule(string $hook, string $recurrence, int $delay = 0): void {
        $intervals = [
            'daily'   => DAY_IN_SECONDS,
            'weekly'  => WEEK_IN_SECONDS,
            'monthly' => 30 * DAY_IN_SECONDS,
        ];
        if (function_exists('as_next_scheduled_action')) {
            if (!as_next_scheduled_action($hook, [], 'replanta-care')) {
                $interval = $intervals[$recurrence] ?? DAY_IN_SECONDS;
                as_schedule_recurring_action(time() + $delay, $interval, $hook, [], 'replanta-care');
            }
        } elseif (!wp_next_scheduled($hook)) {
            wp_schedule_event(time() + $delay, $recurrence, $hook);
        }
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
            referer varchar(500) DEFAULT '',
            user_agent text,
            ip varchar(45) DEFAULT '',
            suggested_redirect varchar(500),
            suggestion_score decimal(5,2) DEFAULT NULL,
            status varchar(20) DEFAULT 'pending',
            PRIMARY KEY (id),
            UNIQUE KEY url (url),
            KEY hits (hits),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql404);
        
        // Add missing columns to existing 404 table (for upgrades)
        global $wpdb;
        $table_404 = $wpdb->prefix . 'rpcare_404_logs';
        
        // Check if referer column exists, if not add it
        $referer_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_404` LIKE 'referer'");
        if (empty($referer_column)) {
            $wpdb->query("ALTER TABLE `$table_404` ADD COLUMN `referer` varchar(500) DEFAULT '' AFTER `last_seen`");
        }
        
        // Check if user_agent column exists, if not add it
        $user_agent_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_404` LIKE 'user_agent'");
        if (empty($user_agent_column)) {
            $wpdb->query("ALTER TABLE `$table_404` ADD COLUMN `user_agent` text AFTER `referer`");
        }
        
        // Check if ip column exists, if not add it
        $ip_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_404` LIKE 'ip'");
        if (empty($ip_column)) {
            $wpdb->query("ALTER TABLE `$table_404` ADD COLUMN `ip` varchar(45) DEFAULT '' AFTER `user_agent`");
        }
        
        // Check if suggestion_score column exists, if not add it
        $suggestion_score_column = $wpdb->get_results("SHOW COLUMNS FROM `$table_404` LIKE 'suggestion_score'");
        if (empty($suggestion_score_column)) {
            $wpdb->query("ALTER TABLE `$table_404` ADD COLUMN `suggestion_score` decimal(5,2) DEFAULT NULL AFTER `suggested_redirect`");
        }
    }
    
    public function is_activated() {
        // Auto-activation through hub detection
        $plan = get_option('rpcare_plan', '');
        $hub_connected = get_option('rpcare_hub_connected', false);
        
        if ($plan && $hub_connected) {
            return true;
        }
        
        // Try to detect from hub
        if (class_exists('RP_Care_Plan')) {
            $detected_plan = RP_Care_Plan::get_current();
            if ($detected_plan) {
                return true;
            }
        }
        
        // Fallback to manual activation
        return get_option('rpcare_activated', false) && 
               get_option('rpcare_token', '') !== '' && 
               $plan !== '';
    }

    /**
     * AJAX handler — regenerate the Care REST API token and return it.
     * Used by the admin to obtain a fresh token to paste into Hub.
     */
    public function ajax_regenerate_token() {
        check_ajax_referer('rpcare_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }

        $new_token = RP_Care_Security::generate_token();
        update_option('rpcare_token', $new_token);

        wp_send_json_success([
            'token' => $new_token,
            'message' => 'Token regenerado correctamente. Copia este token y pégalo en Replanta Hub para el sitio correspondiente.',
        ]);
    }

    /**
     * AJAX handler for force backup
     */
    public function ajax_force_backup() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        // Check if backup is available for this plan
        $plan = RP_Care_Plan::get_current();
        $features = RP_Care_Plan::get_features($plan);
        
        if (!$features['backup']) {
            wp_send_json_error('Backup no disponible en tu plan');
        }
        
        // Run backup
        if (class_exists('RP_Care_Task_Backup')) {
            $result = RP_Care_Task_Backup::run();
            
            if ($result && $result['success']) {
                wp_send_json_success('Backup creado exitosamente');
            } else {
                $message = isset($result['message']) ? $result['message'] : 'Error al crear backup';
                wp_send_json_error($message);
            }
        } else {
            wp_send_json_error('Sistema de backup no disponible');
        }
    }
}

// Initialize the plugin
ReplantaCare::getInstance();

register_uninstall_hook(__FILE__, 'rpcare_uninstall');
function rpcare_uninstall() {
    global $wpdb;
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rpcare_logs");
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}rpcare_404s");
    $options = $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'rpcare_%'");
    foreach ($options as $opt) {
        delete_option($opt);
    }
    delete_transient('rpcare_plan');
    wp_clear_scheduled_hook('rpcare_daily_check');
    wp_clear_scheduled_hook('rpcare_hourly_monitor');
}


