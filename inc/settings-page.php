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
        add_action('wp_ajax_rpcare_get_hub_reports', [$this, 'ajax_get_hub_reports']);
        add_action('wp_ajax_rpcare_check_updates', [$this, 'ajax_check_updates']);
        add_action('wp_ajax_rpcare_get_logs', [$this, 'ajax_get_logs']);
        add_action('wp_ajax_rpcare_get_backup_history', [$this, 'ajax_get_backup_history']);
        
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
        register_setting('rpcare_settings', 'rpcare_cloudflare_token', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('rpcare_settings', 'rpcare_psi_api_key', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        
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
            'github_token',
            'Token GitHub (updates)',
            [$this, 'github_token_field'],
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
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Replanta Care: Hook actual = $hook");
            error_log("Replanta Care: GET page = " . ($_GET['page'] ?? 'no-page'));
        }
        
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
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Replanta Care: Cargando scripts admin");
        }
        
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

        $options       = get_option('rpcare_options', []);
        $current_plan  = RP_Care_Plan::get_current();
        $plan_config   = RP_Care_Plan::get_plan_config($current_plan);
        $hub_connected = get_option('rpcare_hub_connected', false);
        $health_score  = (int) get_option('rpcare_health_score', 85);

        $last_backup  = get_option('rpcare_last_backup', '');
        $wp_version   = get_bloginfo('version');
        $php_version  = PHP_VERSION;
        $ssl_ok       = is_ssl();
        $tasks_active = (bool) wp_next_scheduled('rpcare_daily_tasks');
        $pending_upd  = $this->get_pending_updates_count();

        $conn_class   = $hub_connected ? 'connected' : 'disconnected';
        $conn_label   = $hub_connected ? 'Tu sitio está protegido' : 'Sin conexión con el Hub';
        $plan_display = ($hub_connected && $current_plan)
            ? ($plan_config['name'] ?? ucfirst($current_plan))
            : '';

        // Hub URL / token — hub_url is opaque to the end user: default to canonical Hub if unset
        $hub_url   = esc_attr($options['hub_url'] ?? 'https://replanta.net');
        $token     = esc_attr($options['site_token'] ?? '');
        $gh_token  = esc_attr(get_option('rpcare_github_token', ''));
        $has_token = !empty($options['site_token']);

        // Notification options
        $notif_email = esc_attr($options['notification_email'] ?? get_option('admin_email'));
        $notif_types = (array) ($options['notification_types'] ?? []);
        $notif_opts  = [
            'updates'  => 'Actualizaciones',
            'backups'  => 'Copias de seguridad',
            'security' => 'Alertas de seguridad',
            'errors'   => 'Errores del sistema',
            'reports'  => 'Informes periódicos',
        ];

        // Tasks
        $auto_updates = $options['auto_updates'] ?? 'minor_only';
        $backup_on    = !isset($options['backup_enabled']) || $options['backup_enabled'];
        $cache_on     = !isset($options['cache_clearing']) || $options['cache_clearing'];
        $security_on  = !isset($options['security_monitoring']) || $options['security_monitoring'];
        ?>
        <div class="rpc-wrap">

            <!-- HEADER -->
            <header class="rpc-header">
                <div class="rpc-header-brand">
                    <img src="<?php echo esc_url(RPCARE_PLUGIN_URL . 'assets/img/ico.png'); ?>" alt="Replanta Care" class="rpc-logo">
                    <div>
                        <h1 class="rpc-title">Replanta Care</h1>
                        <span class="rpc-version">v<?php echo esc_html(RPCARE_VERSION); ?></span>
                    </div>
                </div>
                <div class="rpc-header-status">
                    <div class="rpc-connection <?php echo $conn_class; ?>" id="rpc-hub-pill">
                        <span class="rpc-dot"></span>
                        <span id="rpc-conn-label"><?php echo esc_html($conn_label); ?></span>
                    </div>
                    <?php if ($plan_display): ?>
                    <div class="rpc-plan-badge" id="rpc-plan-badge"><?php echo esc_html($plan_display); ?></div>
                    <?php endif; ?>
                </div>
            </header>

            <!-- STATS BAR -->
            <div class="rpc-stats-bar">
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-wordpress-alt"></span>
                    <span class="rpc-stat-label">WordPress</span>
                    <span class="rpc-stat-value"><?php echo esc_html($wp_version); ?></span>
                </div>
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-editor-code"></span>
                    <span class="rpc-stat-label">PHP</span>
                    <span class="rpc-stat-value"><?php echo esc_html($php_version); ?></span>
                </div>
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-backup"></span>
                    <span class="rpc-stat-label">Último backup</span>
                    <span class="rpc-stat-value <?php echo $last_backup ? 'good' : 'warn'; ?>">
                        <?php echo $last_backup ? esc_html(wp_date('d M', strtotime($last_backup))) : 'Nunca'; ?>
                    </span>
                </div>
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-lock"></span>
                    <span class="rpc-stat-label">SSL</span>
                    <span class="rpc-stat-value <?php echo $ssl_ok ? 'good' : 'bad'; ?>"><?php echo $ssl_ok ? 'Activo' : 'Inactivo'; ?></span>
                </div>
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-update"></span>
                    <span class="rpc-stat-label">Actualizaciones</span>
                    <span class="rpc-stat-value <?php echo $pending_upd > 0 ? 'warn' : 'good'; ?>">
                        <?php echo $pending_upd > 0 ? $pending_upd . ' pendientes' : 'Al día'; ?>
                    </span>
                </div>
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-heart"></span>
                    <span class="rpc-stat-label">Salud</span>
                    <span class="rpc-stat-value rpc-health-num"><?php echo $health_score; ?>%</span>
                </div>
                <div class="rpc-stat">
                    <span class="rpc-stat-icon dashicons dashicons-performance"></span>
                    <span class="rpc-stat-label">Tareas</span>
                    <span class="rpc-stat-value <?php echo $tasks_active ? 'good' : 'warn'; ?>"><?php echo $tasks_active ? 'Activas' : 'Inactivas'; ?></span>
                </div>
            </div>

            <!-- SETTINGS FORM -->
            <form method="post" action="options.php" class="rpc-form" id="rpc-settings-form">
                <?php settings_fields('rpcare_settings'); ?>

                <div class="rpc-sections">

                    <!-- CONEXIÓN HUB -->
                    <section class="rpc-section">
                        <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-admin-links"></span> Conexión con Replanta Hub</h2>

                        <div class="rpc-field">
                            <label class="rpc-label" for="rpc-hub-url">URL del Hub <small style="text-transform:none;font-weight:400;color:var(--rp-muted)">(por defecto: https://replanta.net)</small></label>
                            <input type="url" id="rpc-hub-url" name="rpcare_options[hub_url]" value="<?php echo $hub_url; ?>"
                                   class="rpc-input" placeholder="https://replanta.net">
                        </div>

                        <div class="rpc-field">
                            <label class="rpc-label" for="rpc-site-token">Token del sitio <small style="text-transform:none;font-weight:400;color:var(--rp-muted)">(proporcionado por Replanta Hub al añadir el sitio)</small></label>
                            <div class="rpc-input-row">
                                <input type="text" id="rpc-site-token" name="rpcare_options[site_token]"
                                       class="rpc-input rpc-input-mono" value="<?php echo $token; ?>"
                                       placeholder="Pega aquí el token que generó Replanta Hub" autocomplete="off">
                                <?php if ($has_token): ?>
                                <button type="button" class="rpc-btn rpc-btn-secondary rpc-btn-sm"
                                        onclick="rpcare_copy_token()" title="Copiar token"><span class="dashicons dashicons-clipboard"></span></button>
                                <?php endif; ?>
                            </div>
                            <span class="rpc-hint <?php echo $has_token ? 'ok' : 'warn'; ?>">
                                <?php if ($has_token): ?>
                                    <span class="dashicons dashicons-yes-alt"></span> Token configurado. Hub autenticado.
                                <?php else: ?>
                                    <span class="dashicons dashicons-warning"></span> Sin token: copia el token desde Hub (panel de sitios) y pégalo aquí.
                                <?php endif; ?>
                            </span>
                        </div>

                        <div class="rpc-field">
                            <label class="rpc-label" for="rpc-gh-token">Token GitHub <small style="text-transform:none;font-size:10px;color:var(--rp-muted)">(para actualizaciones privadas)</small></label>
                            <input type="password" id="rpc-gh-token" name="rpcare_options[github_token]"
                                   class="rpc-input" value="<?php echo $gh_token; ?>" autocomplete="new-password">
                        </div>

                        <button type="button" class="rpc-btn rpc-btn-secondary" id="test-connection" style="margin-top:4px;">
                            <span id="rpc-test-icon" class="dashicons dashicons-rest-api"></span> Probar conexión
                        </button>
                        <div id="rpc-connection-result"></div>
                        <div id="connection-status" style="display:none;"></div>
                    </section>

                    <!-- PLAN / TAREAS -->
                    <section class="rpc-section">
                        <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-performance"></span> Tareas automáticas</h2>

                        <?php if ($hub_connected && $current_plan): ?>
                        <div class="rpc-hint ok" style="margin-bottom:16px;">
                            <span class="dashicons dashicons-yes-alt"></span> Plan <strong><?php echo esc_html($plan_display); ?></strong> detectado desde el Hub.
                            Las tareas se ajustan automáticamente.
                        </div>
                        <input type="hidden" name="rpcare_options[plan]" value="<?php echo esc_attr($current_plan); ?>">
                        <?php else: ?>
                        <div class="rpc-field">
                            <label class="rpc-label">Plan seleccionado</label>
                            <?php $this->render_plan_selection(); ?>
                        </div>
                        <?php endif; ?>

                        <div class="rpc-field" style="margin-top:12px;">
                            <label class="rpc-label">Actualizaciones automáticas</label>
                            <select name="rpcare_options[auto_updates]" class="rpc-select">
                                <option value="disabled" <?php selected($auto_updates, 'disabled'); ?>>Deshabilitadas</option>
                                <option value="minor_only" <?php selected($auto_updates, 'minor_only'); ?>>Solo actualizaciones menores</option>
                                <option value="all" <?php selected($auto_updates, 'all'); ?>>Todas las actualizaciones</option>
                            </select>
                        </div>

                        <div class="rpc-toggle-row">
                            <div class="rpc-toggle-info">
                                <span class="rpc-toggle-name"><span class="dashicons dashicons-backup"></span> Copias de seguridad</span>
                                <span class="rpc-toggle-desc">Backups automáticos con Backuply según tu plan</span>
                            </div>
                            <label class="rpc-switch">
                                <input type="checkbox" name="rpcare_options[backup_enabled]" value="1" <?php checked($backup_on); ?>>
                                <span class="rpc-switch-slider"></span>
                            </label>
                        </div>

                        <div class="rpc-toggle-row">
                            <div class="rpc-toggle-info">
                                <span class="rpc-toggle-name"><span class="dashicons dashicons-trash"></span> Limpieza de caché</span>
                                <span class="rpc-toggle-desc">Vaciar caché tras actualizaciones</span>
                            </div>
                            <label class="rpc-switch">
                                <input type="checkbox" name="rpcare_options[cache_clearing]" value="1" <?php checked($cache_on); ?>>
                                <span class="rpc-switch-slider"></span>
                            </label>
                        </div>

                        <div class="rpc-toggle-row">
                            <div class="rpc-toggle-info">
                                <span class="rpc-toggle-name"><span class="dashicons dashicons-shield-alt"></span> Escaneo de seguridad</span>
                                <span class="rpc-toggle-desc">Análisis periódico de vulnerabilidades</span>
                            </div>
                            <label class="rpc-switch">
                                <input type="checkbox" name="rpcare_options[security_monitoring]" value="1" <?php checked($security_on); ?>>
                                <span class="rpc-switch-slider"></span>
                            </label>
                        </div>

                        <?php
                        $feat_map = [
                            'updates'      => ['Actualizaciones',    'dashicons-update'],
                            'backups'      => ['Copias de seguridad','dashicons-backup'],
                            'wpo_basic'    => ['Optimización WPO',   'dashicons-performance'],
                            'wpo_advanced' => ['WPO Avanzado',       'dashicons-performance'],
                            'monitoring'   => ['Monitorización 24/7','dashicons-visibility'],
                            'seo_reviews'  => ['Revisiones SEO',     'dashicons-search'],
                            'staging'      => ['Entorno staging',    'dashicons-admin-multisite'],
                            'cdn_config'   => ['CDN / Cloudflare',   'dashicons-networking'],
                            'audit'        => ['Auditoría SEO/WPO',  'dashicons-clipboard'],
                        ];
                        ?>
                        <div style="margin-top:18px;padding-top:14px;border-top:1px solid var(--rp-border);">
                            <label class="rpc-label" style="margin-bottom:10px;display:block;">Funciones incluidas en tu plan</label>
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                            <?php foreach ($feat_map as $feat => [$label, $icon]):
                                $active = class_exists('RP_Care_Plan') && RP_Care_Plan::can_access_feature($feat, $current_plan ?? '');
                            ?>
                                <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 10px;border-radius:20px;font-size:12px;
                                    background:<?php echo $active ? 'rgba(76,175,78,0.15)' : 'rgba(255,255,255,0.04)'; ?>;
                                    color:<?php echo $active ? 'var(--rp-green,#4caf8e)' : 'var(--rp-muted,#8fa99a)'; ?>;
                                    border:1px solid <?php echo $active ? 'rgba(76,175,78,0.3)' : 'rgba(255,255,255,0.08)'; ?>;">
                                    <span class="dashicons <?php echo $active ? esc_attr($icon) : 'dashicons-lock'; ?>" style="font-size:13px;width:13px;height:13px;margin-top:2px;"></span>
                                    <?php echo esc_html($label); ?>
                                </span>
                            <?php endforeach; ?>
                            </div>
                        </div>

                    </section>

                    <!-- NOTIFICACIONES -->
                    <section class="rpc-section rpc-section-full">
                        <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-bell"></span> Notificaciones</h2>

                        <div style="display:grid;grid-template-columns:1fr 2fr;gap:24px;align-items:start;">
                            <div class="rpc-field">
                                <label class="rpc-label" for="rpc-notif-email">Email de notificaciones</label>
                                <input type="email" id="rpc-notif-email" name="rpcare_options[notification_email]"
                                       class="rpc-input" value="<?php echo $notif_email; ?>">
                                <span class="rpc-hint">Recibirás los avisos del sistema en este email.</span>
                            </div>
                            <div class="rpc-field">
                                <label class="rpc-label">Tipos de notificaciones</label>
                                <div class="rpc-checkbox-grid">
                                    <?php foreach ($notif_opts as $key => $label): ?>
                                    <label class="rpc-checkbox-item">
                                        <input type="checkbox" name="rpcare_options[notification_types][]"
                                               value="<?php echo esc_attr($key); ?>"
                                               <?php checked(in_array($key, $notif_types)); ?>>
                                        <?php echo esc_html($label); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </section>

                </div><!-- /.rpc-sections -->

                <div class="rpc-form-footer">
                    <button type="submit" name="submit" class="rpc-btn rpc-btn-primary">
                        <span class="dashicons dashicons-saved"></span> Guardar configuración
                    </button>
                    <button type="button" id="rpc-check-updates-btn" class="rpc-btn rpc-btn-ghost">
                        <span class="dashicons dashicons-update"></span> Comprobar actualizaciones ahora
                    </button>
                    <?php if (isset($_GET['settings-updated'])): ?>
                    <span class="rpc-hint ok" style="margin-left:auto;"><span class="dashicons dashicons-yes-alt"></span> Configuración guardada</span>
                    <?php endif; ?>
                </div>

            </form>

            <!-- ACCIONES RÁPIDAS -->
            <section class="rpc-actions">
                <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-controls-play"></span> Acciones inmediatas</h2>
                <div class="rpc-action-grid">
                    <button class="rpc-action-card" data-task="updates" type="button" title="Aplica actualizaciones pendientes de WordPress, plugins y temas con backup previo">
                        <span class="rpc-action-icon dashicons dashicons-update"></span>
                        <span class="rpc-action-label">Actualizaciones</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Aplica actualizaciones pendientes de WordPress, plugins y temas con backup previo"></span>
                    </button>
                    <button class="rpc-action-card" data-task="backup" type="button" title="Crea una copia de seguridad completa del sitio via Backuply o WHM según la configuración">
                        <span class="rpc-action-icon dashicons dashicons-backup"></span>
                        <span class="rpc-action-label">Crear backup</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Crea una copia de seguridad completa del sitio via Backuply o WHM según la configuración"></span>
                    </button>
                    <button class="rpc-action-card" data-task="cache" type="button" title="Purga el caché de página (LiteSpeed, WP Rocket, W3TC…) y el caché de objetos de PHP">
                        <span class="rpc-action-icon dashicons dashicons-trash"></span>
                        <span class="rpc-action-label">Limpiar caché</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Purga el caché de página (LiteSpeed, WP Rocket, W3TC…) y el caché de objetos de PHP"></span>
                    </button>
                    <button class="rpc-action-card" data-task="security" type="button" title="Escanea el sitio en busca de archivos sospechosos, usuarios sin seguridad y configuraciones vulnerables">
                        <span class="rpc-action-icon dashicons dashicons-shield-alt"></span>
                        <span class="rpc-action-label">Seguridad</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Escanea el sitio en busca de archivos sospechosos, usuarios sin seguridad y configuraciones vulnerables"></span>
                    </button>
                    <button class="rpc-action-card" data-task="health" type="button" title="Comprueba el estado del servidor, espacio en disco, memoria, WP Cron y funcionalidad de email">
                        <span class="rpc-action-icon dashicons dashicons-heart"></span>
                        <span class="rpc-action-label">Salud del sitio</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Comprueba el estado del servidor, espacio en disco, memoria, WP Cron y funcionalidad de email"></span>
                    </button>
                    <button class="rpc-action-card" data-task="report" type="button" title="Genera el informe mensual del sitio y lo envía por email al administrador">
                        <span class="rpc-action-icon dashicons dashicons-chart-bar"></span>
                        <span class="rpc-action-label">Generar informe</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Genera el informe mensual del sitio y lo envía por email al administrador"></span>
                    </button>
                    <button class="rpc-action-card" data-task="wpo" type="button" title="Optimiza la base de datos, limpia transients caducados, aplica ajustes de LiteSpeed y revisa imágenes grandes">
                        <span class="rpc-action-icon dashicons dashicons-performance"></span>
                        <span class="rpc-action-label">Optimizar WPO</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Optimiza la base de datos, limpia transients caducados, aplica ajustes de LiteSpeed y revisa imágenes grandes"></span>
                    </button>
                    <button class="rpc-action-card" data-task="seo" type="button" title="Revisa meta títulos, meta descripciones, sitemap XML y robots.txt del sitio">
                        <span class="rpc-action-icon dashicons dashicons-search"></span>
                        <span class="rpc-action-label">Análisis SEO</span>
                        <span class="rpc-action-hint dashicons dashicons-info" style="font-size:11px;position:absolute;top:6px;right:6px;color:var(--rp-muted,#8fa99a);cursor:help;" aria-label="Revisa meta títulos, meta descripciones, sitemap XML y robots.txt del sitio"></span>
                    </button>
                </div>
                <div class="rpc-results" id="rpcare-task-results"></div>
            </section>

            <!-- REPORTES DE REPLANTA -->
            <section class="rpc-reports">
                <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-media-document"></span> Informes de Replanta</h2>
                <p class="rpc-hint" style="margin-bottom:14px;">Informes generados por Replanta para este sitio.</p>
                <button type="button" class="rpc-btn rpc-btn-secondary rpc-btn-sm" id="rpcare-load-reports">
                    <span id="rpc-reports-icon" class="dashicons dashicons-download"></span> Cargar informes
                </button>
                <div id="rpcare-reports-list" style="margin-top:14px;"></div>
            </section>

            <!-- HISTORIAL DE BACKUPS -->
            <section class="rpc-backups">
                <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-backup"></span> Historial de copias de seguridad</h2>
                <p class="rpc-hint" style="margin-bottom:14px;">Últimas copias de seguridad realizadas en este sitio.</p>
                <button type="button" class="rpc-btn rpc-btn-secondary rpc-btn-sm" id="rpcare-load-backups">
                    <span id="rpc-backups-icon" class="dashicons dashicons-download"></span> Ver historial
                </button>
                <div id="rpcare-backups-list" style="margin-top:14px;"></div>
            </section>

            <!-- ACTIVIDAD RECIENTE -->
            <section class="rpc-logs">
                <h2 class="rpc-section-title"><span class="rpc-section-icon dashicons dashicons-list-view"></span> Actividad reciente</h2>
                <?php $this->display_recent_logs(); ?>
            </section>

        </div><!-- /.rpc-wrap -->

        <!-- TOAST CONTAINER -->
        <div id="rpc-toasts" aria-live="polite"></div>

        <script>
        function rpcare_generate_token() {
            var chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            var token = '';
            var arr = new Uint8Array(32);
            window.crypto.getRandomValues(arr);
            arr.forEach(function(v){ token += chars[v % chars.length]; });
            document.getElementById('rpc-site-token').value = token;
        }
        function rpcare_copy_token() {
            var input = document.getElementById('rpc-site-token');
            if (!input || !input.value) return;
            navigator.clipboard.writeText(input.value).then(function(){
                if (window.ReplantaCare) window.ReplantaCare.showNotification('Token copiado', 'Pegalo ahora en Replanta Hub.', 'success');
            }).catch(function(){
                input.select();
                document.execCommand('copy');
                if (window.ReplantaCare) window.ReplantaCare.showNotification('Token copiado', '', 'success');
            });
        }

        /* Reports loader */
        (function($){
            $('#rpcare-load-reports').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true);
                $('#rpc-reports-icon').removeClass('dashicons-download').addClass('dashicons-update rpc-spin');
                $('#rpcare-reports-list').html('<p class="rpc-hint">Cargando informes...</p>');
                $.post(rpcare_ajax.ajax_url, { action: 'rpcare_get_hub_reports', nonce: rpcare_ajax.nonce }, function(res){
                    btn.prop('disabled', false);
                    $('#rpc-reports-icon').removeClass('dashicons-update rpc-spin').addClass('dashicons-download');
                    if (!res.success) {
                        $('#rpcare-reports-list').html('<p class="rpc-hint error"><span class="dashicons dashicons-warning"></span> ' + (res.data || 'Error al cargar informes.') + '</p>');
                        return;
                    }
                    var reports = res.data;
                    if (!Array.isArray(reports) || !reports.length) {
                        $('#rpcare-reports-list').html('<div class="rpc-empty"><span class="rpc-empty-icon dashicons dashicons-media-document"></span>No hay informes disponibles todavía.</div>');
                        return;
                    }
                    var html = '<table class="rpc-table"><thead><tr><th>Tipo</th><th>Generado</th><th></th></tr></thead><tbody>';
                    $.each(reports, function(i, r){
                        html += '<tr>';
                        html += '<td>' + $('<span>').text(r.report_type_name || r.report_type).html() + '</td>';
                        html += '<td>' + $('<span>').text(r.generated_at).html() + '</td>';
                        html += '<td><button class="rpc-btn rpc-btn-secondary rpc-btn-sm rpcare-view-report" data-id="' + $('<span>').text(r.report_id).html() + '">Ver</button></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#rpcare-reports-list').html(html);
                }).fail(function(){
                    btn.prop('disabled', false);
                    $('#rpc-reports-icon').removeClass('dashicons-update rpc-spin').addClass('dashicons-download');
                    $('#rpcare-reports-list').html('<p class="rpc-hint error"><span class="dashicons dashicons-warning"></span> Error de red.</p>');
                });
            });

            $(document).on('click', '.rpcare-view-report', function(){
                var reportId = $(this).data('id');
                var btn = $(this);
                btn.prop('disabled', true).text('Cargando...');
                $.post(rpcare_ajax.ajax_url, { action: 'rpcare_get_hub_reports', nonce: rpcare_ajax.nonce, report_id: reportId }, function(res){
                    btn.prop('disabled', false).text('Ver');
                    if (!res.success || !res.data || !res.data.html) {
                        if (window.ReplantaCare) window.ReplantaCare.showNotification('Error al cargar informe', 'error');
                        return;
                    }
                    var overlay = $('<div id="rpcare-report-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100000;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px;overflow-y:auto;"></div>');
                    var box = $('<div style="background:#fff;border-radius:6px;width:100%;max-width:900px;min-height:200px;position:relative;box-shadow:0 8px 40px rgba(0,0,0,.35);"></div>');
                    var header = $('<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;border-bottom:1px solid #ddd;position:sticky;top:0;background:#fff;z-index:1;border-radius:6px 6px 0 0;"></div>');
                    header.append('<strong style="font-size:14px;">' + ($('<span>').text(res.data.title || 'Informe').html()) + '</strong>');
                    var closeBtn = $('<button type="button" style="background:none;border:none;font-size:20px;cursor:pointer;color:#666;padding:0 4px;" aria-label="Cerrar">&times;</button>');
                    closeBtn.on('click', function(){ overlay.remove(); });
                    header.append(closeBtn);
                    var body = $('<div style="padding:20px;overflow:auto;"></div>').html(res.data.html);
                    box.append(header).append(body);
                    overlay.append(box);
                    $('body').append(overlay);
                    overlay.on('click', function(e){ if (e.target === overlay[0]) overlay.remove(); });
                    $(document).one('keydown.rpcReport', function(e){ if (e.key === 'Escape') { overlay.remove(); $(document).off('keydown.rpcReport'); } });
                });
            });
        })(jQuery);

        /* Backup history loader */
        (function($){
            $('#rpcare-load-backups').on('click', function(){
                var btn = $(this);
                btn.prop('disabled', true);
                $('#rpc-backups-icon').removeClass('dashicons-download').addClass('dashicons-update rpc-spin');
                $('#rpcare-backups-list').html('<p class="rpc-hint">Cargando historial...</p>');
                $.post(rpcare_ajax.ajax_url, { action: 'rpcare_get_backup_history', nonce: rpcare_ajax.nonce }, function(res){
                    btn.prop('disabled', false);
                    $('#rpc-backups-icon').removeClass('dashicons-update rpc-spin').addClass('dashicons-download');
                    if (!res.success) {
                        $('#rpcare-backups-list').html('<p class="rpc-hint error"><span class="dashicons dashicons-warning"></span> ' + (res.data || 'Error al cargar historial.') + '</p>');
                        return;
                    }
                    var backups = res.data;
                    if (!Array.isArray(backups) || !backups.length) {
                        $('#rpcare-backups-list').html('<div class="rpc-empty"><span class="rpc-empty-icon dashicons dashicons-backup"></span>No hay copias de seguridad registradas todavía.</div>');
                        return;
                    }
                    var html = '<table class="rpc-table"><thead><tr><th>Fecha</th><th>Nombre</th><th>Tamaño</th><th>Origen</th></tr></thead><tbody>';
                    $.each(backups, function(i, b){
                        html += '<tr>';
                        html += '<td>' + $('<span>').text(b.date).html() + '</td>';
                        html += '<td>' + $('<span>').text(b.name).html() + '</td>';
                        html += '<td>' + $('<span>').text(b.size).html() + '</td>';
                        html += '<td><span class="rpc-badge">' + $('<span>').text(b.source).html() + '</span></td>';
                        html += '</tr>';
                    });
                    html += '</tbody></table>';
                    $('#rpcare-backups-list').html(html);
                }).fail(function(){
                    btn.prop('disabled', false);
                    $('#rpc-backups-icon').removeClass('dashicons-update rpc-spin').addClass('dashicons-download');
                    $('#rpcare-backups-list').html('<p class="rpc-hint error"><span class="dashicons dashicons-warning"></span> Error de red.</p>');
                });
            });
        })(jQuery);
        </script>
        <!-- report modal placeholder -->
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
        $value   = $options['site_token'] ?? '';
        $has_token = !empty($value);
        ?>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <input type="text"
                   id="rpcare_site_token_input"
                   name="rpcare_options[site_token]"
                   value="<?php echo esc_attr($value); ?>"
                   class="regular-text"
                   placeholder="Pega aquí el token copiado desde Replanta Hub"
                   style="font-family:monospace;font-size:12px;" />
            <?php if ($has_token): ?>
            <button type="button"
                    class="button"
                    onclick="rpcare_copy_token()"
                    title="Copiar token al portapapeles">
                <span class="dashicons dashicons-clipboard" style="vertical-align:middle;"></span> Copiar
            </button>
            <?php endif; ?>
        </div>
        <p class="description" style="margin-top:6px;">
            <?php if ($has_token): ?>
                <span style="color:#46b450;font-weight:600;"><span class="dashicons dashicons-yes" style="vertical-align:middle;"></span> Token configurado.</span>
                El token autentica las peticiones de Replanta Hub hacia este sitio.
            <?php else: ?>
                <span style="color:#d63638;font-weight:600;"><span class="dashicons dashicons-warning" style="vertical-align:middle;"></span> Sin token.</span>
                Sin token el Hub no puede conectar a este sitio.
            <?php endif; ?>
            <br><strong>Flujo:</strong> Abre el sitio en Replanta Hub → copia el token → pégalo aquí y guarda.
        </p>
        <script>
        function rpcare_generate_token() {
            const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            let token = '';
            const arr = new Uint8Array(32);
            window.crypto.getRandomValues(arr);
            arr.forEach(v => { token += chars[v % chars.length]; });
            document.getElementById('rpcare_site_token_input').value = token;
        }
        function rpcare_copy_token() {
            const input = document.getElementById('rpcare_site_token_input');
            if (!input || !input.value) return;
            navigator.clipboard.writeText(input.value).then(function() {
                alert('Token copiado al portapapeles');
            }).catch(function() {
                input.select();
                document.execCommand('copy');
                alert('Token copiado al portapapeles');
            });
        }
        </script>
        <?php
    }

    public function github_token_field() {
        $value = get_option('rpcare_github_token', '');
        ?>
        <input type="password" name="rpcare_options[github_token]" value="<?php echo esc_attr($value); ?>" class="regular-text" autocomplete="off" />
        <p class="description">Token de GitHub para acceder al repositorio privado y detectar actualizaciones.</p>
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
                Plan detectado automáticamente desde el Hub Replanta. 
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
                <strong><span class="dashicons dashicons-warning"></span> No estás conectado al Hub Replanta.</strong><br>
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
                        <li><span class="check-icon dashicons dashicons-yes"></span> Actualizaciones mensuales</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Copias de seguridad semanales</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Optimización básica WPO</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Revisión trimestral de rendimiento</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Soporte por email</li>
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
                        <li><span class="check-icon dashicons dashicons-yes"></span> Todo lo del plan Semilla</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Actualizaciones semanales</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Soporte prioritario</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Monitorización 24/7</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Revisión SEO + WPO mensual</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Informes de estado mensuales</li>
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
                        <li><span class="check-icon dashicons dashicons-yes"></span> Todo lo del plan Raíz</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Consultoría técnica trimestral</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> <strong>Hosting ecológico incluido</strong></li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> Auditoría SEO/WPO trimestral</li>
                        <li><span class="check-icon dashicons dashicons-yes"></span> CDN y optimización avanzada</li>
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

        // Invalidate plan cache whenever Hub creds are touched so next request re-detects from Hub
        delete_transient('rpcare_plan_cache');
        delete_transient('rpcare_hub_backoff');
        delete_option('rpcare_hub_failures');

        if (isset($input['github_token'])) {
            update_option('rpcare_github_token', sanitize_text_field($input['github_token']));
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

        if (isset($_POST['rpcare_check_updates'])) {
            delete_site_transient('update_plugins');
            wp_clean_plugins_cache(true);
            wp_update_plugins();
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

    public function ajax_check_updates() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos'], 403);
        }

        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';

        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
        wp_clean_plugins_cache(true);
        wp_clean_themes_cache(true);

        wp_update_plugins();
        wp_update_themes();
        wp_version_check();

        $plugin_updates = get_site_transient('update_plugins');
        $theme_updates  = get_site_transient('update_themes');
        $core_updates   = get_core_updates();

        $plugins_count = isset($plugin_updates->response) ? count($plugin_updates->response) : 0;
        $themes_count  = isset($theme_updates->response)  ? count($theme_updates->response)  : 0;
        $core_pending  = !empty($core_updates) && isset($core_updates[0]->response) && $core_updates[0]->response === 'upgrade';

        $total = $plugins_count + $themes_count + ($core_pending ? 1 : 0);

        $parts = [];
        if ($core_pending) $parts[] = 'WordPress';
        if ($plugins_count) $parts[] = $plugins_count . ' ' . _n('plugin', 'plugins', $plugins_count, 'replanta-care');
        if ($themes_count)  $parts[] = $themes_count . ' ' . _n('tema', 'temas', $themes_count, 'replanta-care');

        $message = $total === 0
            ? 'Todo al día. Sin actualizaciones pendientes.'
            : 'Pendientes: ' . implode(', ', $parts) . '.';

        wp_send_json_success([
            'message'        => $message,
            'total'          => $total,
            'plugins_count'  => $plugins_count,
            'themes_count'   => $themes_count,
            'core_pending'   => $core_pending,
            'checked_at'     => current_time('mysql'),
        ]);
    }

    public function ajax_get_logs() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Sin permisos', 403);
        }

        $task_labels = [
            'updates'          => 'Actualizaciones',
            'backup'           => 'Copia de seguridad',
            'cache'            => 'Caché',
            'security'         => 'Seguridad',
            'health_check'     => 'Chequeo de salud',
            'seo_basic_review' => 'SEO',
            'wpo'              => 'Optimización',
            'report_generation'=> 'Reporte',
            'maintenance'      => 'Mantenimiento',
        ];

        global $wpdb;
        $table = $wpdb->prefix . 'rpcare_logs';
        $logs  = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        );

        ob_start();
        if (empty($logs)) {
            echo '<p style="color:var(--rp-muted);font-size:13px;">No hay registros disponibles.</p>';
        } else {
            echo '<table class="rpc-table">';
            echo '<thead><tr><th>Fecha</th><th>Tarea</th><th>Estado</th><th>Mensaje</th></tr></thead>';
            echo '<tbody>';
            foreach ($logs as $log) {
                $label  = $task_labels[$log['task_type']] ?? ucfirst(str_replace('_', ' ', $log['task_type']));
                $status = $log['status'];
                $pill   = in_array($status, ['success', 'error', 'warning', 'info'], true) ? $status : 'info';
                $date   = wp_date('d M Y H:i', strtotime($log['created_at']));
                echo '<tr>';
                echo '<td style="white-space:nowrap;color:var(--rp-muted)">' . esc_html($date) . '</td>';
                echo '<td style="color:var(--rp-text)">' . esc_html($label) . '</td>';
                echo '<td><span class="rpc-pill ' . esc_attr($pill) . '">' . esc_html($status) . '</span></td>';
                echo '<td>' . esc_html($log['message']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html, 'count' => count($logs)]);
    }

    public function test_hub_connection() {
        check_ajax_referer('rpcare_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']);
            return;
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
                if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
                    $body = substr($body, 3);
                }
                $body = trim($body);
                $data = json_decode($body, true);

                if (!is_array($data)) {
                    // Non-JSON response — show first 200 chars for debug
                    $preview = esc_html(substr(wp_strip_all_tags($body), 0, 200));
                    wp_send_json_error(['message' => 'Hub devolvió respuesta no JSON. Verifica que el Hub esté activo. Detalle: ' . $preview]);
                    return;
                }

                if (!empty($data['success'])) {
                    update_option('rpcare_hub_connected', true);

                    // Hub now returns plan in the success response — use it directly
                    $hub_plan = $data['data']['plan'] ?? null;
                    if ($hub_plan && RP_Care_Plan::is_valid_plan($hub_plan)) {
                        RP_Care_Plan::set_current($hub_plan);
                        $plan_name = RP_Care_Plan::get_plan_name($hub_plan);
                    } else {
                        // Fallback: ask Hub for plan via separate endpoint
                        $hub_plan  = RP_Care_Plan::detect_plan_from_hub($hub_url, $site_token);
                        $plan_name = $hub_plan ? RP_Care_Plan::get_plan_name($hub_plan) : '';
                    }

                    $site_name = $data['data']['site'] ?? '';
                    $msg = $plan_name
                        ? "Conectado al Hub. Sitio: $site_name. Plan: $plan_name"
                        : "Conectado al Hub. Sitio: $site_name (plan no detectado)";

                    wp_send_json_success($msg);
                } else {
                    // Hub returned a structured error — surface it clearly
                    $raw = $data['data'] ?? null;
                    if (is_array($raw)) {
                        $error_msg = $raw['message'] ?? json_encode($raw);
                    } else {
                        $error_msg = is_string($raw) && $raw !== '' ? $raw : 'Respuesta inesperada del Hub';
                    }
                    wp_send_json_error("Error del Hub: $error_msg");
                }
            } else {
                wp_send_json_error("El Hub respondió con HTTP $code. Verifica la URL del Hub.");
            }
        } else {
            wp_send_json_error('No se pudo conectar al Hub: ' . $response->get_error_message());
        }
    }
    
    /**
     * AJAX: Obtiene reportes del Hub para este sitio usando el token configurado.
     * Si se pasa `report_id` devuelve el HTML del reporte; si no, la lista.
     */
    public function ajax_get_hub_reports() {
        check_ajax_referer('rpcare_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes'); return;
        }

        $options   = get_option('rpcare_options', []);
        $hub_url   = rtrim($options['hub_url'] ?? '', '/');
        $token     = $options['site_token'] ?? '';

        if (empty($hub_url) || empty($token)) {
            wp_send_json_error('Configura la URL de Replanta y el Token antes de cargar informes.'); return;
        }

        $endpoint = $hub_url . '/wp-admin/admin-ajax.php';

        $report_id = sanitize_text_field($_POST['report_id'] ?? '');

        if (!empty($report_id)) {
            $response = wp_remote_post($endpoint, [
                'body'    => [
                    'action'       => 'rphub_view_report_modal',
                    'client_token' => $token,
                    'report_id'    => $report_id,
                ],
                'timeout' => 15,
            ]);
        } else {
            $response = wp_remote_post($endpoint, [
                'body'    => [
                    'action'       => 'rphub_get_client_reports',
                    'client_token' => $token,
                ],
                'timeout' => 15,
            ]);
        }

        if (is_wp_error($response)) {
            wp_send_json_error('No se pudo conectar a Replanta: ' . $response->get_error_message()); return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $raw = wp_remote_retrieve_body($response);
            $preview = esc_html(substr(wp_strip_all_tags($raw), 0, 120));
            wp_send_json_error("Replanta respondió con HTTP $code" . ($preview ? ": $preview" : '.'));
            return;
        }

        $body = wp_remote_retrieve_body($response);
        if (substr($body, 0, 3) === "\xEF\xBB\xBF") {
            $body = substr($body, 3);
        }
        $body = json_decode(trim($body), true);

        if (!is_array($body) || empty($body['success'])) {
            $msg = is_array($body) ? ($body['data'] ?? 'Respuesta inesperada del Hub.') : 'Respuesta no JSON del Hub.';
            wp_send_json_error(is_string($msg) ? $msg : wp_json_encode($msg));
        }

        wp_send_json_success($body['data']);
    }

    public function ajax_get_backup_history() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes'); return;
        }

        $history = [];

        // Pull from Backuply option if available
        $backuply_list = get_option('backuply_backup_list', []);
        if (is_array($backuply_list) && !empty($backuply_list)) {
            foreach ($backuply_list as $b) {
                $ts = isset($b['time']) ? (int) $b['time'] : strtotime($b['date'] ?? '');
                if (!$ts) continue;
                $history[] = [
                    'date'   => date('Y-m-d H:i', $ts),
                    'name'   => $b['name'] ?? 'Backuply backup',
                    'size'   => isset($b['size']) ? size_format((int) $b['size']) : '—',
                    'source' => 'Backuply',
                ];
            }
            usort($history, fn($a, $b) => strcmp($b['date'], $a['date']));
            $history = array_slice($history, 0, 20);
        }

        // Fall back to Care logs if no Backuply data
        if (empty($history)) {
            global $wpdb;
            $table = $wpdb->prefix . 'rpcare_logs';
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'")) {
                $rows = $wpdb->get_results(
                    "SELECT created_at, status, context FROM $table WHERE task = 'backup' ORDER BY created_at DESC LIMIT 20",
                    ARRAY_A
                );
                foreach ($rows as $r) {
                    $ctx = is_string($r['context']) ? json_decode($r['context'], true) : [];
                    $history[] = [
                        'date'   => $r['created_at'],
                        'name'   => ($ctx['type'] ?? 'backup') . ($r['status'] === 'success' ? '' : ' (error)'),
                        'size'   => '—',
                        'source' => 'Care',
                    ];
                }
            }
        }

        wp_send_json_success($history);
    }

    private static function format_updates_result($raw) {
        $raw = is_array($raw) ? $raw : [];
        $updated = [];
        $errors  = [];

        if (!empty($raw['core']['updated']) && empty($raw['core']['rolled_back'])) {
            $updated[] = 'WordPress → ' . ($raw['core']['version'] ?? 'nueva versión');
        } elseif (!empty($raw['core']['error'])) {
            $errors[] = 'WP Core: ' . $raw['core']['error'];
        }

        foreach ($raw['plugins'] ?? [] as $slug => $p) {
            $name = $p['name'] ?? $slug;
            if (!empty($p['updated']) && empty($p['rolled_back'])) {
                $updated[] = $name . ' → ' . ($p['new_version'] ?? '');
            } elseif (!empty($p['rolled_back'])) {
                $errors[] = $name . ' (rollback a ' . ($p['old_version'] ?? '?') . ')';
            }
        }

        foreach ($raw['themes'] ?? [] as $slug => $t) {
            $name = $t['name'] ?? $slug;
            if (!empty($t['updated']) && empty($t['rolled_back'])) {
                $updated[] = $name . ' → ' . ($t['new_version'] ?? '');
            } elseif (!empty($t['rolled_back'])) {
                $errors[] = $name . ' (rollback a ' . ($t['old_version'] ?? '?') . ')';
            }
        }

        $n_updated = count($updated);
        $n_errors  = count($errors);
        $backup_ok = !empty($raw['backup']['success']);

        $msg = $n_updated > 0 ? "{$n_updated} elemento(s) actualizado(s)" : 'Sin actualizaciones pendientes';
        if ($n_errors > 0) {
            $msg .= ", {$n_errors} con error/rollback";
        }

        $details = [
            'actualizados'  => $n_updated,
            'backup_previo' => $backup_ok,
        ];
        if ($n_updated > 0) {
            $details['lista'] = implode(' | ', $updated);
        }
        if ($n_errors > 0) {
            $details['errores'] = implode(' | ', $errors);
        }

        update_option('rpcare_last_update_result', $details);

        return ['success' => true, 'message' => $msg, 'details' => $details];
    }

    public function run_task_manually() {
        check_ajax_referer('rpcare_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permisos insuficientes']); return;
        }
        
        $task = sanitize_text_field($_POST['task'] ?? '');

        if (empty($task)) {
            wp_send_json_error(['message' => 'Tarea no especificada']);
        }

        try {

        // Run the task based on type
        switch ($task) {
            case 'updates':
                $result = self::format_updates_result(RP_Care_Task_Updates::run(['manual' => true]));
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
            case 'sync':
                $result = $this->sync_with_hub();
                break;
            default:
                wp_send_json_error(['message' => 'Tarea no válida']);
        }

        // Normalize: tasks that return plain arrays (no 'success' key) are treated as successful
        if (!isset($result['success'])) {
            $result = [
                'success' => true,
                'message' => 'Tarea completada',
                'details' => $result,
            ];
        }

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }

        } catch (\Throwable $e) {
            error_log('Replanta Care run_task_manually error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            wp_send_json_error(['message' => $e->getMessage()]);
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
        $logs  = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT 20",
            ARRAY_A
        );

        $task_labels = [
            'updates'          => 'Actualizaciones',
            'backup'           => 'Copia de seguridad',
            'cache'            => 'Caché',
            'security'         => 'Seguridad',
            'health_check'     => 'Chequeo de salud',
            'seo_basic_review' => 'SEO',
            'wpo'              => 'Optimización',
            'report_generation'=> 'Reporte',
            'maintenance'      => 'Mantenimiento',
        ];

        echo '<div class="rpc-log-toolbar" style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">';
        echo '<button type="button" class="button rpc-refresh-logs" style="font-size:12px;height:28px;line-height:28px;padding:0 10px;">'
           . '<span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;margin-top:7px;margin-right:4px;"></span> Actualizar</button>';
        echo '<span id="rpc-log-count" style="font-size:12px;color:var(--rp-muted);">' . count($logs) . ' entradas</span>';
        echo '</div>';

        echo '<div id="rpc-log-container">';
        $this->render_log_table($logs, $task_labels);
        echo '</div>';
    }

    private function render_log_table($logs, $task_labels) {
        if (empty($logs)) {
            echo '<p style="color:var(--rp-muted);font-size:13px;">No hay registros disponibles.</p>';
            return;
        }

        echo '<table class="rpc-table">';
        echo '<thead><tr><th>Fecha</th><th>Tarea</th><th>Estado</th><th>Mensaje</th></tr></thead>';
        echo '<tbody>';

        foreach ($logs as $log) {
            $label  = $task_labels[$log['task_type']] ?? ucfirst(str_replace('_', ' ', $log['task_type']));
            $status = $log['status'];
            $pill   = in_array($status, ['success', 'error', 'warning', 'info'], true) ? $status : 'info';
            $date   = wp_date('d M Y H:i', strtotime($log['created_at']));

            echo '<tr>';
            echo '<td style="white-space:nowrap;color:var(--rp-muted)">' . esc_html($date) . '</td>';
            echo '<td style="color:var(--rp-text)">' . esc_html($label) . '</td>';
            echo '<td><span class="rpc-pill ' . esc_attr($pill) . '">' . esc_html($status) . '</span></td>';
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
            'tasks' => class_exists('RP_Care_Tasks') ? RP_Care_Tasks::get_all_task_statuses() : [],
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
        $core_updates   = get_core_updates();
        $plugin_updates = get_plugin_updates();
        $theme_updates  = get_theme_updates();

        return [
            'core_updates'         => count((array) $core_updates),
            'plugin_updates'       => count((array) $plugin_updates),
            'theme_updates'        => count((array) $theme_updates),
            'auto_updates_enabled' => get_option('rpcare_auto_updates', false),
            'last_update'          => get_option('rpcare_last_update', ''),
            'last_result'          => get_option('rpcare_last_update_result', null),
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

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2)
                 FROM information_schema.tables
                 WHERE table_schema = %s",
                DB_NAME
            )
        );

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
        $core_updates   = get_core_updates();
        $plugin_updates = get_plugin_updates();
        $theme_updates  = get_theme_updates();

        return count((array) $core_updates) + count((array) $plugin_updates) + count((array) $theme_updates);
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
    
    /**
     * Sync site data with Replanta Hub
     */
    private function sync_with_hub() {
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        $hub_url = class_exists('RP_Care_Plan') ? RP_Care_Plan::get_hub_url() : 'https://sitios.replanta.dev';
        
        $response = wp_remote_post($hub_url . '/wp-json/replanta/v1/sites/heartbeat', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Site-Domain' => $domain,
                'User-Agent' => 'Replanta-Care/' . RPCARE_VERSION
            ],
            'body' => json_encode([
                'domain' => $domain,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => RPCARE_VERSION,
                'php_version' => PHP_VERSION,
                'last_update' => get_option('rpcare_last_update'),
                'last_backup' => get_option('rpcare_last_backup'),
                'plan' => get_option('rpcare_plan', 'unknown')
            ]),
            'timeout' => 30,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            error_log('Care: Sync error - ' . $response->get_error_message());
            return ['success' => false, 'message' => 'Error de conexión: ' . $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200) {
            update_option('rpcare_last_sync', current_time('mysql'));
            return ['success' => true, 'message' => 'Sincronización completada'];
        }
        
        return ['success' => false, 'message' => 'Error del servidor Hub (código ' . $code . ')'];
    }
}
