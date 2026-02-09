<?php
/**
 * Replanta Care Dashboard Widget
 * Premium dashboard interface for maintenance clients
 * 
 * @package ReplantaCare
 * @since 1.2.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Dashboard {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget'], 1);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_rpcare_get_dashboard_data', [$this, 'ajax_get_dashboard_data']);
        add_action('wp_ajax_rpcare_create_backup', [$this, 'ajax_create_backup']);
        add_action('wp_ajax_rpcare_get_health_report', [$this, 'ajax_get_health_report']);
        
        // Silent background sync on admin init
        add_action('admin_init', [$this, 'maybe_background_sync']);
    }
    
    /**
     * Background sync - runs silently every 6 hours
     */
    public function maybe_background_sync() {
        $last_sync = get_option('rpcare_last_sync_timestamp', 0);
        $six_hours = 6 * HOUR_IN_SECONDS;
        
        if (time() - $last_sync > $six_hours) {
            $this->sync_with_hub_silent();
            update_option('rpcare_last_sync_timestamp', time());
        }
    }
    
    public function add_dashboard_widget() {
        $plan = RP_Care_Plan::get_current();
        
        if (!$plan) {
            return;
        }
        
        wp_add_dashboard_widget(
            'rpcare_dashboard',
            'Replanta Care',
            [$this, 'render_dashboard_widget'],
            null,
            null,
            'normal',
            'high'
        );
    }
    
    public function enqueue_assets($hook) {
        if ($hook !== 'index.php') {
            return;
        }
        
        // Inline styles - no external CSS needed
        add_action('admin_head', [$this, 'output_inline_styles']);
        
        wp_enqueue_script(
            'rpcare-dashboard',
            RPCARE_PLUGIN_URL . 'assets/js/dashboard.js',
            ['jquery'],
            RPCARE_VERSION,
            true
        );
        
        wp_localize_script('rpcare-dashboard', 'rpcare', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('rpcare_ajax'),
            'i18n' => [
                'loading' => 'Cargando...',
                'error' => 'Error al procesar',
                'success' => 'Completado',
                'backup_started' => 'Copia de seguridad iniciada',
                'syncing' => 'Sincronizando...'
            ]
        ]);
    }
    
    public function output_inline_styles() {
        echo '<style>' . $this->get_widget_styles() . '</style>';
    }
    
    public function render_dashboard_widget() {
        $plan = RP_Care_Plan::get_current();
        $plan_name = RP_Care_Plan::get_plan_name($plan);
        $features = RP_Care_Plan::get_features($plan);
        $status = $this->get_status_data();
        
        ?>
        <div class="rpcare-widget" data-plan="<?php echo esc_attr($plan); ?>">
            
            <!-- Header -->
            <div class="rpcare-header">
                <div class="rpcare-brand">
                    <img src="<?php echo RPCARE_PLUGIN_URL; ?>assets/img/ico.png" alt="Replanta" class="rpcare-logo">
                    <div class="rpcare-brand-text">
                        <span class="rpcare-title">Mantenimiento Activo</span>
                        <span class="rpcare-plan-label"><?php echo esc_html($plan_name); ?></span>
                    </div>
                </div>
                <div class="rpcare-status-indicator <?php echo $status['healthy'] ? 'status-ok' : 'status-warning'; ?>">
                    <span class="status-dot"></span>
                    <span class="status-text"><?php echo $status['healthy'] ? 'Todo en orden' : 'Requiere atención'; ?></span>
                </div>
            </div>
            
            <!-- Metrics Grid -->
            <div class="rpcare-metrics">
                
                <!-- Last Backup -->
                <div class="rpcare-metric">
                    <div class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                            <polyline points="17,21 17,13 7,13 7,21"/>
                            <polyline points="7,3 7,8 15,8"/>
                        </svg>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Última copia</span>
                        <span class="metric-value" id="rpcare-backup-time"><?php echo esc_html($status['last_backup']); ?></span>
                    </div>
                </div>
                
                <!-- Last Update -->
                <div class="rpcare-metric">
                    <div class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polyline points="23,4 23,10 17,10"/>
                            <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
                        </svg>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Última actualización</span>
                        <span class="metric-value" id="rpcare-update-time"><?php echo esc_html($status['last_update']); ?></span>
                    </div>
                </div>
                
                <!-- Site Health -->
                <div class="rpcare-metric">
                    <div class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                        </svg>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Salud del sitio</span>
                        <span class="metric-value metric-score" id="rpcare-health-score"><?php echo intval($status['health_score']); ?>%</span>
                    </div>
                </div>
                
                <!-- Security -->
                <div class="rpcare-metric">
                    <div class="metric-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                    </div>
                    <div class="metric-content">
                        <span class="metric-label">Seguridad</span>
                        <span class="metric-value"><?php echo esc_html($status['security_status']); ?></span>
                    </div>
                </div>
                
            </div>
            
            <!-- Quick Info -->
            <div class="rpcare-info-bar">
                <div class="info-item">
                    <span class="info-label">WordPress</span>
                    <span class="info-value"><?php echo get_bloginfo('version'); ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">PHP</span>
                    <span class="info-value"><?php echo PHP_VERSION; ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Plugins</span>
                    <span class="info-value"><?php echo count(get_option('active_plugins', [])); ?></span>
                </div>
            </div>
            
            <?php if (!empty($status['pending_updates']) && $status['pending_updates'] > 0): ?>
            <!-- Pending Updates Notice -->
            <div class="rpcare-notice">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="8" x2="12" y2="12"/>
                    <line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <span><?php echo intval($status['pending_updates']); ?> actualizaciones pendientes serán aplicadas automáticamente</span>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="rpcare-footer">
                <a href="<?php echo admin_url('admin.php?page=replanta-care'); ?>" class="rpcare-link">
                    Ver configuración completa
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="5" y1="12" x2="19" y2="12"/>
                        <polyline points="12,5 19,12 12,19"/>
                    </svg>
                </a>
                <span class="rpcare-version">v<?php echo RPCARE_VERSION; ?></span>
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Get current status data
     */
    private function get_status_data() {
        $health = $this->get_health_data();
        
        return [
            'last_backup' => $this->format_relative_time(get_option('rpcare_last_backup', '')),
            'last_update' => $this->format_relative_time(get_option('rpcare_last_update', '')),
            'health_score' => $health['score'] ?? 100,
            'security_status' => $this->get_security_status(),
            'pending_updates' => $this->count_pending_updates(),
            'healthy' => ($health['score'] ?? 100) >= 80 && $this->count_pending_updates() <= 5
        ];
    }
    
    /**
     * Format time as relative (hace X horas, etc)
     */
    private function format_relative_time($datetime) {
        if (empty($datetime)) {
            return 'Pendiente';
        }
        
        $timestamp = strtotime($datetime);
        if (!$timestamp) {
            return 'Pendiente';
        }
        
        $diff = time() - $timestamp;
        
        if ($diff < HOUR_IN_SECONDS) {
            $mins = round($diff / MINUTE_IN_SECONDS);
            return $mins <= 1 ? 'Hace un momento' : "Hace {$mins} min";
        } elseif ($diff < DAY_IN_SECONDS) {
            $hours = round($diff / HOUR_IN_SECONDS);
            return $hours == 1 ? 'Hace 1 hora' : "Hace {$hours} horas";
        } elseif ($diff < WEEK_IN_SECONDS) {
            $days = round($diff / DAY_IN_SECONDS);
            return $days == 1 ? 'Ayer' : "Hace {$days} días";
        } else {
            return date_i18n('j M', $timestamp);
        }
    }
    
    /**
     * Get security status label
     */
    private function get_security_status() {
        $issues = 0;
        
        // Check SSL
        if (!is_ssl()) $issues++;
        
        // Check debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) $issues++;
        
        // Check file editing
        if (!defined('DISALLOW_FILE_EDIT') || (defined('DISALLOW_FILE_EDIT') && !DISALLOW_FILE_EDIT)) $issues++;
        
        if ($issues === 0) {
            return 'Óptima';
        } elseif ($issues === 1) {
            return 'Buena';
        } else {
            return 'Revisar';
        }
    }
    
    /**
     * Count pending updates
     */
    private function count_pending_updates() {
        $count = 0;
        
        $plugin_updates = get_site_transient('update_plugins');
        if ($plugin_updates && isset($plugin_updates->response)) {
            $count += count($plugin_updates->response);
        }
        
        $theme_updates = get_site_transient('update_themes');
        if ($theme_updates && isset($theme_updates->response)) {
            $count += count($theme_updates->response);
        }
        
        return $count;
    }
    
    /**
     * Get health data
     */
    private function get_health_data() {
        $cached = get_transient('rpcare_health_data');
        if ($cached) {
            return $cached;
        }
        
        $health = $this->calculate_health_score();
        set_transient('rpcare_health_data', $health, HOUR_IN_SECONDS);
        
        return $health;
    }
    
    /**
     * Calculate health score
     */
    private function calculate_health_score() {
        $score = 100;
        $issues = [];
        
        // WordPress version check
        $updates = get_site_transient('update_core');
        if ($updates && !empty($updates->updates) && $updates->updates[0]->response === 'upgrade') {
            $score -= 10;
            $issues[] = 'WordPress desactualizado';
        }
        
        // SSL check
        if (!is_ssl()) {
            $score -= 15;
            $issues[] = 'SSL no activo';
        }
        
        // PHP version check
        if (version_compare(PHP_VERSION, '8.0', '<')) {
            $score -= 10;
            $issues[] = 'PHP desactualizado';
        }
        
        // Memory limit check
        $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($memory < 256 * MB_IN_BYTES) {
            $score -= 5;
            $issues[] = 'Memoria limitada';
        }
        
        // Too many pending updates
        $pending = $this->count_pending_updates();
        if ($pending > 10) {
            $score -= 10;
            $issues[] = 'Muchas actualizaciones pendientes';
        } elseif ($pending > 5) {
            $score -= 5;
        }
        
        return [
            'score' => max(0, $score),
            'issues' => $issues
        ];
    }
    
    /**
     * Silent sync with hub (non-blocking)
     */
    private function sync_with_hub_silent() {
        $site_url = get_site_url();
        $domain = parse_url($site_url, PHP_URL_HOST);
        
        if (!class_exists('RP_Care_Plan')) {
            return;
        }
        
        $hub_url = RP_Care_Plan::get_hub_url();
        
        wp_remote_post($hub_url . '/wp-json/replanta/v1/sites/heartbeat', [
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
                'health_score' => $this->get_health_data()['score'] ?? 100,
                'pending_updates' => $this->count_pending_updates(),
                'last_backup' => get_option('rpcare_last_backup'),
                'plan' => get_option('rpcare_plan', 'unknown')
            ]),
            'timeout' => 10,
            'blocking' => false,
            'sslverify' => true
        ]);
        
        update_option('rpcare_last_sync', current_time('mysql'));
    }
    
    // =========================================================================
    // AJAX Handlers
    // =========================================================================
    
    public function ajax_get_dashboard_data() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        wp_send_json_success([
            'status' => $this->get_status_data(),
            'health' => $this->get_health_data()
        ]);
    }
    
    public function ajax_create_backup() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sin permisos']);
        }
        
        $result = $this->trigger_backup();
        
        if ($result) {
            update_option('rpcare_last_backup', current_time('mysql'));
            wp_send_json_success(['message' => 'Copia de seguridad iniciada']);
        } else {
            wp_send_json_error(['message' => 'Plugin de backup no disponible']);
        }
    }
    
    public function ajax_get_health_report() {
        check_ajax_referer('rpcare_ajax', 'nonce');
        
        // Force refresh
        delete_transient('rpcare_health_data');
        $health = $this->calculate_health_score();
        set_transient('rpcare_health_data', $health, HOUR_IN_SECONDS);
        
        wp_send_json_success(['health' => $health]);
    }
    
    // =========================================================================
    // Backup Integration (Backuply)
    // =========================================================================
    
    /**
     * Get backup list from Backuply
     */
    private function get_backup_list() {
        $backups = [];
        
        // Backuply integration
        if (defined('SUSPENDED_BACKUPLY') || class_exists('backuply_backup')) {
            $backups = $this->get_backuply_backups();
        }
        
        return $backups;
    }
    
    /**
     * Get backups from Backuply
     */
    private function get_backuply_backups() {
        $backups = [];
        
        // Backuply stores backup info in options
        $backup_list = get_option('backuply_backup_list', []);
        
        if (is_array($backup_list)) {
            foreach (array_slice($backup_list, 0, 5) as $backup) {
                $backups[] = [
                    'date' => isset($backup['time']) ? date('Y-m-d H:i', $backup['time']) : '',
                    'size' => isset($backup['size']) ? size_format($backup['size']) : 'N/A',
                    'type' => isset($backup['type']) ? $backup['type'] : 'full'
                ];
            }
        }
        
        return $backups;
    }
    
    /**
     * Trigger backup via Backuply
     */
    private function trigger_backup() {
        // Backuply Pro integration
        if (function_exists('backuply_cron_backup')) {
            do_action('backuply_cron_backup');
            return true;
        }
        
        // Backuply free version
        if (class_exists('Suspended_Backuply') || defined('SUSPENDED_BACKUPLY')) {
            do_action('backuply_instant_backup');
            return true;
        }
        
        return false;
    }
    
    // =========================================================================
    // Widget Styles
    // =========================================================================
    
    private function get_widget_styles() {
        return '
@import url("https://fonts.googleapis.com/css2?family=Fraunces:wght@600&display=swap");

#rpcare_dashboard .inside { padding: 0; margin: 0; }
#rpcare_dashboard .postbox-header { display: none; }

.rpcare-widget {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    --rp-primary: #1E2F23;
    --rp-accent: #93F1C9;
    --rp-text: #7A7A7A;
    --rp-teal: #41999F;
    --rp-yellow: #F7D450;
}

.rpcare-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px;
    background: var(--rp-primary);
    color: #fff;
}

.rpcare-brand {
    display: flex;
    align-items: center;
    gap: 12px;
}

.rpcare-logo {
    width: 36px;
    height: 36px;
    object-fit: contain;
}

.rpcare-brand-text {
    display: flex;
    flex-direction: column;
}

.rpcare-title {
    font-family: "Fraunces", Georgia, serif;
    font-size: 15px;
    font-weight: 600;
    letter-spacing: -0.02em;
    color: #fff;
}

.rpcare-plan-label {
    font-size: 11px;
    color: var(--rp-accent);
    text-transform: uppercase;
    letter-spacing: 0.05em;
}

.rpcare-status-indicator {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    padding: 6px 12px;
    border-radius: 20px;
    background: rgba(147, 241, 201, 0.15);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--rp-accent);
}

.status-ok .status-dot { background: var(--rp-accent); }
.status-warning .status-dot { background: var(--rp-yellow); }

.rpcare-metrics {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1px;
    background: #e8ebe9;
}

.rpcare-metric {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #fff;
}

.metric-icon {
    width: 36px;
    height: 36px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(147, 241, 201, 0.15);
    border-radius: 8px;
    color: var(--rp-teal);
}

.metric-icon svg {
    width: 18px;
    height: 18px;
}

.metric-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.metric-label {
    font-size: 11px;
    color: var(--rp-text);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

.metric-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--rp-primary);
}

.metric-score {
    color: var(--rp-teal);
}

.rpcare-info-bar {
    display: flex;
    justify-content: space-around;
    padding: 12px 16px;
    background: #f8faf9;
    border-top: 1px solid #e8ebe9;
}

.info-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.info-label {
    font-size: 10px;
    color: var(--rp-text);
    text-transform: uppercase;
}

.info-value {
    font-size: 13px;
    font-weight: 500;
    color: var(--rp-primary);
}

.rpcare-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 16px;
    background: rgba(247, 212, 80, 0.12);
    border-top: 1px solid rgba(247, 212, 80, 0.3);
    font-size: 12px;
    color: #8a6d00;
}

.rpcare-notice svg {
    width: 16px;
    height: 16px;
    flex-shrink: 0;
    color: var(--rp-yellow);
}

.rpcare-footer {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 16px;
    background: #fff;
    border-top: 1px solid #e8ebe9;
}

.rpcare-link {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    font-weight: 500;
    color: var(--rp-teal);
    text-decoration: none;
    transition: color 0.15s;
}

.rpcare-link:hover {
    color: var(--rp-primary);
}

.rpcare-link svg {
    width: 14px;
    height: 14px;
}

.rpcare-version {
    font-size: 11px;
    color: var(--rp-text);
}

/* All plans use the same dark header now */
.rpcare-widget[data-plan="semilla"] .rpcare-header,
.rpcare-widget[data-plan="raiz"] .rpcare-header,
.rpcare-widget[data-plan="ecosistema"] .rpcare-header { 
    background: var(--rp-primary); 
}
        ';
    }
}
