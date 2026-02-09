<?php
/**
 * Replanta Care - Plugin Settings Page
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Settings_Page {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_rpcare_test_connection', [$this, 'test_hub_connection']);
        add_action('wp_ajax_rpcare_run_task', [$this, 'run_task_manually']);
        add_action('wp_ajax_rpcare_get_status', [$this, 'get_status_ajax']);
        add_action('wp_ajax_rpcare_get_metric_details', [$this, 'get_metric_details_ajax']);
        
        // Hide other plugin notices on our settings page
        add_action('admin_head', [$this, 'hide_other_plugin_notices']);
    }
    
    public function add_admin_menu() {
        add_options_page(
            'Replanta Care',
            'Replanta Care',
            'manage_options',
            'replanta-care',
            [$this, 'settings_page']
        );
    }
    
    public function register_settings() {
        register_setting('rpcare_settings', 'rpcare_options', [$this, 'sanitize_options']);
        
        // General Settings Section
        add_settings_section(
            'rpcare_general',
            'Configuración General',
            [$this, 'general_section_callback'],
            'rpcare_settings'
        );
        
        add_settings_field(
            'hub_url',
            'URL del Hub',
            [$this, 'hub_url_field'],
            'rpcare_settings',
            'rpcare_general'
        );
        
        add_settings_field(
            'site_token',
            'Token del Sitio',
            [$this, 'site_token_field'],
            'rpcare_settings',
            'rpcare_general'
        );
        
        add_settings_field(
            'current_plan',
            'Plan Actual',
            [$this, 'current_plan_field'],
            'rpcare_settings',
            'rpcare_general'
        );
        
        // Tasks Section
        add_settings_section(
            'rpcare_tasks',
            'Configuración de Tareas',
            [$this, 'tasks_section_callback'],
            'rpcare_settings'
        );
        
        add_settings_field(
            'auto_updates',
            'Actualizaciones Automáticas',
            [$this, 'auto_updates_field'],
            'rpcare_settings',
            'rpcare_tasks'
        );
        
        add_settings_field(
            'backup_enabled',
            'Copias de Seguridad',
            [$this, 'backup_enabled_field'],
            'rpcare_settings',
            'rpcare_tasks'
        );
        
        add_settings_field(
            'cache_clearing',
            'Limpieza de Caché',
            [$this, 'cache_clearing_field'],
            'rpcare_settings',
            'rpcare_tasks'
        );
        
        add_settings_field(
            'security_monitoring',
            'Monitoreo de Seguridad',
            [$this, 'security_monitoring_field'],
            'rpcare_settings',
            'rpcare_tasks'
        );
        
        // Notifications Section
        add_settings_section(
            'rpcare_notifications',
            'Notificaciones',
            [$this, 'notifications_section_callback'],
            'rpcare_settings'
        );
        
        add_settings_field(
            'notification_email',
            'Email para Notificaciones',
            [$this, 'notification_email_field'],
            'rpcare_settings',
            'rpcare_notifications'
        );
        
        add_settings_field(
            'notification_types',
            'Tipos de Notificaciones',
            [$this, 'notification_types_field'],
            'rpcare_settings',
            'rpcare_notifications'
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        // Debug: log the current hook
        error_log("Replanta Care: Hook actual = $hook");
        error_log("Replanta Care: GET page = " . ($_GET['page'] ?? 'no-page'));
        
        // Load scripts on Replanta Care admin pages - more permissive check
        $should_load = false;
        
        // Check if it's our settings page
        if ($hook === 'settings_page_replanta-care' || 
            strpos($hook, 'replanta-care') !== false ||
            (isset($_GET['page']) && $_GET['page'] === 'replanta-care')) {
            $should_load = true;
        }
        
        // If we're not sure, load it anyway on admin pages for safety
        if (!$should_load && is_admin()) {
            $current_screen = get_current_screen();
            if ($current_screen && strpos($current_screen->id, 'replanta') !== false) {
                $should_load = true;
            }
        }
        
        if (!$should_load) {
            return;
        }
        
        error_log("Replanta Care: Cargando scripts admin");
        
        wp_enqueue_script(
            'rpcare-admin',
            RPCARE_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            RPCARE_VERSION,
            true
        );
        
        wp_enqueue_style(
            'rpcare-admin',
            RPCARE_PLUGIN_URL . 'assets/css/admin.css',
            [],
            RPCARE_VERSION
        );
        
        wp_localize_script('rpcare-admin', 'rpcare_ajax', [
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
        
        // Add inline CSS for plan detection display
        $css = "
        .rpcare-plan-detected {
            background: #e7f5e7;
            border: 1px solid #46b450;
            border-radius: 4px;
            padding: 10px;
            margin-bottom: 10px;
        }
        .rpcare-plan-detected strong {
            color: #135e96;
            font-size: 16px;
        }
        .rpcare-plan-detected .plan-price {
            color: #666;
            margin-left: 10px;
        }
        
        /* Plan Selection Cards */
        .rpcare-plans-wrapper {
            max-width: 1200px;
            margin: 20px 0;
        }
        .rpcare-plans-intro {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            border-radius: 6px;
            padding: 15px;
            margin-bottom: 20px;
            color: #856404;
        }
        .rpcare-plans-cards {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 20px;
        }
        .rpcare-plan-card {
            flex: 1;
            min-width: 300px;
            background: #fff;
            border: 2px solid #e1e5e9;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.3s ease;
            position: relative;
        }
        .rpcare-plan-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
        }
        .rpcare-plan-card.selected {
            border-color: #0073aa;
            box-shadow: 0 0 0 1px #0073aa;
        }
        .rpcare-plan-card.plan-featured {
            border-color: #d63384;
            position: relative;
        }
        .rpcare-plan-card.plan-featured .featured-label {
            position: absolute;
            top: 15px;
            right: 15px;
            background: #d63384;
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 600;
        }
        .plan-header {
            padding: 25px 20px 20px;
            text-align: center;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        }
        .plan-header h3 {
            margin: 0 0 8px;
            font-size: 24px;
            font-weight: 700;
            color: #2c3e50;
        }
        .plan-sub {
            margin: 0 0 15px;
            color: #6c757d;
            font-size: 14px;
        }
        .plan-price {
            font-size: 28px;
            font-weight: 700;
            color: #0073aa;
        }
        .plan-price small {
            font-size: 16px;
            color: #6c757d;
            font-weight: 400;
        }
        .plan-features {
            list-style: none;
            padding: 20px;
            margin: 0;
        }
        .plan-features li {
            display: flex;
            align-items: flex-start;
            padding: 8px 0;
            font-size: 14px;
            line-height: 1.5;
        }
        .check-icon {
            color: #28a745;
            margin-right: 10px;
            font-weight: 600;
            flex-shrink: 0;
        }
        .plan-cta {
            padding: 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .plan-selector {
            cursor: pointer;
            display: block;
        }
        .plan-selector input[type='radio'] {
            display: none;
        }
        .plan-btn {
            display: block;
            width: 100%;
            padding: 12px 20px;
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            color: #495057;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .plan-btn:hover, .plan-selector:hover .plan-btn {
            background: #e9ecef;
            border-color: #0073aa;
            color: #0073aa;
            text-decoration: none;
        }
        .plan-btn.primary {
            background: #0073aa;
            border-color: #0073aa;
            color: white;
        }
        .plan-btn.primary:hover, .plan-selector:hover .plan-btn.primary {
            background: #005a87;
            border-color: #005a87;
            color: white;
        }
        .plan-selector input[type='radio']:checked + .plan-btn {
            background: #0073aa;
            border-color: #0073aa;
            color: white;
        }
        .plan-btn-external {
            display: block;
            padding: 8px 20px;
            background: transparent;
            border: 1px solid #dee2e6;
            border-radius: 4px;
            color: #6c757d;
            text-decoration: none;
            text-align: center;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        .plan-btn-external:hover {
            background: #f8f9fa;
            color: #495057;
            text-decoration: none;
        }
        
        @media (max-width: 768px) {
            .rpcare-plans-cards {
                flex-direction: column;
            }
            .rpcare-plan-card {
                min-width: auto;
            }
        }
        ";
        wp_add_inline_style('rpcare-admin', $css);
    }
    
    public function hide_other_plugin_notices() {
        global $pagenow;
        
        // Only on our settings page
        if ($pagenow === 'options-general.php' && isset($_GET['page']) && $_GET['page'] === 'replanta-care') {
            // Remove all admin notices except WordPress core ones
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
            
            // Re-add only WordPress core notices
            add_action('admin_notices', 'settings_errors');
        }
    }
    
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $options = get_option('rpcare_options', []);
        $current_plan = RP_Care_Plan::get_current_plan();
        $plan_features = RP_Care_Plan::get_plan_features($current_plan);
        
        ?>
        <div class="wrap">
            <div class="rpcare-header">
                <div class="rpcare-logo">
                    <img src="<?php echo RPCARE_PLUGIN_URL . 'assets/img/ico.png'; ?>" alt="Replanta" class="logo-icon">
                    <h1>Replanta Care</h1>
                    <span class="version">v<?php echo RPCARE_VERSION; ?></span>
                </div>
            </div>
            
            <?php settings_errors('rpcare_messages'); ?>
            
            <?php if (isset($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><span class="dashicons dashicons-yes-alt"></span> Configuración guardada correctamente.</p>
                </div>
            <?php endif; ?>
            
            <div class="rpcare-admin-header">
                <div class="rpcare-plan-card">
                    <div class="plan-header">
                        <div class="plan-icon">
                            <span class="dashicons dashicons-admin-plugins"></span>
                        </div>
                        <div>
                            <h2>Plan <?php echo esc_html(ucfirst($current_plan)); ?></h2>
                            <p class="plan-subtitle">Mantenimiento profesional WordPress</p>
                        </div>
                    </div>
                    
                    <ul class="rpcare-plan-features">
                        <?php foreach ($plan_features as $feature => $enabled): ?>
                            <li class="<?php echo $enabled ? 'feature-enabled' : 'feature-disabled'; ?>" data-feature="<?php echo esc_attr($feature); ?>">
                                <span class="feature-icon"><?php echo $enabled ? '✓' : '✗'; ?></span>
                                <?php echo esc_html(self::get_feature_label($feature)); ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                
                <div class="rpcare-status-card">
                    <h3><span class="dashicons dashicons-dashboard"></span> Estado del Sistema</h3>
                    <div class="status-metrics">
                        <?php $this->display_system_status(); ?>
                    </div>
                    
                    <div class="health-score-container">
                        <?php $health_score = get_option('rpcare_health_score', 85); ?>
                        <div class="health-score-circle" style="--health-angle: <?php echo ($health_score * 3.6); ?>deg;">
                            <span class="health-score"><?php echo $health_score; ?>%</span>
                        </div>
                        <div class="health-label">Salud del Sitio</div>
                    </div>
                </div>
            </div>
            
            <form method="post" action="options.php" class="rpcare-settings-form">
                <?php
                settings_fields('rpcare_settings');
                do_settings_sections('rpcare_settings');
                ?>
                
                <div class="rpcare-form-actions">
                    <?php submit_button('Guardar Configuración', 'primary large', 'submit', false, [
                        'style' => 'background: linear-gradient(135deg, var(--replanta-primary), var(--replanta-secondary)); border: none; padding: 12px 24px; border-radius: 8px;'
                    ]); ?>
                </div>
            </form>
            
            <div class="rpcare-manual-tasks">
                <h3><span class="dashicons dashicons-admin-tools"></span> Ejecutar Tareas Manualmente</h3>
                <p class="description">Ejecuta tareas de mantenimiento de forma inmediata sin esperar a la programación automática.</p>
                
                <div class="rpcare-task-buttons">
                    <button type="button" class="button" data-task="updates">
                        <span class="dashicons dashicons-update"></span>
                        Comprobar Actualizaciones
                    </button>
                    <button type="button" class="button" data-task="backup">
                        <span class="dashicons dashicons-backup"></span>
                        Crear Copia de Seguridad
                    </button>
                    <button type="button" class="button" data-task="cache">
                        <span class="dashicons dashicons-performance"></span>
                        Limpiar Caché
                    </button>
                    <button type="button" class="button" data-task="security">
                        <span class="dashicons dashicons-shield"></span>
                        Escaneo de Seguridad
                    </button>
                    <button type="button" class="button" data-task="health">
                        <span class="dashicons dashicons-heart"></span>
                        Chequeo de Salud
                    </button>
                    <button type="button" class="button" data-task="report">
                        <span class="dashicons dashicons-chart-line"></span>
                        Generar Reporte
                    </button>
                </div>
                
                <div id="rpcare-task-results"></div>
            </div>
            
            <div class="rpcare-logs">
                <h3><span class="dashicons dashicons-list-view"></span> Registro de Actividad</h3>
                <div class="logs-controls">
                    <button type="button" class="button task-history-toggle">Mostrar Historial Completo</button>
                </div>
                <?php $this->display_recent_logs(); ?>
                <div class="task-history-container" style="display: none;">
                    <div class="task-history-content"></div>
                </div>
            </div>
        </div>
        
        <style>
        .rpcare-header {
            background: linear-gradient(135deg, var(--replanta-primary), var(--replanta-secondary));
            color: white;
            padding: 20px 24px;
            margin: 0 -20px 24px -20px;
            border-radius: 0 0 12px 12px;
        }
        
        .rpcare-logo {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .rpcare-logo .logo-icon {
            width: 32px;
            height: 32px;
        }
        
        .rpcare-logo h1 {
            margin: 0;
            font-size: 24px;
            color: white;
        }
        
        .rpcare-logo .version {
            background: rgba(255,255,255,0.2);
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .health-label {
            font-size: 12px;
            color: var(--replanta-text-secondary);
            margin-top: 8px;
        }
        
        .rpcare-form-actions {
            background: var(--replanta-surface);
            padding: 24px;
            border-radius: var(--replanta-radius-large);
            box-shadow: var(--replanta-shadow);
            border: 1px solid var(--replanta-border);
            margin-top: 24px;
            text-align: center;
        }
        
        .logs-controls {
            margin-bottom: 16px;
        }
        
        .task-history-container {
            margin-top: 16px;
            padding: 16px;
            background: var(--replanta-bg);
            border-radius: var(--replanta-radius);
            border: 1px solid var(--replanta-border);
        }
        </style>
        <?php
    }
    
    // Section Callbacks
    public function general_section_callback() {
        echo '<p>Configuración básica de conexión con el Hub de Replanta.</p>';
    }
    
    public function tasks_section_callback() {
        echo '<p>Configuración de las tareas automatizadas según tu plan.</p>';
    }
    
    public function notifications_section_callback() {
        echo '<p>Configuración de notificaciones por email.</p>';
    }
    
    // Field Callbacks
    public function hub_url_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['hub_url']) ? $options['hub_url'] : '';
        ?>
        <input type="url" name="rpcare_options[hub_url]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <button type="button" class="button" id="test-connection">Probar Conexión</button>
        <p class="description">URL del Hub de Replanta para la comunicación.</p>
        <?php
    }
    
    public function site_token_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['site_token']) ? $options['site_token'] : '';
        ?>
        <input type="text" name="rpcare_options[site_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">Token único proporcionado por Replanta para este sitio.</p>
        <?php
    }
    
    public function current_plan_field() {
        $current = RP_Care_Plan::get_current();
        $plan_config = RP_Care_Plan::get_plan_config($current);
        $hub_connected = get_option('rpcare_hub_connected', false);
        
        if ($hub_connected && !empty($current)) {
            // Show detected plan from Hub (read-only)
            ?>
            <div class="rpcare-plan-detected">
                <strong><?php echo esc_html($plan_config['name'] ?? 'Plan no detectado'); ?></strong>
                <span class="plan-price"><?php echo esc_html($plan_config['price'] ?? ''); ?></span>
                <input type="hidden" name="rpcare_options[plan]" value="<?php echo esc_attr($current); ?>">
            </div>
            <p class="description">
                ✅ Plan detectado automáticamente desde el Hub Replanta. 
                <a href="#" onclick="jQuery('#test-connection').click(); return false;">Actualizar plan</a>
            </p>
            <?php
        } else {
            // Show plan selection cards when not connected
            $this->render_plan_selection();
        }
    }
    
    private function render_plan_selection() {
        $options = get_option('rpcare_options', []);
        $current_manual = isset($options['plan']) ? $options['plan'] : 'semilla';
        ?>
        <div class="rpcare-plans-wrapper">
            <p class="rpcare-plans-intro">
                <strong>⚠️ No estás conectado al Hub Replanta.</strong><br>
                Selecciona tu plan para configurar las características correspondientes, o conecta con el Hub para detección automática.
            </p>
            
            <div class="rpcare-plans-cards">
                
                <!-- Plan Semilla -->
                <div class="rpcare-plan-card <?php echo $current_manual === 'semilla' ? 'selected' : ''; ?>">
                    <div class="plan-header">
                        <h3>Plan Semilla</h3>
                        <p class="plan-sub">Ideal para webs pequeñas pero importantes</p>
                        <span class="plan-price">49€ <small>/mes</small></span>
                    </div>
                    <ul class="plan-features">
                        <li><span class="check-icon">✔</span> Actualizaciones mensuales</li>
                        <li><span class="check-icon">✔</span> Copias de seguridad semanales</li>
                        <li><span class="check-icon">✔</span> Optimización básica WPO</li>
                        <li><span class="check-icon">✔</span> Revisión trimestral de rendimiento</li>
                        <li><span class="check-icon">✔</span> Soporte por email</li>
                    </ul>
                    <div class="plan-cta">
                        <label class="plan-selector">
                            <input type="radio" name="rpcare_options[plan]" value="semilla" <?php checked($current_manual, 'semilla'); ?>>
                            <span class="plan-btn">Seleccionar este plan</span>
                        </label>
                        <a href="https://clientes.replanta.dev/order/product?pid=2e071d93-1d5e-4689-305b-646028758396" class="plan-btn-external" target="_blank" rel="nofollow noopener">Contratar plan</a>
                    </div>
                </div>

                <!-- Plan Raíz -->
                <div class="rpcare-plan-card plan-featured <?php echo $current_manual === 'raiz' ? 'selected' : ''; ?>">
                    <div class="plan-header">
                        <div class="featured-label">Más contratado</div>
                        <h3>Plan Raíz</h3>
                        <p class="plan-sub">Para empresas que viven de su web</p>
                        <span class="plan-price">89€ <small>/mes</small></span>
                    </div>
                    <ul class="plan-features">
                        <li><span class="check-icon">✔</span> Todo lo del plan Semilla</li>
                        <li><span class="check-icon">✔</span> Actualizaciones semanales</li>
                        <li><span class="check-icon">✔</span> Soporte prioritario</li>
                        <li><span class="check-icon">✔</span> Monitorización 24/7</li>
                        <li><span class="check-icon">✔</span> Revisión SEO + WPO mensual</li>
                        <li><span class="check-icon">✔</span> Informes de estado mensuales</li>
                    </ul>
                    <div class="plan-cta">
                        <label class="plan-selector">
                            <input type="radio" name="rpcare_options[plan]" value="raiz" <?php checked($current_manual, 'raiz'); ?>>
                            <span class="plan-btn primary">Seleccionar este plan</span>
                        </label>
                        <a href="https://clientes.replanta.dev/order/product?pid=d5308768-251d-4852-057a-147e390921e6" class="plan-btn-external" target="_blank" rel="nofollow noopener">Contratar plan</a>
                    </div>
                </div>

                <!-- Plan Ecosistema -->
                <div class="rpcare-plan-card <?php echo $current_manual === 'ecosistema' ? 'selected' : ''; ?>">
                    <div class="plan-header">
                        <h3>Plan Ecosistema</h3>
                        <p class="plan-sub">Para proyectos que exigen velocidad y evolución</p>
                        <span class="plan-price">149€ <small>/mes</small></span>
                    </div>
                    <ul class="plan-features">
                        <li><span class="check-icon">✔</span> Todo lo del plan Raíz</li>
                        <li><span class="check-icon">✔</span> Consultoría técnica trimestral</li>
                        <li><span class="check-icon">✔</span> <strong>Hosting ecológico incluido</strong></li>
                        <li><span class="check-icon">✔</span> Auditoría SEO/WPO trimestral</li>
                        <li><span class="check-icon">✔</span> CDN y optimización avanzada</li>
                    </ul>
                    <div class="plan-cta">
                        <label class="plan-selector">
                            <input type="radio" name="rpcare_options[plan]" value="ecosistema" <?php checked($current_manual, 'ecosistema'); ?>>
                            <span class="plan-btn">Seleccionar este plan</span>
                        </label>
                        <a href="https://clientes.replanta.dev/order/product?pid=2e071d93-1d5e-4689-088f-646028758396" class="plan-btn-external" target="_blank" rel="nofollow noopener">Contratar plan</a>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }
    
    public function auto_updates_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['auto_updates']) ? $options['auto_updates'] : 'minor_only';
        $choices = [
            'disabled' => 'Deshabilitadas',
            'minor_only' => 'Solo actualizaciones menores',
            'all' => 'Todas las actualizaciones'
        ];
        ?>
        <select name="rpcare_options[auto_updates]">
            <?php foreach ($choices as $val => $label): ?>
                <option value="<?php echo esc_attr($val); ?>" <?php selected($value, $val); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">Tipo de actualizaciones automáticas permitidas.</p>
        <?php
    }
    
    public function backup_enabled_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['backup_enabled']) ? $options['backup_enabled'] : true;
        ?>
        <label>
            <input type="checkbox" name="rpcare_options[backup_enabled]" value="1" <?php checked($value); ?> />
            Habilitar copias de seguridad automáticas
        </label>
        <p class="description">Las copias se realizan según la frecuencia de tu plan.</p>
        <?php
    }
    
    public function cache_clearing_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['cache_clearing']) ? $options['cache_clearing'] : true;
        ?>
        <label>
            <input type="checkbox" name="rpcare_options[cache_clearing]" value="1" <?php checked($value); ?> />
            Limpiar caché automáticamente
        </label>
        <p class="description">Se limpia la caché después de actualizaciones y según programación.</p>
        <?php
    }
    
    public function security_monitoring_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['security_monitoring']) ? $options['security_monitoring'] : true;
        ?>
        <label>
            <input type="checkbox" name="rpcare_options[security_monitoring]" value="1" <?php checked($value); ?> />
            Habilitar monitoreo de seguridad
        </label>
        <p class="description">Escaneos regulares de vulnerabilidades y amenazas.</p>
        <?php
    }
    
    public function notification_email_field() {
        $options = get_option('rpcare_options', []);
        $value = isset($options['notification_email']) ? $options['notification_email'] : get_option('admin_email');
        ?>
        <input type="email" name="rpcare_options[notification_email]" value="<?php echo esc_attr($value); ?>" class="regular-text" />
        <p class="description">Email donde recibir las notificaciones del sistema.</p>
        <?php
    }
    
    public function notification_types_field() {
        $options = get_option('rpcare_options', []);
        $types = isset($options['notification_types']) ? $options['notification_types'] : [];
        
        $available_types = [
            'updates' => 'Actualizaciones completadas',
            'backups' => 'Copias de seguridad',
            'security' => 'Alertas de seguridad',
            'errors' => 'Errores del sistema',
            'reports' => 'Reportes periódicos'
        ];
        
        foreach ($available_types as $type => $label) {
            $checked = in_array($type, (array)$types);
            ?>
            <label style="display: block; margin-bottom: 5px;">
                <input type="checkbox" name="rpcare_options[notification_types][]" value="<?php echo esc_attr($type); ?>" <?php checked($checked); ?> />
                <?php echo esc_html($label); ?>
            </label>
            <?php
        }
    }
    
    public function sanitize_options($input) {
        $sanitized = [];
        
        if (isset($input['hub_url'])) {
            $sanitized['hub_url'] = esc_url_raw($input['hub_url']);
        }
        
        if (isset($input['site_token'])) {
            $sanitized['site_token'] = sanitize_text_field($input['site_token']);
        }
        
        if (isset($input['plan'])) {
            $valid_plans = ['semilla', 'raiz', 'ecosistema'];
            $sanitized['plan'] = in_array($input['plan'], $valid_plans) ? $input['plan'] : 'semilla';
        }
        
        if (isset($input['auto_updates'])) {
            $valid_updates = ['disabled', 'minor_only', 'all'];
            $sanitized['auto_updates'] = in_array($input['auto_updates'], $valid_updates) ? $input['auto_updates'] : 'minor_only';
        }
        
        $sanitized['backup_enabled'] = isset($input['backup_enabled']);
        $sanitized['cache_clearing'] = isset($input['cache_clearing']);
        $sanitized['security_monitoring'] = isset($input['security_monitoring']);
        
        if (isset($input['notification_email'])) {
            $sanitized['notification_email'] = sanitize_email($input['notification_email']);
        }
        
        if (isset($input['notification_types'])) {
            $sanitized['notification_types'] = array_map('sanitize_text_field', $input['notification_types']);
        }
        
        // Add success message/toast
        add_settings_error(
            'rpcare_messages',
            'rpcare_message',
            '¡Configuración guardada exitosamente!',
            'success'
        );
        
        return $sanitized;
    }
    
    public function test_hub_connection() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $hub_url = $_POST['hub_url'] ?? '';
        $site_token = $_POST['site_token'] ?? '';
        
        if (empty($hub_url) || empty($site_token)) {
            wp_send_json_error('URL y token son requeridos');
        }
        
        // Test connection to hub
        // Clean and normalize hub URL
        $hub_url = rtrim($hub_url, '/');
        
        // Remove common incorrect paths that users might add
        $hub_url = preg_replace('#/(api/test-connection|wp-admin/admin-ajax\.php)$#', '', $hub_url);
        
        // Use only the WordPress AJAX endpoint for simplicity and reliability
        $endpoint = $hub_url . '/wp-admin/admin-ajax.php';
        
        // Test via WordPress AJAX
        $response = wp_remote_post($endpoint, [
            'body' => [
                'action' => 'rphub_test_care_connection',
                'site_token' => $site_token,
                'site_url' => site_url()
            ],
            'timeout' => 10
        ]);
        
        if (!is_wp_error($response)) {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                if ($data && isset($data['success']) && $data['success']) {
                    // Store successful connection
                    update_option('rpcare_hub_connected', true);
                    
                    // Auto-detect plan from Hub
                    $plan = RP_Care_Plan::detect_plan_from_hub($hub_url, $site_token);
                    if ($plan) {
                        wp_send_json_success("Conexión exitosa con el Hub. Plan detectado: " . RP_Care_Plan::get_plan_name($plan));
                    } else {
                        wp_send_json_success('Conexión exitosa con el Hub (plan no detectado)');
                    }
                } else {
                    $error_msg = isset($data['data']) ? $data['data'] : 'Respuesta inválida del Hub';
                    wp_send_json_error("Error del Hub: $error_msg");
                }
            } else {
                wp_send_json_error("Error del servidor: código $code desde $endpoint");
            }
        } else {
            wp_send_json_error('Error de conexión: ' . $response->get_error_message());
        }
    }
    
    public function run_task_manually() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $task = sanitize_text_field($_POST['task'] ?? '');
        
        if (empty($task)) {
            wp_send_json_error('Tarea no especificada');
        }
        
        // Run the task based on type
        switch ($task) {
            case 'updates':
                $result = RP_Care_Task_Updates::run(['manual' => true]);
                break;
            case 'backup':
                $result = class_exists('RP_Care_Task_Backup') ? call_user_func(array('RP_Care_Task_Backup', 'run'), ['manual' => true]) : ['success' => false, 'message' => 'Backup task not available'];
                break;
            case 'cache':
                $result = class_exists('RP_Care_Task_Cache') ? call_user_func(array('RP_Care_Task_Cache', 'run'), ['manual' => true]) : ['success' => false, 'message' => 'Cache task not available'];
                break;
            case 'security':
                $result = class_exists('RP_Care_Task_Security') ? call_user_func(array('RP_Care_Task_Security', 'run'), ['manual' => true]) : ['success' => false, 'message' => 'Security task not available'];
                break;
            case 'health':
                $result = class_exists('RP_Care_Task_Health') ? call_user_func(array('RP_Care_Task_Health', 'run'), ['manual' => true]) : ['success' => false, 'message' => 'Health task not available'];
                break;
            case 'report':
                $result = class_exists('RP_Care_Task_Report') ? call_user_func(array('RP_Care_Task_Report', 'generate_monthly'), ['manual' => true]) : ['success' => false, 'message' => 'Report task not available'];
                break;
            case 'wpo':
                $result = class_exists('RP_Care_Task_WPO') ? call_user_func(array('RP_Care_Task_WPO', 'run'), ['manual' => true]) : ['success' => false, 'message' => 'WPO task not available'];
                break;
            case 'seo':
                $result = class_exists('RP_Care_Task_SEO') ? call_user_func(array('RP_Care_Task_SEO', 'run_basic_review'), ['manual' => true]) : ['success' => false, 'message' => 'SEO task not available'];
                break;
            case '404':
                $result = class_exists('RP_Care_Task_404') ? call_user_func(array('RP_Care_Task_404', 'cleanup'), ['manual' => true]) : ['success' => false, 'message' => '404 task not available'];
                break;
            default:
                wp_send_json_error('Tarea no válida');
        }
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    private function display_system_status() {
        $status = [
            'WordPress' => get_bloginfo('version'),
            'PHP' => PHP_VERSION,
            'Último backup' => get_option('rpcare_last_backup', 'Nunca'),
            'Último reporte' => get_option('rpcare_last_report', 'Nunca'),
            'Tareas programadas' => wp_next_scheduled('rpcare_daily_tasks') ? 'Activas' : 'Inactivas'
        ];
        
        foreach ($status as $label => $value) {
            echo '<div class="status-metric">';
            echo '<span class="status-metric-label">' . esc_html($label) . '</span>';
            echo '<span class="status-metric-value">' . esc_html($value) . '</span>';
            echo '</div>';
        }
    }
    
    private function display_recent_logs() {
        global $wpdb;
        
        $table = $wpdb->prefix . 'rpcare_logs';
        $logs = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT 10",
            ARRAY_A
        );
        
        if (empty($logs)) {
            echo '<p>No hay registros disponibles.</p>';
            return;
        }
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Fecha</th><th>Tipo</th><th>Estado</th><th>Mensaje</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            $status_class = $log['status'] === 'success' ? 'success' : 'error';
            echo '<tr>';
            echo '<td>' . esc_html($log['created_at']) . '</td>';
            echo '<td>' . esc_html($log['task_type']) . '</td>';
            echo '<td class="status-' . $status_class . '">' . esc_html($log['status']) . '</td>';
            echo '<td>' . esc_html($log['message']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    private static function get_feature_label($feature) {
        $labels = [
            'auto_updates' => 'Actualizaciones automáticas',
            'backup' => 'Copias de seguridad',
            'security_monitoring' => 'Monitoreo de seguridad',
            'performance_optimization' => 'Optimización de rendimiento',
            'seo_monitoring' => 'Monitoreo SEO',
            'uptime_monitoring' => 'Monitoreo de disponibilidad',
            'malware_scanning' => 'Escaneo de malware',
            'staging_environment' => 'Entorno de pruebas',
            'priority_support' => 'Soporte prioritario',
            'white_label_reports' => 'Reportes personalizados'
        ];
        
        return isset($labels[$feature]) ? $labels[$feature] : ucfirst(str_replace('_', ' ', $feature));
    }
    
    /**
     * AJAX handler to get current status of all components
     */
    public function get_status_ajax() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        $status = [
            'connection' => $this->check_connection_status(),
            'tasks' => RP_Care_Tasks::get_all_task_statuses(),
            'health' => $this->get_health_metrics(),
            'last_update' => current_time('mysql')
        ];
        
        wp_send_json_success($status);
    }
    
    /**
     * AJAX handler to get detailed metrics for a specific component
     */
    public function get_metric_details_ajax() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        $metric = sanitize_text_field($_POST['metric'] ?? '');
        $details = [];
        
        switch ($metric) {
            case 'security':
                $details = $this->get_security_details();
                break;
            case 'performance':
                $details = $this->get_performance_details();
                break;
            case 'seo':
                $details = $this->get_seo_details();
                break;
            case 'updates':
                $details = $this->get_updates_details();
                break;
            case 'backups':
                $details = $this->get_backups_details();
                break;
            default:
                wp_send_json_error('Métrica no válida');
                return;
        }
        
        wp_send_json_success($details);
    }
    
    private function check_connection_status() {
        $hub_url = get_option('rpcare_hub_url', '');
        $token = get_option('rpcare_token', '');
        
        if (empty($hub_url) || empty($token)) {
            return ['status' => 'disconnected', 'message' => 'No configurado'];
        }
        
        $response = wp_remote_post($hub_url . '/wp-json/rphub/v1/heartbeat', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'url' => home_url(),
                'version' => RPCARE_VERSION
            ]),
            'timeout' => 5  // Reduced from 15 to 5 seconds
        ]);
        
        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            return ['status' => 'connected', 'message' => 'Conectado'];
        }
        
        return ['status' => 'error', 'message' => 'Error de conexión (código: ' . $code . ')'];
    }
    
    private function get_health_metrics() {
        return [
            'overall_score' => RP_Care_Utils::calculate_health_score(),
            'security_score' => (class_exists('RP_Care_Task_Security') && method_exists('RP_Care_Task_Security', 'get_security_score')) ? call_user_func(array('RP_Care_Task_Security', 'get_security_score')) : 85,
            'performance_score' => $this->calculate_performance_score(),
            'seo_score' => $this->calculate_seo_score(),
            'last_backup' => get_option('rpcare_last_backup', ''),
            'updates_pending' => $this->get_pending_updates_count()
        ];
    }
    
    private function get_security_details() {
        return [
            'last_scan' => get_option('rpcare_last_security_scan', ''),
            'threats_found' => get_option('rpcare_security_threats', 0),
            'firewall_status' => $this->check_firewall_status(),
            'ssl_status' => is_ssl() ? 'enabled' : 'disabled',
            'login_security' => $this->check_login_security(),
            'file_permissions' => $this->check_file_permissions()
        ];
    }
    
    private function get_performance_details() {
        return [
            'page_load_time' => get_option('rpcare_avg_load_time', 0),
            'cache_status' => $this->check_cache_status(),
            'database_size' => $this->get_database_size(),
            'image_optimization' => get_option('rpcare_images_optimized', 0),
            'gzip_compression' => $this->check_gzip_status(),
            'cdn_status' => $this->check_cdn_status()
        ];
    }
    
    private function get_seo_details() {
        return [
            'last_audit' => get_option('rpcare_last_seo_audit', ''),
            'seo_score' => get_option('rpcare_seo_score', 0),
            'meta_issues' => get_option('rpcare_meta_issues', []),
            'sitemap_status' => $this->check_sitemap_status(),
            'robots_txt' => $this->check_robots_txt(),
            'analytics_connected' => $this->check_analytics_connection()
        ];
    }
    
    private function get_updates_details() {
        $updates = [
            'core' => get_core_updates(),
            'plugins' => get_plugin_updates(),
            'themes' => get_theme_updates()
        ];
        
        return [
            'core_updates' => count($updates['core']),
            'plugin_updates' => count($updates['plugins']),
            'theme_updates' => count($updates['themes']),
            'auto_updates_enabled' => get_option('rpcare_auto_updates', false),
            'last_update' => get_option('rpcare_last_update', '')
        ];
    }
    
    private function get_backups_details() {
        return [
            'last_backup' => get_option('rpcare_last_backup', ''),
            'backup_frequency' => get_option('rpcare_backup_frequency', 'weekly'),
            'backup_location' => get_option('rpcare_backup_location', 'local'),
            'backup_size' => get_option('rpcare_last_backup_size', 0),
            'automated_backups' => get_option('rpcare_auto_backup', false),
            'retention_days' => get_option('rpcare_backup_retention', 30)
        ];
    }
    
    // Helper methods for detailed metrics
    private function calculate_performance_score() {
        $load_time = get_option('rpcare_avg_load_time', 3);
        $cache_enabled = $this->check_cache_status() === 'enabled';
        $gzip_enabled = $this->check_gzip_status();
        
        $score = 100;
        if ($load_time > 3) $score -= 20;
        if ($load_time > 5) $score -= 30;
        if (!$cache_enabled) $score -= 25;
        if (!$gzip_enabled) $score -= 15;
        
        return max(0, $score);
    }
    
    private function calculate_seo_score() {
        $sitemap = $this->check_sitemap_status();
        $robots = $this->check_robots_txt();
        $ssl = is_ssl();
        $meta_issues = get_option('rpcare_meta_issues', []);
        
        $score = 100;
        if (!$sitemap) $score -= 20;
        if (!$robots) $score -= 15;
        if (!$ssl) $score -= 25;
        $score -= count($meta_issues) * 5;
        
        return max(0, $score);
    }
    
    private function check_firewall_status() {
        // Check for common firewall plugins
        $firewall_plugins = [
            'wordfence/wordfence.php',
            'all-in-one-wp-security-and-firewall/wp-security.php',
            'sucuri-scanner/sucuri.php'
        ];
        
        foreach ($firewall_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return 'enabled';
            }
        }
        
        return 'disabled';
    }
    
    private function check_login_security() {
        $failed_logins = get_option('rpcare_failed_logins_24h', 0);
        $two_factor = $this->check_two_factor_auth();
        
        return [
            'failed_attempts' => $failed_logins,
            'two_factor_enabled' => $two_factor,
            'login_url_changed' => $this->check_custom_login_url()
        ];
    }
    
    private function check_file_permissions() {
        $wp_config_perms = fileperms(ABSPATH . 'wp-config.php') & 0777;
        $uploads_dir = wp_upload_dir();
        $uploads_perms = fileperms($uploads_dir['basedir']) & 0777;
        
        return [
            'wp_config' => decoct($wp_config_perms),
            'uploads_dir' => decoct($uploads_perms),
            'secure' => $wp_config_perms <= 0644 && $uploads_perms >= 0755
        ];
    }
    
    private function check_cache_status() {
        $cache_plugins = [
            'w3-total-cache/w3-total-cache.php',
            'wp-super-cache/wp-cache.php',
            'wp-rocket/wp-rocket.php',
            'wp-fastest-cache/wpFastestCache.php'
        ];
        
        foreach ($cache_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return 'enabled';
            }
        }
        
        return 'disabled';
    }
    
    private function get_database_size() {
        global $wpdb;
        
        $result = $wpdb->get_var("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) as 'DB Size in MB' 
            FROM information_schema.tables 
            WHERE table_schema = '{$wpdb->dbname}'
        ");
        
        return $result ? floatval($result) : 0;
    }
    
    private function check_gzip_status() {
        return function_exists('gzencode') && 
               (isset($_SERVER['HTTP_ACCEPT_ENCODING']) && 
                strpos($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') !== false);
    }
    
    private function check_cdn_status() {
        $cdn_plugins = [
            'cloudflare/cloudflare.php',
            'w3-total-cache/w3-total-cache.php' // W3TC has CDN features
        ];
        
        foreach ($cdn_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return 'enabled';
            }
        }
        
        return 'disabled';
    }
    
    private function check_sitemap_status() {
        $sitemap_urls = [
            home_url('/sitemap.xml'),
            home_url('/sitemap_index.xml'),
            home_url('/wp-sitemap.xml')
        ];
        
        foreach ($sitemap_urls as $url) {
            $response = wp_remote_head($url);
            if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                return true;
            }
        }
        
        return false;
    }
    
    private function check_robots_txt() {
        $robots_url = home_url('/robots.txt');
        $response = wp_remote_head($robots_url);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    private function check_analytics_connection() {
        // Check for common analytics plugins
        $analytics_plugins = [
            'google-analytics-for-wordpress/googleanalytics.php',
            'ga-google-analytics/ga-google-analytics.php',
            'google-analytics-dashboard-for-wp/gadwp.php'
        ];
        
        foreach ($analytics_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function get_pending_updates_count() {
        $core_updates = get_core_updates();
        $plugin_updates = get_plugin_updates();
        $theme_updates = get_theme_updates();
        
        return count($core_updates) + count($plugin_updates) + count($theme_updates);
    }
    
    private function check_two_factor_auth() {
        $two_factor_plugins = [
            'two-factor/two-factor.php',
            'google-authenticator/google-authenticator.php',
            'wordfence/wordfence.php'
        ];
        
        foreach ($two_factor_plugins as $plugin) {
            if (is_plugin_active($plugin)) {
                return true;
            }
        }
        
        return false;
    }
    
    private function check_custom_login_url() {
        return get_option('rpcare_custom_login_url', false) || 
               is_plugin_active('wps-hide-login/wps-hide-login.php');
    }
}
