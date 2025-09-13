<?php
/**
 * Dashboard Widget para Replanta Care
 * Muestra información del sitio, plan y estado
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Dashboard_Widget {
    
    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
        add_action('wp_ajax_rpcare_get_dashboard_data', [$this, 'ajax_get_dashboard_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_dashboard_scripts']);
    }
    
    public function add_dashboard_widget() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $plan = RP_Care_Plan::get_current();
        $plan_name = RP_Care_Plan::get_plan_name($plan);
        
        wp_add_dashboard_widget(
            'rpcare_dashboard_widget',
            '🛡️ Replanta Care - ' . $plan_name,
            [$this, 'render_dashboard_widget'],
            [$this, 'dashboard_widget_control']
        );
    }
    
    public function render_dashboard_widget() {
        $plan = RP_Care_Plan::get_current();
        $plan_config = RP_Care_Plan::get_plan_config($plan);
        $features = RP_Care_Plan::get_features($plan);
        $hub_connected = get_option('rpcare_hub_connected', false);
        
        // Get recent activity
        $recent_logs = $this->get_recent_activity();
        
        // Get site health score
        $health_score = $this->get_site_health_score();
        
        // Get last update check
        $last_check = get_option('rpcare_last_check', 'Nunca');
        if ($last_check !== 'Nunca') {
            $last_check = human_time_diff(strtotime($last_check)) . ' ago';
        }
        
        ?>
        <div class="rpcare-dashboard-widget">
            <!-- Plan Info -->
            <div class="rpcare-plan-info">
                <div class="rpcare-plan-badge <?php echo esc_attr($plan); ?>">
                    <?php echo esc_html($plan_config['name']); ?>
                </div>
                <div class="rpcare-plan-price">
                    <?php echo esc_html($plan_config['price']); ?>
                </div>
            </div>
            
            <!-- Site Health -->
            <div class="rpcare-health-section">
                <h4>🩺 Estado del Sitio</h4>
                <div class="rpcare-health-score">
                    <div class="rpcare-health-circle" data-score="<?php echo $health_score; ?>">
                        <span class="score"><?php echo $health_score; ?></span>
                        <span class="label">Health Score</span>
                    </div>
                    <div class="rpcare-health-details">
                        <div class="health-item">
                            <span class="status <?php echo $hub_connected ? 'connected' : 'disconnected'; ?>"></span>
                            Hub Replanta: <?php echo $hub_connected ? 'Conectado' : 'Desconectado'; ?>
                        </div>
                        <div class="health-item">
                            <span class="status checking"></span>
                            Última verificación: <?php echo esc_html($last_check); ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Features -->
            <div class="rpcare-features-section">
                <h4>✨ Características Activas</h4>
                <div class="rpcare-features-grid">
                    <?php if ($features['automatic_updates']): ?>
                    <div class="feature-item active">
                        <span class="icon">🔄</span>
                        <span class="label">Actualizaciones Automáticas</span>
                        <span class="frequency"><?php echo ucfirst($features['updates_frequency']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($features['backup']): ?>
                    <div class="feature-item active">
                        <span class="icon">💾</span>
                        <span class="label">Copias de Seguridad</span>
                        <span class="frequency"><?php echo ucfirst($features['backup_frequency']); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($features['monitoring']): ?>
                    <div class="feature-item active">
                        <span class="icon">📊</span>
                        <span class="label">Monitorización 24/7</span>
                        <span class="frequency">Activa</span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($features['priority_support']): ?>
                    <div class="feature-item active">
                        <span class="icon">🚀</span>
                        <span class="label">Soporte Prioritario</span>
                        <span class="frequency">Disponible</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="rpcare-activity-section">
                <h4>📋 Actividad Reciente</h4>
                <div class="rpcare-activity-list">
                    <?php if (empty($recent_logs)): ?>
                        <div class="no-activity">
                            <span class="icon">💤</span>
                            <span class="text">No hay actividad reciente</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($recent_logs as $log): ?>
                        <div class="activity-item <?php echo esc_attr($log->status); ?>">
                            <span class="time"><?php echo human_time_diff(strtotime($log->created_at)); ?> ago</span>
                            <span class="task"><?php echo esc_html($log->task_type); ?></span>
                            <span class="status"><?php echo esc_html($log->status); ?></span>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="rpcare-actions-section">
                <h4>⚡ Acciones Rápidas</h4>
                <div class="rpcare-quick-actions">
                    <a href="<?php echo admin_url('options-general.php?page=replanta-care'); ?>" class="rpcare-btn primary">
                        ⚙️ Configuración
                    </a>
                    <button type="button" class="rpcare-btn secondary" id="rpcare-force-check">
                        🔍 Verificar Estado
                    </button>
                    <?php if ($features['backup']): ?>
                    <button type="button" class="rpcare-btn secondary" id="rpcare-force-backup">
                        💾 Backup Manual
                    </button>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Control de Actualizaciones -->
            <?php if ($features['update_control']): ?>
            <div class="rpcare-update-control-section">
                <h4>🔒 Control de Actualizaciones</h4>
                <div class="update-control-info">
                    <p>Las actualizaciones están siendo gestionadas automáticamente por Replanta Care.</p>
                    <p><small>Los plugins con licencia pueden actualizarse libremente.</small></p>
                    <a href="<?php echo admin_url('plugins.php'); ?>" class="rpcare-link">Ver plugins →</a>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <style>
        .rpcare-dashboard-widget {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        
        .rpcare-plan-info {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            color: white;
        }
        
        .rpcare-plan-badge {
            font-weight: 600;
            font-size: 16px;
        }
        
        .rpcare-plan-price {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .rpcare-health-section {
            margin-bottom: 20px;
        }
        
        .rpcare-health-section h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #555;
        }
        
        .rpcare-health-score {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .rpcare-health-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: conic-gradient(#4CAF50 0deg, #4CAF50 calc(3.6deg * var(--score)), #f0f0f0 calc(3.6deg * var(--score)));
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .rpcare-health-circle::before {
            content: '';
            position: absolute;
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 50%;
        }
        
        .rpcare-health-circle .score {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            z-index: 1;
        }
        
        .rpcare-health-circle .label {
            font-size: 10px;
            color: #666;
            z-index: 1;
        }
        
        .rpcare-health-details {
            flex: 1;
        }
        
        .health-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 5px;
            font-size: 13px;
        }
        
        .health-item .status {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }
        
        .health-item .status.connected {
            background: #4CAF50;
        }
        
        .health-item .status.disconnected {
            background: #f44336;
        }
        
        .health-item .status.checking {
            background: #ff9800;
        }
        
        .rpcare-features-section,
        .rpcare-activity-section,
        .rpcare-actions-section,
        .rpcare-update-control-section {
            margin-bottom: 20px;
        }
        
        .rpcare-features-section h4,
        .rpcare-activity-section h4,
        .rpcare-actions-section h4,
        .rpcare-update-control-section h4 {
            margin: 0 0 10px 0;
            font-size: 14px;
            color: #555;
        }
        
        .rpcare-features-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        
        .feature-item {
            padding: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            background: #f9f9f9;
        }
        
        .feature-item.active {
            background: #e8f5e8;
            border-color: #4CAF50;
        }
        
        .feature-item .icon {
            font-size: 16px;
            margin-right: 5px;
        }
        
        .feature-item .label {
            display: block;
            font-size: 12px;
            font-weight: 500;
        }
        
        .feature-item .frequency {
            display: block;
            font-size: 11px;
            color: #666;
            margin-top: 2px;
        }
        
        .rpcare-activity-list {
            max-height: 150px;
            overflow-y: auto;
        }
        
        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            font-size: 12px;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-item .time {
            color: #666;
            flex: 0 0 auto;
        }
        
        .activity-item .task {
            flex: 1;
            margin: 0 10px;
        }
        
        .activity-item .status {
            flex: 0 0 auto;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
            text-transform: uppercase;
        }
        
        .activity-item.success .status {
            background: #e8f5e8;
            color: #4CAF50;
        }
        
        .activity-item.error .status {
            background: #ffebee;
            color: #f44336;
        }
        
        .activity-item.running .status {
            background: #e3f2fd;
            color: #2196F3;
        }
        
        .no-activity {
            text-align: center;
            padding: 20px;
            color: #666;
        }
        
        .rpcare-quick-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .rpcare-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .rpcare-btn.primary {
            background: #0073aa;
            color: white;
        }
        
        .rpcare-btn.primary:hover {
            background: #005a87;
            color: white;
        }
        
        .rpcare-btn.secondary {
            background: #f0f0f0;
            color: #333;
            border: 1px solid #ccc;
        }
        
        .rpcare-btn.secondary:hover {
            background: #e0e0e0;
        }
        
        .update-control-info {
            background: #e7f3ff;
            padding: 12px;
            border-radius: 6px;
            border-left: 4px solid #0073aa;
        }
        
        .update-control-info p {
            margin: 0 0 5px 0;
            font-size: 13px;
        }
        
        .update-control-info small {
            color: #666;
        }
        
        .rpcare-link {
            color: #0073aa;
            text-decoration: none;
            font-size: 12px;
        }
        
        .rpcare-link:hover {
            text-decoration: underline;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Set CSS custom property for health score
            const healthCircle = document.querySelector('.rpcare-health-circle');
            if (healthCircle) {
                const score = healthCircle.getAttribute('data-score');
                healthCircle.style.setProperty('--score', score);
            }
            
            // Force check button
            $('#rpcare-force-check').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('🔄 Verificando...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rpcare_force_check',
                        nonce: '<?php echo wp_create_nonce('rpcare_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Error al verificar estado: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Error de conexión');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('🔍 Verificar Estado');
                    }
                });
            });
            
            // Force backup button
            $('#rpcare-force-backup').on('click', function() {
                const $btn = $(this);
                $btn.prop('disabled', true).text('💾 Creando...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'rpcare_force_backup',
                        nonce: '<?php echo wp_create_nonce('rpcare_ajax'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('Backup creado exitosamente');
                            location.reload();
                        } else {
                            alert('Error al crear backup: ' + response.data);
                        }
                    },
                    error: function() {
                        alert('Error de conexión');
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('💾 Backup Manual');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    public function dashboard_widget_control() {
        // Widget control options if needed
    }
    
    private function get_recent_activity() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_logs';
        
        return $wpdb->get_results("
            SELECT * FROM $table_name 
            ORDER BY created_at DESC 
            LIMIT 5
        ");
    }
    
    private function get_site_health_score() {
        // Calculate basic health score
        $score = 100;
        
        // Check if hub is connected
        if (!get_option('rpcare_hub_connected', false)) {
            $score -= 20;
        }
        
        // Check last successful task
        $last_check = get_option('rpcare_last_check');
        if (!$last_check || strtotime($last_check) < strtotime('-1 week')) {
            $score -= 15;
        }
        
        // Check WordPress version
        if (version_compare(get_bloginfo('version'), '6.0', '<')) {
            $score -= 10;
        }
        
        // Check plugin updates
        $update_plugins = get_site_transient('update_plugins');
        if (!empty($update_plugins->response)) {
            $score -= 5;
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $score -= 10;
        }
        
        return max(0, min(100, $score));
    }
    
    public function enqueue_dashboard_scripts($hook) {
        if ($hook !== 'index.php') {
            return;
        }
        
        wp_enqueue_script('jquery');
    }
    
    public function ajax_get_dashboard_data() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        $data = [
            'health_score' => $this->get_site_health_score(),
            'recent_activity' => $this->get_recent_activity(),
            'plan' => RP_Care_Plan::get_current(),
            'hub_connected' => get_option('rpcare_hub_connected', false),
            'last_check' => get_option('rpcare_last_check', 'Nunca')
        ];
        
        wp_send_json_success($data);
    }
}

// Initialize dashboard widget
new RP_Care_Dashboard_Widget();
