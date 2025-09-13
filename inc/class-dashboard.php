<?php
/**
 * Care Dashboard Widget
 * Main dashboard interface for clients
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Dashboard {
    
    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rpcare_get_dashboard_data', [$this, 'ajax_get_dashboard_data']);
        add_action('wp_ajax_rpcare_run_task', [$this, 'ajax_run_task']);
        add_action('wp_ajax_rpcare_get_backups', [$this, 'ajax_get_backups']);
        add_action('wp_ajax_rpcare_create_backup', [$this, 'ajax_create_backup']);
        add_action('wp_ajax_rpcare_get_updates', [$this, 'ajax_get_updates']);
        add_action('wp_ajax_rpcare_get_health_report', [$this, 'ajax_get_health_report']);
    }
    
    public function add_dashboard_widget() {
        $plan = RP_Care_Plan::get_current();
        
        if (!$plan) {
            return;
        }
        
        wp_add_dashboard_widget(
            'rpcare_dashboard',
            '🛡️ Replanta Care - Estado del Mantenimiento',
            [$this, 'render_dashboard_widget']
        );
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== 'index.php') {
            return;
        }
        
        wp_enqueue_style(
            'rpcare-dashboard',
            RPCARE_PLUGIN_URL . 'assets/css/dashboard.css',
            [],
            RPCARE_VERSION
        );
        
        wp_enqueue_script(
            'rpcare-dashboard',
            RPCARE_PLUGIN_URL . 'assets/js/dashboard.js',
            ['jquery'],
            RPCARE_VERSION,
            true
        );
        
        wp_localize_script('rpcare-dashboard', 'rpcare_dashboard', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rpcare_ajax'),
            'strings' => [
                'loading' => 'Cargando...',
                'error' => 'Error al cargar datos',
                'success' => 'Operación completada',
                'backup_created' => 'Copia de seguridad creada',
                'task_running' => 'Ejecutando tarea...'
            ]
        ]);
    }
    
    public function render_dashboard_widget() {
        $plan = RP_Care_Plan::get_current();
        $plan_name = RP_Care_Plan::get_plan_name($plan);
        $features = RP_Care_Plan::get_features($plan);
        
        ?>
        <div class="rpcare-dashboard-widget">
            <div class="rpcare-plan-info">
                <h3>Plan Activo: <?php echo esc_html($plan_name); ?></h3>
                <span class="rpcare-plan-badge rpcare-plan-<?php echo esc_attr($plan); ?>">
                    <?php echo esc_html($plan_name); ?>
                </span>
            </div>
            
            <div class="rpcare-dashboard-tabs">
                <div class="rpcare-tab-nav">
                    <button class="rpcare-tab-btn active" data-tab="status">Estado General</button>
                    <?php if ($features['backup']): ?>
                    <button class="rpcare-tab-btn" data-tab="backups">Copias de Seguridad</button>
                    <?php endif; ?>
                    <button class="rpcare-tab-btn" data-tab="updates">Actualizaciones</button>
                    <button class="rpcare-tab-btn" data-tab="health">Salud del Sitio</button>
                </div>
                
                <div class="rpcare-tab-content">
                    <!-- Status Tab -->
                    <div class="rpcare-tab-panel active" id="rpcare-tab-status">
                        <div class="rpcare-status-grid">
                            <div class="rpcare-status-card">
                                <div class="rpcare-status-icon">🔄</div>
                                <div class="rpcare-status-info">
                                    <h4>Última Actualización</h4>
                                    <p id="rpcare-last-update">Cargando...</p>
                                    <small>Frecuencia: <?php echo esc_html($features['updates_frequency']); ?></small>
                                </div>
                            </div>
                            
                            <div class="rpcare-status-card">
                                <div class="rpcare-status-icon">💾</div>
                                <div class="rpcare-status-info">
                                    <h4>Última Copia de Seguridad</h4>
                                    <p id="rpcare-last-backup">Cargando...</p>
                                    <small>Frecuencia: <?php echo esc_html($features['backup_frequency']); ?></small>
                                </div>
                            </div>
                            
                            <div class="rpcare-status-card">
                                <div class="rpcare-status-icon">⚡</div>
                                <div class="rpcare-status-info">
                                    <h4>Optimización WPO</h4>
                                    <p id="rpcare-wpo-status">Cargando...</p>
                                    <small>Nivel: <?php echo esc_html($features['wpo_level']); ?></small>
                                </div>
                            </div>
                            
                            <?php if ($features['monitoring']): ?>
                            <div class="rpcare-status-card">
                                <div class="rpcare-status-icon">📊</div>
                                <div class="rpcare-status-info">
                                    <h4>Monitoreo 24/7</h4>
                                    <p id="rpcare-monitoring-status">Activo</p>
                                    <small>Estado: En línea</small>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="rpcare-actions">
                            <button type="button" class="button button-primary" onclick="rpcareRunTask('sync')">
                                🔄 Sincronizar con Hub
                            </button>
                            <button type="button" class="button" onclick="rpcareRefreshData()">
                                ↻ Actualizar Datos
                            </button>
                        </div>
                    </div>
                    
                    <!-- Backups Tab -->
                    <?php if ($features['backup']): ?>
                    <div class="rpcare-tab-panel" id="rpcare-tab-backups">
                        <div class="rpcare-backups-header">
                            <h4>Gestión de Copias de Seguridad</h4>
                            <button type="button" class="button button-secondary" onclick="rpcareCreateBackup()">
                                💾 Crear Copia Ahora
                            </button>
                        </div>
                        
                        <div id="rpcare-backups-list">
                            <p>Cargando copias de seguridad...</p>
                        </div>
                        
                        <div class="rpcare-backup-info">
                            <h5>Información del Plan:</h5>
                            <ul>
                                <li>Frecuencia: <?php echo esc_html($features['backup_frequency']); ?></li>
                                <li>Retención: 30 días</li>
                                <li>Restauración: Contactar soporte</li>
                            </ul>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Updates Tab -->
                    <div class="rpcare-tab-panel" id="rpcare-tab-updates">
                        <div class="rpcare-updates-header">
                            <h4>Control de Actualizaciones</h4>
                            <p class="rpcare-update-notice">
                                ℹ️ Las actualizaciones están gestionadas automáticamente por Replanta Care según tu plan.
                                Los plugins con licencia pueden actualizarse libremente.
                            </p>
                        </div>
                        
                        <div id="rpcare-updates-list">
                            <p>Cargando estado de actualizaciones...</p>
                        </div>
                        
                        <div class="rpcare-update-controls">
                            <button type="button" class="button" onclick="rpcareCheckUpdates()">
                                🔍 Verificar Actualizaciones
                            </button>
                        </div>
                    </div>
                    
                    <!-- Health Tab -->
                    <div class="rpcare-tab-panel" id="rpcare-tab-health">
                        <div class="rpcare-health-header">
                            <h4>Salud del Sitio Web</h4>
                        </div>
                        
                        <div id="rpcare-health-report">
                            <p>Cargando informe de salud...</p>
                        </div>
                        
                        <div class="rpcare-health-actions">
                            <button type="button" class="button" onclick="rpcareRunHealthCheck()">
                                🔍 Ejecutar Diagnóstico
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Loading Overlay -->
            <div id="rpcare-loading-overlay" style="display: none;">
                <div class="rpcare-spinner"></div>
                <p>Cargando...</p>
            </div>
        </div>
        
        <style>
        .rpcare-dashboard-widget {
            position: relative;
        }
        
        .rpcare-plan-info {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .rpcare-plan-info h3 {
            margin: 0;
            color: #333;
        }
        
        .rpcare-plan-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        
        .rpcare-plan-semilla { background: #4CAF50; }
        .rpcare-plan-raiz { background: #FF9800; }
        .rpcare-plan-ecosistema { background: #9C27B0; }
        
        .rpcare-tab-nav {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 20px;
        }
        
        .rpcare-tab-btn {
            background: none;
            border: none;
            padding: 10px 15px;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            font-weight: 500;
        }
        
        .rpcare-tab-btn.active {
            border-bottom-color: #0073aa;
            color: #0073aa;
        }
        
        .rpcare-tab-panel {
            display: none;
        }
        
        .rpcare-tab-panel.active {
            display: block;
        }
        
        .rpcare-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .rpcare-status-card {
            display: flex;
            align-items: center;
            padding: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .rpcare-status-icon {
            font-size: 24px;
            margin-right: 15px;
        }
        
        .rpcare-status-info h4 {
            margin: 0 0 5px 0;
            font-size: 14px;
            color: #333;
        }
        
        .rpcare-status-info p {
            margin: 0 0 5px 0;
            font-weight: bold;
        }
        
        .rpcare-status-info small {
            color: #666;
            font-size: 12px;
        }
        
        .rpcare-actions {
            text-align: center;
            padding: 15px 0;
            border-top: 1px solid #eee;
        }
        
        .rpcare-actions .button {
            margin: 0 5px;
        }
        
        .rpcare-backups-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .rpcare-backup-info {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        
        .rpcare-backup-info h5 {
            margin: 0 0 10px 0;
        }
        
        .rpcare-backup-info ul {
            margin: 0;
            padding-left: 20px;
        }
        
        .rpcare-update-notice {
            background: #e7f3ff;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
        }
        
        #rpcare-loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.9);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            z-index: 1000;
        }
        
        .rpcare-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #0073aa;
            border-radius: 50%;
            animation: rpcare-spin 1s linear infinite;
        }
        
        @keyframes rpcare-spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Tab functionality
            $('.rpcare-tab-btn').on('click', function() {
                var tab = $(this).data('tab');
                
                $('.rpcare-tab-btn').removeClass('active');
                $(this).addClass('active');
                
                $('.rpcare-tab-panel').removeClass('active');
                $('#rpcare-tab-' + tab).addClass('active');
                
                // Load tab data
                rpcareLoadTabData(tab);
            });
            
            // Load initial data
            rpcareLoadTabData('status');
        });
        
        function rpcareLoadTabData(tab) {
            jQuery.post(rpcare_dashboard.ajax_url, {
                action: 'rpcare_get_dashboard_data',
                tab: tab,
                nonce: rpcare_dashboard.nonce
            }, function(response) {
                if (response.success) {
                    rpcareUpdateTabContent(tab, response.data);
                }
            });
        }
        
        function rpcareUpdateTabContent(tab, data) {
            switch(tab) {
                case 'status':
                    jQuery('#rpcare-last-update').text(data.last_update || 'Nunca');
                    jQuery('#rpcare-last-backup').text(data.last_backup || 'Nunca');
                    jQuery('#rpcare-wpo-status').text(data.wpo_status || 'Pendiente');
                    break;
                    
                case 'backups':
                    var backupsList = '<div class="rpcare-backups">';
                    if (data.backups && data.backups.length > 0) {
                        data.backups.forEach(function(backup) {
                            backupsList += '<div class="rpcare-backup-item">';
                            backupsList += '<strong>' + backup.date + '</strong> - ' + backup.size;
                            backupsList += '</div>';
                        });
                    } else {
                        backupsList += '<p>No hay copias de seguridad disponibles.</p>';
                    }
                    backupsList += '</div>';
                    jQuery('#rpcare-backups-list').html(backupsList);
                    break;
                    
                case 'updates':
                    var updatesList = '<div class="rpcare-updates">';
                    if (data.updates && data.updates.length > 0) {
                        data.updates.forEach(function(update) {
                            updatesList += '<div class="rpcare-update-item">';
                            updatesList += '<strong>' + update.name + '</strong> - ' + update.status;
                            updatesList += '</div>';
                        });
                    } else {
                        updatesList += '<p>Todas las actualizaciones están al día.</p>';
                    }
                    updatesList += '</div>';
                    jQuery('#rpcare-updates-list').html(updatesList);
                    break;
                    
                case 'health':
                    var healthReport = '<div class="rpcare-health">';
                    if (data.health) {
                        healthReport += '<div class="rpcare-health-score">Puntuación: ' + data.health.score + '/100</div>';
                        healthReport += '<div class="rpcare-health-issues">' + (data.health.issues || 'Sin problemas detectados') + '</div>';
                    } else {
                        healthReport += '<p>Ejecuta un diagnóstico para ver el estado de salud.</p>';
                    }
                    healthReport += '</div>';
                    jQuery('#rpcare-health-report').html(healthReport);
                    break;
            }
        }
        
        function rpcareShowLoading() {
            jQuery('#rpcare-loading-overlay').show();
        }
        
        function rpcareHideLoading() {
            jQuery('#rpcare-loading-overlay').hide();
        }
        
        function rpcareRunTask(task) {
            rpcareShowLoading();
            
            jQuery.post(rpcare_dashboard.ajax_url, {
                action: 'rpcare_run_task',
                task: task,
                nonce: rpcare_dashboard.nonce
            }, function(response) {
                rpcareHideLoading();
                
                if (response.success) {
                    alert(rpcare_dashboard.strings.success);
                    rpcareRefreshData();
                } else {
                    alert(rpcare_dashboard.strings.error + ': ' + response.data);
                }
            });
        }
        
        function rpcareCreateBackup() {
            rpcareShowLoading();
            
            jQuery.post(rpcare_dashboard.ajax_url, {
                action: 'rpcare_create_backup',
                nonce: rpcare_dashboard.nonce
            }, function(response) {
                rpcareHideLoading();
                
                if (response.success) {
                    alert(rpcare_dashboard.strings.backup_created);
                    rpcareLoadTabData('backups');
                } else {
                    alert(rpcare_dashboard.strings.error + ': ' + response.data);
                }
            });
        }
        
        function rpcareCheckUpdates() {
            rpcareLoadTabData('updates');
        }
        
        function rpcareRunHealthCheck() {
            rpcareShowLoading();
            
            jQuery.post(rpcare_dashboard.ajax_url, {
                action: 'rpcare_get_health_report',
                nonce: rpcare_dashboard.nonce
            }, function(response) {
                rpcareHideLoading();
                
                if (response.success) {
                    rpcareUpdateTabContent('health', response.data);
                } else {
                    alert(rpcare_dashboard.strings.error + ': ' + response.data);
                }
            });
        }
        
        function rpcareRefreshData() {
            var activeTab = jQuery('.rpcare-tab-btn.active').data('tab');
            rpcareLoadTabData(activeTab);
        }
        </script>
        <?php
    }
    
    // AJAX Handlers
    public function ajax_get_dashboard_data() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        $tab = sanitize_text_field($_POST['tab'] ?? 'status');
        
        switch ($tab) {
            case 'status':
                $data = [
                    'last_update' => get_option('rpcare_last_update', 'Nunca'),
                    'last_backup' => get_option('rpcare_last_backup', 'Nunca'),
                    'wpo_status' => get_option('rpcare_wpo_status', 'Pendiente'),
                    'monitoring_status' => 'Activo'
                ];
                break;
                
            case 'backups':
                $data = [
                    'backups' => $this->get_backup_list()
                ];
                break;
                
            case 'updates':
                $data = [
                    'updates' => $this->get_updates_list()
                ];
                break;
                
            case 'health':
                $data = [
                    'health' => $this->get_health_data()
                ];
                break;
                
            default:
                $data = [];
        }
        
        wp_send_json_success($data);
    }
    
    public function ajax_run_task() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        $task = sanitize_text_field($_POST['task'] ?? '');
        
        switch ($task) {
            case 'sync':
                // Sync with hub
                $result = $this->sync_with_hub();
                break;
                
            default:
                wp_send_json_error('Tarea no reconocida');
        }
        
        if ($result) {
            wp_send_json_success('Tarea completada');
        } else {
            wp_send_json_error('Error al ejecutar la tarea');
        }
    }
    
    public function ajax_create_backup() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permisos insuficientes');
        }
        
        // Create backup using available backup plugin
        $result = $this->create_backup();
        
        if ($result) {
            wp_send_json_success('Copia de seguridad creada');
        } else {
            wp_send_json_error('Error al crear la copia de seguridad');
        }
    }
    
    public function ajax_get_health_report() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        $health_data = $this->generate_health_report();
        
        wp_send_json_success([
            'health' => $health_data
        ]);
    }
    
    // Helper methods
    private function get_backup_list() {
        // Get backups from available backup plugins
        $backups = [];
        
        // Check for UpdraftPlus
        if (class_exists('UpdraftPlus_Admin')) {
            // Get UpdraftPlus backups
            $backups = $this->get_updraftplus_backups();
        }
        
        // Check for other backup plugins
        // Add more backup plugin integrations here
        
        return $backups;
    }
    
    private function get_updraftplus_backups() {
        $backups = [];
        
        if (function_exists('updraftplus_backup_history')) {
            $history = updraftplus_backup_history();
            
            foreach ($history as $timestamp => $backup) {
                $backups[] = [
                    'date' => date('Y-m-d H:i:s', $timestamp),
                    'size' => $this->format_bytes($backup['size'] ?? 0),
                    'type' => $backup['type'] ?? 'full'
                ];
            }
        }
        
        return array_slice($backups, 0, 10); // Last 10 backups
    }
    
    private function get_updates_list() {
        $updates = [];
        
        // Get plugin updates
        $plugin_updates = get_site_transient('update_plugins');
        if ($plugin_updates && isset($plugin_updates->response)) {
            foreach ($plugin_updates->response as $plugin_file => $plugin_data) {
                $plugin_info = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
                
                $updates[] = [
                    'name' => $plugin_info['Name'],
                    'type' => 'plugin',
                    'current_version' => $plugin_info['Version'],
                    'new_version' => $plugin_data->new_version ?? '',
                    'status' => 'Gestionado por Replanta'
                ];
            }
        }
        
        // Get theme updates
        $theme_updates = get_site_transient('update_themes');
        if ($theme_updates && isset($theme_updates->response)) {
            foreach ($theme_updates->response as $theme_slug => $theme_data) {
                $theme_info = wp_get_theme($theme_slug);
                
                $updates[] = [
                    'name' => $theme_info->get('Name'),
                    'type' => 'theme',
                    'current_version' => $theme_info->get('Version'),
                    'new_version' => $theme_data['new_version'] ?? '',
                    'status' => 'Gestionado por Replanta'
                ];
            }
        }
        
        return $updates;
    }
    
    private function get_health_data() {
        // Get cached health data or generate new
        $health_data = get_transient('rpcare_health_data');
        
        if (!$health_data) {
            $health_data = $this->generate_health_report();
            set_transient('rpcare_health_data', $health_data, HOUR_IN_SECONDS);
        }
        
        return $health_data;
    }
    
    private function generate_health_report() {
        $score = 100;
        $issues = [];
        
        // Check WordPress version
        $wp_version = get_bloginfo('version');
        $latest_wp = $this->get_latest_wp_version();
        
        if (version_compare($wp_version, $latest_wp, '<')) {
            $score -= 10;
            $issues[] = 'WordPress no está en la última versión';
        }
        
        // Check SSL
        if (!is_ssl()) {
            $score -= 20;
            $issues[] = 'El sitio no tiene SSL habilitado';
        }
        
        // Check memory limit
        $memory_limit = ini_get('memory_limit');
        $memory_mb = intval($memory_limit);
        
        if ($memory_mb < 256) {
            $score -= 15;
            $issues[] = 'Límite de memoria PHP bajo (' . $memory_limit . ')';
        }
        
        // Check for outdated plugins
        $outdated_plugins = $this->count_outdated_plugins();
        if ($outdated_plugins > 5) {
            $score -= 10;
            $issues[] = $outdated_plugins . ' plugins desactualizados';
        }
        
        return [
            'score' => max(0, $score),
            'issues' => empty($issues) ? 'Sin problemas detectados' : implode(', ', $issues)
        ];
    }
    
    private function sync_with_hub() {
        // Sync data with Replanta Hub
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        $response = wp_remote_post(RP_Care_Plan::HUB_URL . '/wp-json/replanta/v1/sites/heartbeat', [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Site-Domain' => $domain,
                'User-Agent' => 'Replanta-Care/' . RPCARE_VERSION
            ],
            'body' => json_encode([
                'domain' => $domain,
                'wp_version' => get_bloginfo('version'),
                'plugin_version' => RPCARE_VERSION,
                'last_update' => get_option('rpcare_last_update'),
                'last_backup' => get_option('rpcare_last_backup')
            ]),
            'timeout' => 30
        ]);
        
        if (is_wp_error($response)) {
            error_log('Care: Sync error - ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        
        if ($response_code === 200) {
            update_option('rpcare_last_sync', current_time('mysql'));
            return true;
        }
        
        return false;
    }
    
    private function create_backup() {
        // Try to create backup using available backup plugins
        
        // UpdraftPlus
        if (class_exists('UpdraftPlus_Admin')) {
            return $this->create_updraftplus_backup();
        }
        
        // Add other backup plugin integrations here
        
        return false;
    }
    
    private function create_updraftplus_backup() {
        if (function_exists('updraftplus_backup_now')) {
            updraftplus_backup_now();
            update_option('rpcare_last_backup', current_time('mysql'));
            return true;
        }
        
        return false;
    }
    
    private function format_bytes($bytes, $precision = 2) {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    private function get_latest_wp_version() {
        $version_check = get_site_transient('update_core');
        
        if ($version_check && isset($version_check->updates[0])) {
            return $version_check->updates[0]->current;
        }
        
        return get_bloginfo('version');
    }
    
    private function count_outdated_plugins() {
        $plugin_updates = get_site_transient('update_plugins');
        
        if ($plugin_updates && isset($plugin_updates->response)) {
            return count($plugin_updates->response);
        }
        
        return 0;
    }
}
