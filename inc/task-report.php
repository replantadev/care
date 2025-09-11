<?php
/**
 * Report Generation Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Report {
    
    public static function generate_monthly($args = []) {
        $plan = RP_Care_Plan::get_current();
        $site_url = get_site_url();
        $report_data = self::collect_report_data();
        
        // Generate HTML report
        $html_report = self::generate_html_report($report_data, $plan);
        
        // Save report
        $report_id = self::save_report($html_report, $report_data);
        
        // Send email if configured
        $email_recipient = get_option('rpcare_email_reports', get_option('admin_email'));
        if ($email_recipient && !empty($email_recipient)) {
            self::send_email_report($email_recipient, $html_report, $report_data, $plan);
        }
        
        // Notify hub
        RP_Care_Utils::send_notification(
            'monthly_report_generated',
            'Monthly Report Generated',
            "Monthly report generated for $site_url",
            ['report_id' => $report_id]
        );
        
        RP_Care_Utils::log('report_generation', 'success', 'Monthly report generated', [
            'report_id' => $report_id,
            'plan' => $plan
        ]);
        
        return [
            'report_id' => $report_id,
            'html_length' => strlen($html_report),
            'email_sent' => !empty($email_recipient)
        ];
    }
    
    private static function collect_report_data() {
        $report_data = [
            'site_info' => [
                'name' => get_bloginfo('name'),
                'url' => get_site_url(),
                'wp_version' => get_bloginfo('version'),
                'php_version' => PHP_VERSION,
                'plan' => RP_Care_Plan::get_current(),
                'report_date' => current_time('mysql'),
                'report_period' => date('Y-m-01') . ' to ' . date('Y-m-t')
            ],
            'tasks_summary' => self::get_tasks_summary(),
            'security_status' => self::get_security_status(),
            'performance_metrics' => self::get_performance_metrics(),
            'seo_status' => self::get_seo_status(),
            'error_404_summary' => self::get_404_summary(),
            'backup_status' => self::get_backup_summary(),
            'recommendations' => self::generate_recommendations()
        ];
        
        return $report_data;
    }
    
    private static function get_tasks_summary() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_logs';
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');
        
        $tasks_summary = $wpdb->get_results($wpdb->prepare("
            SELECT 
                task_type,
                COUNT(*) as total_runs,
                SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful_runs,
                SUM(CASE WHEN status = 'error' THEN 1 ELSE 0 END) as failed_runs,
                MAX(created_at) as last_run
            FROM $table_name 
            WHERE created_at BETWEEN %s AND %s 
            GROUP BY task_type
            ORDER BY task_type
        ", $start_date, $end_date));
        
        $summary = [];
        foreach ($tasks_summary as $task) {
            $success_rate = $task->total_runs > 0 ? ($task->successful_runs / $task->total_runs) * 100 : 0;
            $summary[$task->task_type] = [
                'total_runs' => (int) $task->total_runs,
                'successful_runs' => (int) $task->successful_runs,
                'failed_runs' => (int) $task->failed_runs,
                'success_rate' => round($success_rate, 1),
                'last_run' => $task->last_run
            ];
        }
        
        return $summary;
    }
    
    private static function get_security_status() {
        return [
            'ssl_enabled' => is_ssl(),
            'wp_version_current' => self::is_wp_version_current(),
            'admin_user_secure' => !get_user_by('login', 'admin'),
            'file_permissions' => self::check_basic_file_permissions(),
            'security_plugins' => self::get_active_security_plugins()
        ];
    }
    
    private static function get_performance_metrics() {
        return [
            'performance_score' => RP_Care_Utils::get_performance_score(),
            'caching_enabled' => !empty(RP_Care_Utils::detect_caching_plugins()),
            'caching_plugins' => RP_Care_Utils::detect_caching_plugins(),
            'image_optimization' => self::has_image_optimization(),
            'database_size' => self::get_database_size(),
            'media_library_size' => self::get_media_library_size()
        ];
    }
    
    private static function get_seo_status() {
        $seo_plugin = RP_Care_Utils::detect_seo_plugin();
        
        return [
            'seo_plugin' => $seo_plugin,
            'sitemap_exists' => self::check_sitemap_exists(),
            'robots_txt_exists' => self::check_robots_txt_exists(),
            'meta_tags_coverage' => self::calculate_meta_coverage(),
            'ssl_enabled' => is_ssl()
        ];
    }
    
    private static function get_404_summary() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'rpcare_404_logs';
        $start_date = date('Y-m-01 00:00:00');
        $end_date = date('Y-m-t 23:59:59');
        
        $summary = $wpdb->get_row($wpdb->prepare("
            SELECT 
                COUNT(*) as total_404s,
                SUM(hits) as total_hits,
                COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_404s,
                COUNT(CASE WHEN suggested_redirect IS NOT NULL THEN 1 END) as with_suggestions
            FROM $table_name 
            WHERE last_seen BETWEEN %s AND %s
        ", $start_date, $end_date));
        
        $top_404s = $wpdb->get_results($wpdb->prepare("
            SELECT url, hits, status, suggested_redirect
            FROM $table_name 
            WHERE last_seen BETWEEN %s AND %s
            ORDER BY hits DESC 
            LIMIT 10
        ", $start_date, $end_date));
        
        return [
            'total_404s' => (int) ($summary->total_404s ?? 0),
            'total_hits' => (int) ($summary->total_hits ?? 0),
            'resolved_404s' => (int) ($summary->resolved_404s ?? 0),
            'with_suggestions' => (int) ($summary->with_suggestions ?? 0),
            'top_404s' => $top_404s
        ];
    }
    
    private static function get_backup_summary() {
        $last_backup = get_option('rpcare_last_backup', '');
        $backup_frequency = RP_Care_Plan::get_backup_frequency();
        
        return [
            'last_backup' => $last_backup,
            'backup_frequency' => $backup_frequency,
            'backup_plugin' => RP_Care_Utils::detect_backup_plugin(),
            'days_since_backup' => $last_backup ? round((time() - strtotime($last_backup)) / DAY_IN_SECONDS, 1) : null
        ];
    }
    
    private static function generate_recommendations() {
        $recommendations = [];
        
        // SSL recommendation
        if (!is_ssl()) {
            $recommendations[] = [
                'type' => 'security',
                'priority' => 'high',
                'title' => 'Enable SSL Certificate',
                'description' => 'Your site is not using HTTPS. This affects SEO and user trust.'
            ];
        }
        
        // Caching recommendation
        if (empty(RP_Care_Utils::detect_caching_plugins())) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'title' => 'Install Caching Plugin',
                'description' => 'A caching plugin can significantly improve your site\'s loading speed.'
            ];
        }
        
        // SEO plugin recommendation
        if (RP_Care_Utils::detect_seo_plugin() === 'None') {
            $recommendations[] = [
                'type' => 'seo',
                'priority' => 'medium',
                'title' => 'Install SEO Plugin',
                'description' => 'An SEO plugin helps optimize your content for search engines.'
            ];
        }
        
        // WordPress version recommendation
        if (!self::is_wp_version_current()) {
            $recommendations[] = [
                'type' => 'security',
                'priority' => 'high',
                'title' => 'Update WordPress',
                'description' => 'Your WordPress version is outdated. Updates include security fixes.'
            ];
        }
        
        // Performance score recommendation
        $performance_score = RP_Care_Utils::get_performance_score();
        if ($performance_score < 70) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'title' => 'Improve Site Performance',
                'description' => 'Your site performance score is below optimal. Consider optimizing images and enabling caching.'
            ];
        }
        
        return $recommendations;
    }
    
    private static function generate_html_report($data, $plan) {
        $branding_logo = get_option('rpcare_branding_logo', RPCARE_PLUGIN_URL . 'assets/replanta-logo.png');
        $branding_color = get_option('rpcare_branding_color', '#2c5530');
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Informe Mensual - <?php echo esc_html($data['site_info']['name']); ?></title>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; margin: 0; padding: 20px; background: #f5f5f5; }
                .container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { text-align: center; border-bottom: 3px solid <?php echo esc_attr($branding_color); ?>; padding-bottom: 20px; margin-bottom: 30px; }
                .logo { max-height: 60px; margin-bottom: 15px; }
                h1 { color: <?php echo esc_attr($branding_color); ?>; margin: 0; font-size: 28px; }
                h2 { color: <?php echo esc_attr($branding_color); ?>; border-bottom: 2px solid #eee; padding-bottom: 10px; }
                .site-info { background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 25px; }
                .metric { display: inline-block; margin: 10px 15px; text-align: center; }
                .metric-value { font-size: 24px; font-weight: bold; color: <?php echo esc_attr($branding_color); ?>; }
                .metric-label { font-size: 12px; color: #666; }
                .status-good { color: #28a745; }
                .status-warning { color: #ffc107; }
                .status-error { color: #dc3545; }
                .recommendations { background: #fff3cd; padding: 15px; border-radius: 5px; border-left: 4px solid #ffc107; }
                .recommendation { margin-bottom: 10px; }
                .priority-high { color: #dc3545; font-weight: bold; }
                .priority-medium { color: #ffc107; font-weight: bold; }
                .priority-low { color: #28a745; }
                table { width: 100%; border-collapse: collapse; margin: 15px 0; }
                th, td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                th { background: <?php echo esc_attr($branding_color); ?>; color: white; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; color: #666; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <?php if ($branding_logo): ?>
                        <img src="<?php echo esc_url($branding_logo); ?>" alt="Logo" class="logo">
                    <?php endif; ?>
                    <h1>Informe de Mantenimiento</h1>
                    <p><?php echo esc_html($data['site_info']['report_period']); ?></p>
                </div>
                
                <div class="site-info">
                    <h2>Información del Sitio</h2>
                    <strong><?php echo esc_html($data['site_info']['name']); ?></strong><br>
                    <a href="<?php echo esc_url($data['site_info']['url']); ?>"><?php echo esc_html($data['site_info']['url']); ?></a><br>
                    Plan: <strong><?php echo esc_html(ucfirst($data['site_info']['plan'])); ?></strong> | 
                    WordPress: <?php echo esc_html($data['site_info']['wp_version']); ?> | 
                    PHP: <?php echo esc_html($data['site_info']['php_version']); ?>
                </div>
                
                <h2>Resumen de Tareas</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Tarea</th>
                            <th>Ejecuciones</th>
                            <th>Éxito</th>
                            <th>Errores</th>
                            <th>Tasa de Éxito</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($data['tasks_summary'] as $task_type => $summary): ?>
                        <tr>
                            <td><?php echo esc_html(ucfirst(str_replace('_', ' ', $task_type))); ?></td>
                            <td><?php echo esc_html($summary['total_runs']); ?></td>
                            <td class="status-good"><?php echo esc_html($summary['successful_runs']); ?></td>
                            <td class="status-error"><?php echo esc_html($summary['failed_runs']); ?></td>
                            <td>
                                <span class="<?php echo $summary['success_rate'] >= 90 ? 'status-good' : ($summary['success_rate'] >= 70 ? 'status-warning' : 'status-error'); ?>">
                                    <?php echo esc_html($summary['success_rate']); ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <h2>Estado de Seguridad</h2>
                <div>
                    <div class="metric">
                        <div class="metric-value <?php echo $data['security_status']['ssl_enabled'] ? 'status-good' : 'status-error'; ?>">
                            <?php echo $data['security_status']['ssl_enabled'] ? '✓' : '✗'; ?>
                        </div>
                        <div class="metric-label">SSL</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value <?php echo $data['security_status']['wp_version_current'] ? 'status-good' : 'status-warning'; ?>">
                            <?php echo $data['security_status']['wp_version_current'] ? '✓' : '!'; ?>
                        </div>
                        <div class="metric-label">WP Actualizado</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value <?php echo $data['security_status']['admin_user_secure'] ? 'status-good' : 'status-warning'; ?>">
                            <?php echo $data['security_status']['admin_user_secure'] ? '✓' : '!'; ?>
                        </div>
                        <div class="metric-label">Usuario Admin</div>
                    </div>
                </div>
                
                <h2>Rendimiento</h2>
                <div>
                    <div class="metric">
                        <div class="metric-value status-good"><?php echo esc_html($data['performance_metrics']['performance_score']); ?>%</div>
                        <div class="metric-label">Puntuación</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value <?php echo $data['performance_metrics']['caching_enabled'] ? 'status-good' : 'status-warning'; ?>">
                            <?php echo $data['performance_metrics']['caching_enabled'] ? '✓' : '✗'; ?>
                        </div>
                        <div class="metric-label">Caché</div>
                    </div>
                </div>
                
                <?php if (!empty($data['error_404_summary']['total_404s'])): ?>
                <h2>Errores 404</h2>
                <div>
                    <div class="metric">
                        <div class="metric-value status-warning"><?php echo esc_html($data['error_404_summary']['total_404s']); ?></div>
                        <div class="metric-label">URLs 404</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value status-info"><?php echo esc_html($data['error_404_summary']['total_hits']); ?></div>
                        <div class="metric-label">Total Hits</div>
                    </div>
                    <div class="metric">
                        <div class="metric-value status-good"><?php echo esc_html($data['error_404_summary']['resolved_404s']); ?></div>
                        <div class="metric-label">Resueltos</div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($data['recommendations'])): ?>
                <h2>Recomendaciones</h2>
                <div class="recommendations">
                    <?php foreach ($data['recommendations'] as $rec): ?>
                    <div class="recommendation">
                        <span class="priority-<?php echo esc_attr($rec['priority']); ?>">[<?php echo esc_html(strtoupper($rec['priority'])); ?>]</span>
                        <strong><?php echo esc_html($rec['title']); ?></strong><br>
                        <?php echo esc_html($rec['description']); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                
                <div class="footer">
                    <p>Informe generado el <?php echo esc_html(current_time('d/m/Y H:i')); ?> por Replanta Care</p>
                    <?php if (get_option('rpcare_branding_footer', true)): ?>
                    <p><a href="https://replanta.dev" style="color: <?php echo esc_attr($branding_color); ?>;">Powered by Replanta</a></p>
                    <?php endif; ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        
        return ob_get_clean();
    }
    
    private static function save_report($html_report, $report_data) {
        $upload_dir = wp_upload_dir();
        $reports_dir = $upload_dir['basedir'] . '/rpcare-reports';
        
        if (!is_dir($reports_dir)) {
            wp_mkdir_p($reports_dir);
        }
        
        $report_id = 'report_' . date('Y_m_d_H_i_s') . '_' . wp_generate_password(8, false);
        $html_file = $reports_dir . '/' . $report_id . '.html';
        $json_file = $reports_dir . '/' . $report_id . '.json';
        
        // Save HTML report
        file_put_contents($html_file, $html_report);
        
        // Save JSON data
        file_put_contents($json_file, wp_json_encode($report_data, JSON_PRETTY_PRINT));
        
        // Store report reference in database
        $reports = get_option('rpcare_reports_history', []);
        $reports[] = [
            'id' => $report_id,
            'generated_at' => current_time('mysql'),
            'plan' => RP_Care_Plan::get_current(),
            'html_file' => $html_file,
            'json_file' => $json_file
        ];
        
        // Keep only last 12 reports
        $reports = array_slice($reports, -12);
        update_option('rpcare_reports_history', $reports);
        
        return $report_id;
    }
    
    private static function send_email_report($recipient, $html_report, $report_data, $plan) {
        $site_name = get_bloginfo('name');
        $month_year = date('F Y');
        
        $subject = "Informe de mantenimiento - $site_name - $month_year";
        
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . get_option('rpcare_email_from', 'noreply@' . parse_url(get_site_url(), PHP_URL_HOST))
        ];
        
        return wp_mail($recipient, $subject, $html_report, $headers);
    }
    
    // Helper functions
    private static function is_wp_version_current() {
        $updates = get_site_transient('update_core');
        return empty($updates) || empty($updates->updates) || $updates->updates[0]->response !== 'upgrade';
    }
    
    private static function check_basic_file_permissions() {
        $wp_config_perms = substr(sprintf('%o', fileperms(ABSPATH . 'wp-config.php')), -3);
        return in_array($wp_config_perms, ['644', '600']);
    }
    
    private static function get_active_security_plugins() {
        $security_plugins = [
            'wordfence/wordfence.php' => 'Wordfence',
            'better-wp-security/better-wp-security.php' => 'iThemes Security',
            'sucuri-scanner/sucuri.php' => 'Sucuri Security'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        $active_security = [];
        
        foreach ($security_plugins as $plugin_file => $plugin_name) {
            if (in_array($plugin_file, $active_plugins)) {
                $active_security[] = $plugin_name;
            }
        }
        
        return $active_security;
    }
    
    private static function has_image_optimization() {
        $image_plugins = [
            'smush/wp-smush.php',
            'shortpixel-image-optimiser/wp-shortpixel.php',
            'ewww-image-optimizer/ewww-image-optimizer.php'
        ];
        
        $active_plugins = get_option('active_plugins', []);
        
        foreach ($image_plugins as $plugin) {
            if (in_array($plugin, $active_plugins)) {
                return true;
            }
        }
        
        return false;
    }
    
    private static function get_database_size() {
        global $wpdb;
        
        $size = $wpdb->get_var("
            SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) 
            FROM information_schema.tables 
            WHERE table_schema = '" . DB_NAME . "'
        ");
        
        return $size ? $size . ' MB' : 'Unknown';
    }
    
    private static function get_media_library_size() {
        $upload_dir = wp_upload_dir();
        $size = RP_Care_Utils::get_disk_usage();
        return RP_Care_Utils::format_bytes($size);
    }
    
    private static function check_sitemap_exists() {
        $site_url = get_site_url();
        $response = wp_remote_head($site_url . '/sitemap.xml');
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    private static function check_robots_txt_exists() {
        $site_url = get_site_url();
        $response = wp_remote_head($site_url . '/robots.txt');
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    private static function calculate_meta_coverage() {
        global $wpdb;
        
        $total_posts = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_status = 'publish' 
            AND post_type IN ('post', 'page', 'product')
        ");
        
        if (!$total_posts) return 0;
        
        $seo_plugin = RP_Care_Utils::detect_seo_plugin();
        $meta_key = '';
        
        switch ($seo_plugin) {
            case 'Yoast SEO':
                $meta_key = '_yoast_wpseo_title';
                break;
            case 'Rank Math':
                $meta_key = 'rank_math_title';
                break;
            default:
                return 0;
        }
        
        $posts_with_meta = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_status = 'publish' 
            AND p.post_type IN ('post', 'page', 'product')
            AND pm.meta_key = %s
            AND pm.meta_value != ''
        ", $meta_key));
        
        return round(($posts_with_meta / $total_posts) * 100, 1);
    }
}
