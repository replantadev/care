<?php
/**
 * Security and authentication class
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Security {
    
    private static $token_expiry = 7 * DAY_IN_SECONDS; // 7 days
    
    public static function generate_token($site_data = []) {
        $payload = [
            'site_url' => get_site_url(),
            'domain' => parse_url(get_site_url(), PHP_URL_HOST),
            'issued_at' => time(),
            'expires_at' => time() + self::$token_expiry,
            'plan' => RP_Care_Plan::get_current(),
            'version' => RPCARE_VERSION
        ];
        
        if (!empty($site_data)) {
            $payload = array_merge($payload, $site_data);
        }
        
        $header = wp_json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload_json = wp_json_encode($payload);
        
        $base64_header = self::base64url_encode($header);
        $base64_payload = self::base64url_encode($payload_json);
        
        $signature = self::sign($base64_header . '.' . $base64_payload);
        
        return $base64_header . '.' . $base64_payload . '.' . $signature;
    }
    
    public static function validate_token($token) {
        if (empty($token)) {
            return false;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }
        
        [$header, $payload, $signature] = $parts;
        
        // Verify signature
        $expected_signature = self::sign($header . '.' . $payload);
        if (!hash_equals($signature, $expected_signature)) {
            return false;
        }
        
        // Decode payload
        $payload_data = json_decode(self::base64url_decode($payload), true);
        if (!$payload_data) {
            return false;
        }
        
        // Check expiration
        if (isset($payload_data['expires_at']) && $payload_data['expires_at'] < time()) {
            return false;
        }
        
        // Verify site URL
        if (isset($payload_data['site_url']) && $payload_data['site_url'] !== get_site_url()) {
            return false;
        }
        
        return $payload_data;
    }
    
    public static function refresh_token() {
        $current_token = get_option('rpcare_token', '');
        $payload_data = self::validate_token($current_token);
        
        if (!$payload_data) {
            return false;
        }
        
        // Generate new token with existing data
        $new_token = self::generate_token([
            'plan' => $payload_data['plan'] ?? RP_Care_Plan::get_current(),
            'hub_assigned' => $payload_data['hub_assigned'] ?? false
        ]);
        
        update_option('rpcare_token', $new_token);
        
        RP_Care_Utils::log('security', 'info', 'Token refreshed successfully');
        
        return $new_token;
    }
    
    public static function validate_request($request) {
        $auth_header = $request->get_header('authorization');
        if (!$auth_header) {
            return new WP_Error('no_auth', 'No authorization header', ['status' => 401]);
        }
        
        // Extract token from "Bearer TOKEN" format
        if (strpos($auth_header, 'Bearer ') !== 0) {
            return new WP_Error('invalid_auth_format', 'Invalid authorization format', ['status' => 401]);
        }
        
        $token = substr($auth_header, 7);
        $payload = self::validate_token($token);
        
        if (!$payload) {
            return new WP_Error('invalid_token', 'Invalid or expired token', ['status' => 401]);
        }
        
        // Check IP whitelist if configured
        $allowed_ips = get_option('rpcare_allowed_ips', []);
        if (!empty($allowed_ips)) {
            $client_ip = RP_Care_Utils::get_client_ip();
            if (!in_array($client_ip, $allowed_ips)) {
                RP_Care_Utils::log('security', 'warning', "Blocked request from unauthorized IP: $client_ip");
                return new WP_Error('ip_not_allowed', 'IP not in whitelist', ['status' => 403]);
            }
        }
        
        // Store validated payload for use in request
        $request->set_param('_rpcare_payload', $payload);
        
        return true;
    }
    
    public static function can_execute_task($task, $payload = null) {
        if (!$payload) {
            return false;
        }
        
        $plan = $payload['plan'] ?? '';
        if (!RP_Care_Plan::is_valid_plan($plan)) {
            return false;
        }
        
        // Define task permissions by plan
        $task_permissions = [
            'updates' => [RP_Care_Plan::PLAN_SEMILLA, RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'backup' => [RP_Care_Plan::PLAN_SEMILLA, RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'wpo_basic' => [RP_Care_Plan::PLAN_SEMILLA, RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'wpo_advanced' => [RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'wpo_premium' => [RP_Care_Plan::PLAN_ECOSISTEMA],
            'seo_basic' => [RP_Care_Plan::PLAN_SEMILLA, RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'seo_advanced' => [RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'monitoring' => [RP_Care_Plan::PLAN_RAIZ, RP_Care_Plan::PLAN_ECOSISTEMA],
            'audit' => [RP_Care_Plan::PLAN_ECOSISTEMA],
            'cdn_config' => [RP_Care_Plan::PLAN_ECOSISTEMA]
        ];
        
        return in_array($plan, $task_permissions[$task] ?? []);
    }
    
    public static function set_activation_data($token, $plan, $hub_url = '') {
        $payload = self::validate_token($token);
        if (!$payload) {
            return new WP_Error('invalid_token', 'Token de activación inválido');
        }
        
        if (!RP_Care_Plan::is_valid_plan($plan)) {
            return new WP_Error('invalid_plan', 'Plan no válido');
        }
        
        // Store activation data
        update_option('rpcare_token', $token);
        update_option('rpcare_plan', $plan);
        update_option('rpcare_activated', true);
        
        if (!empty($hub_url)) {
            update_option('rpcare_hub_url', $hub_url);
        }
        
        // Set up initial schedules
        $scheduler = new RP_Care_Scheduler($plan);
        $scheduler->ensure();
        
        RP_Care_Utils::log('activation', 'success', "Plugin activated with plan: $plan");
        
        return true;
    }
    
    public static function deactivate() {
        // Clear sensitive data but keep logs for debugging
        update_option('rpcare_activated', false);
        delete_option('rpcare_token');
        
        // Clear scheduled tasks
        $scheduler = new RP_Care_Scheduler('');
        $scheduler->clear_all();
        
        RP_Care_Utils::log('activation', 'info', 'Plugin deactivated');
        
        return true;
    }
    
    public static function generate_api_key() {
        return wp_generate_password(32, false);
    }
    
    public static function hash_api_key($key) {
        return wp_hash($key);
    }
    
    public static function verify_nonce($nonce, $action = 'rpcare_admin') {
        return wp_verify_nonce($nonce, $action);
    }
    
    public static function sanitize_settings($settings) {
        $sanitized = [];
        
        $allowed_settings = [
            'rpcare_plan' => 'sanitize_text_field',
            'rpcare_token' => 'sanitize_text_field',
            'rpcare_hub_url' => 'esc_url_raw',
            'rpcare_allowed_ips' => 'array',
            'rpcare_email_reports' => 'sanitize_email',
            'rpcare_report_frequency' => 'sanitize_text_field',
            'rpcare_branding_logo' => 'esc_url_raw',
            'rpcare_branding_color' => 'sanitize_hex_color',
            'rpcare_exclude_plugins' => 'array',
            'rpcare_exclude_themes' => 'array'
        ];
        
        foreach ($settings as $key => $value) {
            if (isset($allowed_settings[$key])) {
                $sanitizer = $allowed_settings[$key];
                
                if ($sanitizer === 'array') {
                    $sanitized[$key] = is_array($value) ? array_map('sanitize_text_field', $value) : [];
                } else {
                    $sanitized[$key] = call_user_func($sanitizer, $value);
                }
            }
        }
        
        return $sanitized;
    }
    
    private static function base64url_encode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
    
    private static function base64url_decode($data) {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
    
    private static function sign($data) {
        $secret = self::get_secret_key();
        return self::base64url_encode(hash_hmac('sha256', $data, $secret, true));
    }
    
    private static function get_secret_key() {
        $secret = get_option('rpcare_secret_key');
        
        if (!$secret) {
            $secret = wp_generate_password(64, true, true);
            update_option('rpcare_secret_key', $secret);
        }
        
        return $secret;
    }
    
    public static function log_security_event($event, $details = []) {
        $ip = RP_Care_Utils::get_client_ip();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        $log_data = [
            'event' => $event,
            'ip' => $ip,
            'user_agent' => $user_agent,
            'timestamp' => time(),
            'details' => $details
        ];
        
        RP_Care_Utils::log('security', 'info', "Security event: $event", $log_data);
    }
}
