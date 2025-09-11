<?php
/**
 * Health Check and Monitoring Task
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Task_Health {
    
    public static function run($args = []) {
        $health_checks = [
            'ssl_status' => self::check_ssl_certificate(),
            'wp_version' => self::check_wp_version(),
            'plugin_vulnerabilities' => self::check_plugin_vulnerabilities(),
            'file_permissions' => self::check_file_permissions(),
            'database_connection' => self::check_database_connection(),
            'memory_usage' => self::check_memory_usage(),
            'disk_space' => self::check_disk_space(),
            'cron_status' => self::check_cron_status(),
            'email_functionality' => self::check_email_functionality()
        ];
        
        // Calculate overall health score
        $health_score = self::calculate_health_score($health_checks);
        $health_checks['overall_score'] = $health_score;
        
        // Log critical issues
        self::log_critical_issues($health_checks);
        
        RP_Care_Utils::log('health_check', 'success', "Health check completed with score: $health_score%", $health_checks);
        
        return $health_checks;
    }
    
    public static function run_monitoring($args = []) {
        $monitoring_results = [
            'uptime_check' => self::perform_uptime_check(),
            'response_time' => self::measure_response_time(),
            'error_log_check' => self::check_error_logs(),
            'security_scan' => self::perform_basic_security_scan(),
            'backup_status' => self::check_backup_status()
        ];
        
        // Store monitoring data
        self::store_monitoring_data($monitoring_results);
        
        // Check for alerts
        self::check_monitoring_alerts($monitoring_results);
        
        RP_Care_Utils::log('monitoring', 'success', 'Monitoring check completed', $monitoring_results);
        
        return $monitoring_results;
    }
    
    private static function check_ssl_certificate() {
        $site_url = get_site_url();
        
        if (!is_ssl()) {
            return [
                'status' => 'warning',
                'message' => 'SSL not enabled',
                'recommendation' => 'Enable SSL for better security'
            ];
        }
        
        $parsed_url = parse_url($site_url);
        $host = $parsed_url['host'];
        
        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ]);
            
            $socket = stream_socket_client(
                "ssl://$host:443",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$socket) {
                return [
                    'status' => 'error',
                    'message' => 'Could not connect to SSL port',
                    'error' => $errstr
                ];
            }
            
            $cert = stream_context_get_params($socket)['options']['ssl']['peer_certificate'];
            $cert_info = openssl_x509_parse($cert);
            
            fclose($socket);
            
            $expiry_date = $cert_info['validTo_time_t'];
            $days_until_expiry = ($expiry_date - time()) / DAY_IN_SECONDS;
            
            if ($days_until_expiry < 0) {
                $status = 'error';
                $message = 'SSL certificate has expired';
            } elseif ($days_until_expiry < 30) {
                $status = 'warning';
                $message = "SSL certificate expires in " . round($days_until_expiry) . " days";
            } else {
                $status = 'good';
                $message = "SSL certificate valid for " . round($days_until_expiry) . " more days";
            }
            
            return [
                'status' => $status,
                'message' => $message,
                'expiry_date' => date('Y-m-d', $expiry_date),
                'days_until_expiry' => round($days_until_expiry),
                'issuer' => $cert_info['issuer']['CN'] ?? 'Unknown'
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error checking SSL certificate',
                'error' => $e->getMessage()
            ];
        }
    }
    
    private static function check_wp_version() {
        $current_version = get_bloginfo('version');
        $latest_version = null;
        
        // Try to get latest WordPress version
        $updates = get_site_transient('update_core');
        if ($updates && !empty($updates->updates)) {
            foreach ($updates->updates as $update) {
                if ($update->response === 'upgrade') {
                    $latest_version = $update->current;
                    break;
                }
            }
        }
        
        if (!$latest_version) {
            return [
                'status' => 'info',
                'current_version' => $current_version,
                'message' => 'Could not determine latest WordPress version'
            ];
        }
        
        $version_compare = version_compare($current_version, $latest_version);
        
        if ($version_compare < 0) {
            // Check if it's a major version difference
            $current_major = (int) $current_version;
            $latest_major = (int) $latest_version;
            
            if ($latest_major > $current_major) {
                $status = 'warning';
                $message = "WordPress major update available: $current_version → $latest_version";
            } else {
                $status = 'info';
                $message = "WordPress minor update available: $current_version → $latest_version";
            }
        } else {
            $status = 'good';
            $message = "WordPress is up to date ($current_version)";
        }
        
        return [
            'status' => $status,
            'current_version' => $current_version,
            'latest_version' => $latest_version,
            'message' => $message
        ];
    }
    
    private static function check_plugin_vulnerabilities() {
        $active_plugins = get_option('active_plugins', []);
        $plugin_data = [];
        $vulnerabilities_found = 0;
        
        // Known vulnerable plugins (this would ideally come from a security API)
        $known_vulnerable = [
            'really-simple-captcha' => ['version' => '1.0.0', 'vulnerability' => 'XSS vulnerability'],
            'wp-super-cache' => ['version' => '1.7.1', 'vulnerability' => 'RCE vulnerability']
        ];
        
        foreach ($active_plugins as $plugin_file) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            
            $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;
            if (file_exists($plugin_path)) {
                $plugin_info = get_plugin_data($plugin_path);
                $plugin_slug = dirname($plugin_file);
                
                $plugin_data[] = [
                    'name' => $plugin_info['Name'],
                    'version' => $plugin_info['Version'],
                    'slug' => $plugin_slug,
                    'file' => $plugin_file
                ];
                
                // Check against known vulnerabilities
                if (isset($known_vulnerable[$plugin_slug])) {
                    $vuln = $known_vulnerable[$plugin_slug];
                    if (version_compare($plugin_info['Version'], $vuln['version'], '<=')) {
                        $vulnerabilities_found++;
                    }
                }
            }
        }
        
        return [
            'status' => $vulnerabilities_found > 0 ? 'warning' : 'good',
            'active_plugins' => count($plugin_data),
            'vulnerabilities_found' => $vulnerabilities_found,
            'message' => $vulnerabilities_found > 0 ? 
                "Found $vulnerabilities_found potential security issues" : 
                'No known vulnerabilities found in active plugins'
        ];
    }
    
    private static function check_file_permissions() {
        $critical_files = [
            'wp-config.php' => ['recommended' => '644', 'secure' => '600'],
            '.htaccess' => ['recommended' => '644', 'secure' => '644'],
            'index.php' => ['recommended' => '644', 'secure' => '644']
        ];
        
        $critical_dirs = [
            'wp-content' => ['recommended' => '755', 'secure' => '755'],
            'wp-content/uploads' => ['recommended' => '755', 'secure' => '755'],
            'wp-admin' => ['recommended' => '755', 'secure' => '755']
        ];
        
        $issues = [];
        
        // Check file permissions
        foreach ($critical_files as $file => $perms) {
            $file_path = ABSPATH . $file;
            if (file_exists($file_path)) {
                $current_perms = substr(sprintf('%o', fileperms($file_path)), -3);
                if ($current_perms !== $perms['secure'] && $current_perms !== $perms['recommended']) {
                    $issues[] = "$file has permissions $current_perms (recommended: {$perms['recommended']})";
                }
            }
        }
        
        // Check directory permissions
        foreach ($critical_dirs as $dir => $perms) {
            $dir_path = ABSPATH . $dir;
            if (is_dir($dir_path)) {
                $current_perms = substr(sprintf('%o', fileperms($dir_path)), -3);
                if ($current_perms !== $perms['secure'] && $current_perms !== $perms['recommended']) {
                    $issues[] = "$dir has permissions $current_perms (recommended: {$perms['recommended']})";
                }
            }
        }
        
        return [
            'status' => empty($issues) ? 'good' : 'warning',
            'issues_found' => count($issues),
            'issues' => $issues,
            'message' => empty($issues) ? 'File permissions look good' : 'Some file permissions need attention'
        ];
    }
    
    private static function check_database_connection() {
        global $wpdb;
        
        try {
            $result = $wpdb->get_var("SELECT 1");
            
            if ($result === '1') {
                return [
                    'status' => 'good',
                    'message' => 'Database connection is working',
                    'server_info' => $wpdb->db_server_info()
                ];
            } else {
                return [
                    'status' => 'error',
                    'message' => 'Database query failed'
                ];
            }
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Database connection error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    private static function check_memory_usage() {
        $memory_limit = ini_get('memory_limit');
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        
        $memory_limit_bytes = self::convert_to_bytes($memory_limit);
        $usage_percentage = ($memory_usage / $memory_limit_bytes) * 100;
        $peak_percentage = ($memory_peak / $memory_limit_bytes) * 100;
        
        if ($peak_percentage > 90) {
            $status = 'error';
            $message = 'Memory usage is critically high';
        } elseif ($peak_percentage > 75) {
            $status = 'warning';
            $message = 'Memory usage is high';
        } else {
            $status = 'good';
            $message = 'Memory usage is normal';
        }
        
        return [
            'status' => $status,
            'memory_limit' => $memory_limit,
            'current_usage' => RP_Care_Utils::format_bytes($memory_usage),
            'peak_usage' => RP_Care_Utils::format_bytes($memory_peak),
            'usage_percentage' => round($usage_percentage, 2),
            'peak_percentage' => round($peak_percentage, 2),
            'message' => $message
        ];
    }
    
    private static function check_disk_space() {
        $disk_free = disk_free_space(ABSPATH);
        $disk_total = disk_total_space(ABSPATH);
        
        if ($disk_free === false || $disk_total === false) {
            return [
                'status' => 'error',
                'message' => 'Could not determine disk space'
            ];
        }
        
        $usage_percentage = (($disk_total - $disk_free) / $disk_total) * 100;
        
        if ($usage_percentage > 95) {
            $status = 'error';
            $message = 'Disk space is critically low';
        } elseif ($usage_percentage > 85) {
            $status = 'warning';
            $message = 'Disk space is running low';
        } else {
            $status = 'good';
            $message = 'Disk space is adequate';
        }
        
        return [
            'status' => $status,
            'free_space' => RP_Care_Utils::format_bytes($disk_free),
            'total_space' => RP_Care_Utils::format_bytes($disk_total),
            'usage_percentage' => round($usage_percentage, 2),
            'message' => $message
        ];
    }
    
    private static function check_cron_status() {
        $cron_disabled = defined('DISABLE_WP_CRON') && constant('DISABLE_WP_CRON');
        
        if ($cron_disabled) {
            return [
                'status' => 'info',
                'message' => 'WP-Cron is disabled (using system cron recommended)',
                'wp_cron_disabled' => true
            ];
        }
        
        // Check if cron is running by looking at scheduled events
        $cron_array = _get_cron_array();
        $next_event = wp_next_scheduled('rpcare_daily_check') ?: wp_next_scheduled('wp_scheduled_delete');
        
        if (empty($cron_array) || !$next_event) {
            return [
                'status' => 'warning',
                'message' => 'WP-Cron might not be working properly',
                'scheduled_events' => count($cron_array)
            ];
        }
        
        return [
            'status' => 'good',
            'message' => 'WP-Cron is working properly',
            'scheduled_events' => count($cron_array),
            'next_event' => date('Y-m-d H:i:s', $next_event)
        ];
    }
    
    private static function check_email_functionality() {
        $test_email = get_option('admin_email');
        
        if (empty($test_email)) {
            return [
                'status' => 'warning',
                'message' => 'No admin email configured'
            ];
        }
        
        // Don't actually send email during health check, just verify configuration
        $phpmailer_configured = class_exists('PHPMailer\\PHPMailer\\PHPMailer');
        $smtp_configured = defined('SMTP_HOST') || get_option('smtp_host');
        
        return [
            'status' => 'info',
            'message' => 'Email configuration check completed',
            'admin_email' => $test_email,
            'phpmailer_available' => $phpmailer_configured,
            'smtp_configured' => (bool) $smtp_configured
        ];
    }
    
    private static function perform_uptime_check() {
        $site_url = get_site_url();
        $start_time = microtime(true);
        
        $response = wp_remote_get($site_url, [
            'timeout' => 30,
            'user-agent' => 'Replanta Care Uptime Monitor'
        ]);
        
        $response_time = (microtime(true) - $start_time) * 1000;
        
        if (is_wp_error($response)) {
            return [
                'status' => 'down',
                'error' => $response->get_error_message(),
                'response_time' => null
            ];
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        
        return [
            'status' => $status_code === 200 ? 'up' : 'down',
            'status_code' => $status_code,
            'response_time' => round($response_time, 2)
        ];
    }
    
    private static function measure_response_time() {
        $measurements = [];
        $total_time = 0;
        
        // Test multiple endpoints
        $endpoints = [
            get_site_url(),
            get_site_url() . '/wp-admin/admin-ajax.php',
            get_site_url() . '/wp-json/wp/v2/'
        ];
        
        foreach ($endpoints as $endpoint) {
            $start_time = microtime(true);
            $response = wp_remote_head($endpoint, ['timeout' => 10]);
            $response_time = (microtime(true) - $start_time) * 1000;
            
            if (!is_wp_error($response)) {
                $measurements[] = $response_time;
                $total_time += $response_time;
            }
        }
        
        $avg_response_time = count($measurements) > 0 ? $total_time / count($measurements) : 0;
        
        return [
            'average_response_time' => round($avg_response_time, 2),
            'measurements' => array_map(function($time) { return round($time, 2); }, $measurements),
            'status' => $avg_response_time < 1000 ? 'good' : ($avg_response_time < 3000 ? 'warning' : 'slow')
        ];
    }
    
    private static function check_error_logs() {
        $error_log_file = ini_get('error_log');
        
        if (!$error_log_file || !file_exists($error_log_file)) {
            return [
                'status' => 'info',
                'message' => 'No error log file found'
            ];
        }
        
        $recent_errors = [];
        
        if (is_readable($error_log_file)) {
            $log_content = file_get_contents($error_log_file);
            $lines = explode("\n", $log_content);
            $recent_lines = array_slice($lines, -100); // Last 100 lines
            
            foreach ($recent_lines as $line) {
                if (strpos($line, '[error]') !== false && strpos($line, date('Y-m-d')) !== false) {
                    $recent_errors[] = $line;
                }
            }
        }
        
        return [
            'status' => count($recent_errors) > 10 ? 'warning' : 'good',
            'recent_errors_count' => count($recent_errors),
            'recent_errors' => array_slice($recent_errors, -5), // Last 5 errors
            'message' => count($recent_errors) > 10 ? 'High number of recent errors' : 'Error log looks normal'
        ];
    }
    
    private static function perform_basic_security_scan() {
        $security_issues = [];
        
        // Check for common security issues
        if (!is_ssl()) {
            $security_issues[] = 'SSL not enabled';
        }
        
        if (get_option('blog_public') == 0) {
            $security_issues[] = 'Site not visible to search engines';
        }
        
        // Check for debug mode in production
        if (defined('WP_DEBUG') && WP_DEBUG && !defined('WP_DEBUG_DISPLAY')) {
            $security_issues[] = 'Debug mode enabled in production';
        }
        
        // Check for default admin username
        $admin_user = get_user_by('login', 'admin');
        if ($admin_user) {
            $security_issues[] = 'Default "admin" username exists';
        }
        
        return [
            'status' => count($security_issues) > 0 ? 'warning' : 'good',
            'issues_found' => count($security_issues),
            'issues' => $security_issues,
            'message' => count($security_issues) > 0 ? 'Security issues found' : 'Basic security scan passed'
        ];
    }
    
    private static function check_backup_status() {
        $last_backup = get_option('rpcare_last_backup', '');
        
        if (empty($last_backup)) {
            return [
                'status' => 'warning',
                'message' => 'No backup found',
                'last_backup' => null
            ];
        }
        
        $backup_time = strtotime($last_backup);
        $days_since_backup = (time() - $backup_time) / DAY_IN_SECONDS;
        
        $plan = RP_Care_Plan::get_current();
        $backup_frequency = RP_Care_Plan::get_backup_frequency($plan);
        
        $max_days = $backup_frequency === 'daily' ? 2 : ($backup_frequency === 'weekly' ? 8 : 32);
        
        if ($days_since_backup > $max_days) {
            $status = 'warning';
            $message = 'Backup is overdue';
        } else {
            $status = 'good';
            $message = 'Backup is up to date';
        }
        
        return [
            'status' => $status,
            'last_backup' => $last_backup,
            'days_since_backup' => round($days_since_backup, 1),
            'backup_frequency' => $backup_frequency,
            'message' => $message
        ];
    }
    
    private static function calculate_health_score($health_checks) {
        $total_score = 0;
        $weight_map = [
            'ssl_status' => 15,
            'wp_version' => 10,
            'plugin_vulnerabilities' => 20,
            'file_permissions' => 15,
            'database_connection' => 20,
            'memory_usage' => 10,
            'disk_space' => 5,
            'cron_status' => 3,
            'email_functionality' => 2
        ];
        
        foreach ($health_checks as $check_name => $check_result) {
            if (!isset($weight_map[$check_name]) || !isset($check_result['status'])) {
                continue;
            }
            
            $weight = $weight_map[$check_name];
            
            switch ($check_result['status']) {
                case 'good':
                    $total_score += $weight;
                    break;
                case 'warning':
                case 'info':
                    $total_score += $weight * 0.5;
                    break;
                case 'error':
                default:
                    // No score added for errors
                    break;
            }
        }
        
        return round($total_score);
    }
    
    private static function log_critical_issues($health_checks) {
        $critical_issues = [];
        
        foreach ($health_checks as $check_name => $check_result) {
            if (isset($check_result['status']) && $check_result['status'] === 'error') {
                $critical_issues[] = $check_name . ': ' . $check_result['message'];
            }
        }
        
        if (!empty($critical_issues)) {
            RP_Care_Utils::send_notification(
                'critical_health_issues',
                'Critical Health Issues Detected',
                implode(', ', $critical_issues),
                $health_checks
            );
        }
    }
    
    private static function store_monitoring_data($monitoring_results) {
        $monitoring_data = get_option('rpcare_monitoring_history', []);
        
        $monitoring_data[] = [
            'timestamp' => time(),
            'data' => $monitoring_results
        ];
        
        // Keep only last 100 monitoring records
        $monitoring_data = array_slice($monitoring_data, -100);
        
        update_option('rpcare_monitoring_history', $monitoring_data);
    }
    
    private static function check_monitoring_alerts($monitoring_results) {
        // Check for downtime
        if ($monitoring_results['uptime_check']['status'] === 'down') {
            RP_Care_Utils::send_notification(
                'site_down',
                'Site Down Alert',
                'Website is not responding',
                $monitoring_results['uptime_check']
            );
        }
        
        // Check for slow response times
        if (isset($monitoring_results['response_time']['status']) && 
            $monitoring_results['response_time']['status'] === 'slow') {
            RP_Care_Utils::send_notification(
                'slow_response',
                'Slow Response Time Alert',
                'Website response time is slow',
                $monitoring_results['response_time']
            );
        }
    }
    
    private static function convert_to_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int) $val;
        
        switch ($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        
        return $val;
    }
}
