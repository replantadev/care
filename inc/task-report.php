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
        
        // Notify hub — include key metrics so PC Operaciones can display them without a round-trip
        $upd_task = $report_data['tasks_summary']['updates'] ?? [];
        RP_Care_Utils::send_notification(
            'monthly_report_generated',
            'Informe mensual generado',
            "Informe mensual generado para $site_url",
            [
                'report_id'          => $report_id,
                'period'             => $report_data['site_info']['report_period'] ?? null,
                'wpo_before_ms'      => $report_data['wpo_perf']['before']['response_ms'] ?? null,
                'wpo_after_ms'       => $report_data['wpo_perf']['after']['response_ms'] ?? null,
                'perf_score'         => $report_data['performance_metrics']['performance_score'] ?? null,
                'updates_ok'         => isset( $upd_task['successful_runs'] ) ? (int) $upd_task['successful_runs'] : null,
                'updates_total'      => isset( $upd_task['total_runs'] ) ? (int) $upd_task['total_runs'] : null,
                'updates_rate'       => isset( $upd_task['success_rate'] ) ? (float) $upd_task['success_rate'] : null,
                'errors_404_unique'  => $report_data['error_404_summary']['total_404s'] ?? null,
                'errors_404_hits'    => $report_data['error_404_summary']['total_hits'] ?? null,
                'recommendations'    => array_map(
                    fn( $r ) => [ 'priority' => $r['priority'] ?? '', 'title' => $r['title'] ?? '' ],
                    array_slice( $report_data['recommendations'] ?? [], 0, 5 )
                ),
            ]
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
            'external_metrics' => self::get_external_metrics(),
            'updates_result' => get_option('rpcare_last_update_result', null),
            'wpo_perf' => get_option('rpcare_wpo_perf', null),
            'cwv_history' => class_exists('RP_Care_Task_CWV') ? RP_Care_Task_CWV::get_history() : [],
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
            AND task_type NOT IN ('notification', 'report_generation')
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
        $psi_mobile = null;
        $psi_desktop = null;
        $cwv_last = class_exists('RP_Care_Task_CWV') ? RP_Care_Task_CWV::get_last() : null;
        if (is_array($cwv_last)) {
            $psi_mobile  = $cwv_last['mobile']['scores']['performance'] ?? null;
            $psi_desktop = $cwv_last['desktop']['scores']['performance'] ?? null;
        }

        // Score real de PageSpeed si existe; si no, estimación local
        if ($psi_mobile !== null) {
            $score = $psi_mobile;
            $score_source = 'PageSpeed Insights (móvil)';
        } else {
            $score = RP_Care_Utils::get_performance_score();
            $score_source = 'estimación local';
        }

        return [
            'performance_score' => $score,
            'score_source' => $score_source,
            'psi_mobile' => $psi_mobile,
            'psi_desktop' => $psi_desktop,
            'cwv_measured_at' => is_array($cwv_last) ? ($cwv_last['measured_at'] ?? null) : null,
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

    private static function get_external_metrics() {
        if (!class_exists('RP_Care_Metrics')) return null;
        $all = RP_Care_Metrics::all(30);
        if (is_wp_error($all)) {
            return ['error' => $all->get_error_message()];
        }
        return $all;
    }

    private static function generate_recommendations() {
        $recommendations = [];
        
        // SSL recommendation
        if (!is_ssl()) {
            $recommendations[] = [
                'type' => 'security',
                'priority' => 'high',
                'title' => 'Activar certificado SSL',
                'description' => 'Tu sitio no usa HTTPS. Esto afecta al SEO y a la confianza de los usuarios.'
            ];
        }

        // Caching recommendation
        if (empty(RP_Care_Utils::detect_caching_plugins())) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'title' => 'Instalar plugin de caché',
                'description' => 'Un plugin de caché puede mejorar significativamente la velocidad de carga del sitio.'
            ];
        }

        // SEO plugin recommendation
        if (RP_Care_Utils::detect_seo_plugin() === 'None') {
            $recommendations[] = [
                'type' => 'seo',
                'priority' => 'medium',
                'title' => 'Instalar plugin SEO',
                'description' => 'Un plugin SEO ayuda a optimizar el contenido para los buscadores.'
            ];
        }

        // WordPress version recommendation
        if (!self::is_wp_version_current()) {
            $recommendations[] = [
                'type' => 'security',
                'priority' => 'high',
                'title' => 'Actualizar WordPress',
                'description' => 'Tu versión de WordPress está desactualizada. Las actualizaciones incluyen parches de seguridad.'
            ];
        }

        // Performance score recommendation
        $cwv_last = class_exists('RP_Care_Task_CWV') ? RP_Care_Task_CWV::get_last() : null;
        $performance_score = (is_array($cwv_last) && isset($cwv_last['mobile']['scores']['performance']))
            ? $cwv_last['mobile']['scores']['performance']
            : RP_Care_Utils::get_performance_score();
        if ($performance_score < 70) {
            $recommendations[] = [
                'type' => 'performance',
                'priority' => 'medium',
                'title' => 'Mejorar el rendimiento del sitio',
                'description' => 'La puntuación de rendimiento está por debajo del nivel óptimo. Considera optimizar imágenes y activar la caché.'
            ];
        }
        
        return $recommendations;
    }
    
    private static function generate_html_report($data, $plan) {
        $branding_logo  = get_option('rpcare_branding_logo', '');
        $branding_color = get_option('rpcare_branding_color', '#2c5530');
        // Use inline HTML badges instead of emojis — email clients render them reliably.
        $icon_ok   = '<span style="display:inline-block;background:#1a7e3a;color:#fff;border-radius:3px;padding:1px 7px;font-size:12px;font-weight:700;font-family:Arial,sans-serif;">OK</span>';
        $icon_warn = '<span style="display:inline-block;background:#996800;color:#fff;border-radius:3px;padding:1px 7px;font-size:12px;font-weight:700;font-family:Arial,sans-serif;">!</span>';
        $icon_err  = '<span style="display:inline-block;background:#d63638;color:#fff;border-radius:3px;padding:1px 7px;font-size:12px;font-weight:700;font-family:Arial,sans-serif;">ERR</span>';
        
        // Helper: metric color class based on value and thresholds
        $score_color = static function(int $v): string {
            return $v >= 90 ? '#1a7e3a' : ($v >= 50 ? '#996800' : '#d63638');
        };
        $bool_color = static function(bool $v): string {
            return $v ? '#1a7e3a' : '#d63638';
        };
        $bool_icon = static function(bool $v) use ($icon_ok, $icon_err, $icon_warn): string {
            return $v ? $icon_ok : $icon_warn;
        };

        // Inline style tokens
        $c   = esc_attr($branding_color);   // primary green
        $c_l = '#e8f3e9';                   // light green tint
        $td  = 'font-family:Arial,Helvetica,sans-serif;';

        ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Informe de Mantenimiento &mdash; <?php echo esc_html($data['site_info']['name']); ?></title>
</head>
<body style="margin:0;padding:0;background:#f4f6f4;<?php echo $td; ?>">

<!-- Wrapper -->
<table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f4;">
<tr><td align="center" style="padding:24px 16px;">

<!-- Card -->
<table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:6px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.08);">

  <!-- ── Header ────────────────────────────────────────────── -->
  <tr>
    <td style="background:<?php echo $c; ?>;padding:28px 32px 24px;text-align:center;">
      <?php if ($branding_logo): ?>
      <img src="<?php echo esc_url($branding_logo); ?>" alt="Logo" style="max-height:48px;margin-bottom:12px;display:block;margin-left:auto;margin-right:auto;">
      <?php else: ?>
      <p style="margin:0 0 6px;color:rgba(255,255,255,0.7);font-size:12px;letter-spacing:2px;text-transform:uppercase;<?php echo $td; ?>">Replanta Care</p>
      <?php endif; ?>
      <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;line-height:1.3;<?php echo $td; ?>">Informe de Mantenimiento</h1>
      <p style="margin:8px 0 0;color:rgba(255,255,255,0.8);font-size:13px;<?php echo $td; ?>"><?php echo esc_html($data['site_info']['report_period']); ?></p>
    </td>
  </tr>

  <!-- ── Site strip ────────────────────────────────────────── -->
  <tr>
    <td style="background:<?php echo $c_l; ?>;padding:12px 32px;border-bottom:1px solid #d4e8d7;">
      <table width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          <td style="<?php echo $td; ?>font-size:14px;font-weight:700;color:#1a2e1c;"><?php echo esc_html($data['site_info']['name']); ?></td>
          <td align="right" style="<?php echo $td; ?>font-size:12px;color:#4a6b4d;">
            Plan <strong><?php echo esc_html(ucfirst($data['site_info']['plan'])); ?></strong>
            &nbsp;&middot;&nbsp; WP <?php echo esc_html($data['site_info']['wp_version']); ?>
            &nbsp;&middot;&nbsp; PHP <?php echo esc_html($data['site_info']['php_version']); ?>
          </td>
        </tr>
        <tr>
          <td colspan="2" style="<?php echo $td; ?>font-size:12px;color:#4a6b4d;padding-top:2px;">
            <a href="<?php echo esc_url($data['site_info']['url']); ?>" style="color:<?php echo $c; ?>;text-decoration:none;"><?php echo esc_html($data['site_info']['url']); ?></a>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <!-- ── Body padding start ────────────────────────────────── -->
  <tr><td style="padding:24px 32px 0;">

  <!-- ── Security status row ───────────────────────────────── -->
  <?php
  $sec = $data['security_status'] ?? [];
  $ssl_ok    = !empty($sec['ssl_enabled']);
  $wp_cur    = !empty($sec['wp_version_current']);
  $adm_ok    = !empty($sec['admin_user_secure']);
  $sec_score = (int) ($data['performance_metrics']['security_score'] ?? ($sec['score'] ?? 0));
  ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Seguridad</p>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
    <tr>
      <?php foreach ([
          ['SSL',           $ssl_ok],
          ['WP actualizado',$wp_cur],
          ['Usuario admin', $adm_ok],
      ] as [$lbl, $val]): ?>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;width:33%;">
        <div style="<?php echo $td; ?>font-size:18px;font-weight:700;color:<?php echo $bool_color($val); ?>;"><?php echo $val ? '&#10003;' : '&#10007;'; ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;margin-top:2px;"><?php echo esc_html($lbl); ?></div>
      </td>
      <td width="4"></td>
      <?php endforeach; ?>
    </tr>
  </table>

  <!-- ── Performance ───────────────────────────────────────── -->
  <?php
  $pm  = $data['performance_metrics'] ?? [];
  $ps  = (int) ($pm['performance_score'] ?? 0);
  $psi_d = isset($pm['psi_desktop']) && $pm['psi_desktop'] !== null ? (int) $pm['psi_desktop'] : null;
  $cache = !empty($pm['caching_enabled']);
  $dbsz  = esc_html($pm['database_size'] ?? '—');
  ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Rendimiento</p>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:20px;">
    <tr>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;">
        <div style="<?php echo $td; ?>font-size:26px;font-weight:700;color:<?php echo $score_color($ps); ?>;"><?php echo esc_html($ps); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;margin-top:2px;">Score (<?php echo esc_html($pm['score_source'] ?? 'local'); ?>)</div>
      </td>
      <td width="4"></td>
      <?php if ($psi_d !== null): ?>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;">
        <div style="<?php echo $td; ?>font-size:26px;font-weight:700;color:<?php echo $score_color($psi_d); ?>;"><?php echo esc_html($psi_d); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;margin-top:2px;">PageSpeed escritorio</div>
      </td>
      <td width="4"></td>
      <?php endif; ?>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;">
        <div style="<?php echo $td; ?>font-size:18px;font-weight:700;color:<?php echo $bool_color($cache); ?>;"><?php echo $cache ? '&#10003;' : '&#10007;'; ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;margin-top:2px;">Cach&eacute;</div>
      </td>
      <td width="4"></td>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;">
        <div style="<?php echo $td; ?>font-size:15px;font-weight:700;color:#2c5530;"><?php echo $dbsz; ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;margin-top:2px;">Base de datos</div>
      </td>
    </tr>
  </table>

  <!-- ── WPO Before / After ────────────────────────────────── -->
  <?php
  $wpo = $data['wpo_perf'] ?? null;
  if (is_array($wpo) && (!empty($wpo['before']['response_ms']) || $wpo['before']['psi_mobile'] !== null)):
      $b_ms  = $wpo['before']['response_ms']  ?? null;
      $a_ms  = $wpo['after']['response_ms']   ?? null;
      $b_psi = $wpo['before']['psi_mobile']   ?? null;
      $a_psi = $wpo['after']['psi_mobile']    ?? null;
  ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Optimizaci&oacute;n WPO</p>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f0f7f1;border:1px solid #c8e1cc;border-radius:4px;margin-bottom:20px;">
    <tr>
      <td align="center" style="padding:14px 8px;">
        <?php if ($b_ms !== null && $a_ms !== null):
            $delta_ms = $b_ms > 0 ? round((($b_ms - $a_ms) / $b_ms) * 100) : 0;
        ?>
        <table cellpadding="0" cellspacing="0" border="0"><tr>
          <td align="center" style="padding:0 12px;">
            <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:#996800;"><?php echo esc_html($b_ms); ?> ms</div>
            <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">Respuesta antes</div>
          </td>
          <td style="<?php echo $td; ?>font-size:20px;color:<?php echo $c; ?>;padding:0 8px;">&rarr;</td>
          <td align="center" style="padding:0 12px;">
            <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:<?php echo $a_ms <= $b_ms ? '#1a7e3a' : '#996800'; ?>;"><?php echo esc_html($a_ms); ?> ms</div>
            <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">Respuesta despu&eacute;s</div>
            <?php if ($b_ms > 0): ?>
            <div style="<?php echo $td; ?>font-size:11px;font-weight:700;color:<?php echo $delta_ms >= 0 ? '#1a7e3a' : '#996800'; ?>;">
              <?php echo $delta_ms >= 0 ? '-' . $delta_ms . '% m&aacute;s r&aacute;pido' : '+' . abs($delta_ms) . '%'; ?>
            </div>
            <?php endif; ?>
          </td>
        </tr></table>
        <?php endif; ?>
        <?php if ($b_psi !== null && $a_psi !== null): ?>
        <table cellpadding="0" cellspacing="0" border="0" style="margin-top:8px;"><tr>
          <td align="center" style="padding:0 12px;">
            <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:#996800;"><?php echo esc_html($b_psi); ?></div>
            <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">PageSpeed antes</div>
          </td>
          <td style="<?php echo $td; ?>font-size:20px;color:<?php echo $c; ?>;padding:0 8px;">&rarr;</td>
          <td align="center" style="padding:0 12px;">
            <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:<?php echo $a_psi >= $b_psi ? '#1a7e3a' : '#996800'; ?>;"><?php echo esc_html($a_psi); ?></div>
            <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">PageSpeed despu&eacute;s</div>
          </td>
        </tr></table>
        <?php elseif (!empty($wpo['psi_pending'])): ?>
        <p style="<?php echo $td; ?>font-size:12px;color:#4a6b4d;margin:0;">Medici&oacute;n PageSpeed posterior en curso&hellip;</p>
        <?php endif; ?>
        <p style="<?php echo $td; ?>font-size:11px;color:#88a88a;margin:6px 0 0;">Medido el <?php echo esc_html($wpo['measured_at'] ?? ''); ?></p>
      </td>
    </tr>
  </table>
  <?php endif; ?>

  <!-- ── CWV history ───────────────────────────────────────── -->
  <?php
  $history = $data['cwv_history'] ?? [];
  if (is_array($history) && count($history) >= 2):
      $history_slice = array_slice($history, -8);
  ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Core Web Vitals — Evoluci&oacute;n m&oacute;vil</p>
  <table width="100%" cellpadding="0" cellspacing="4" border="0" style="margin-bottom:20px;">
    <?php foreach ($history_slice as $entry):
        $sc = isset($entry['mobile']) ? (int) $entry['mobile'] : null;
        if ($sc === null) continue;
        $bg = $sc >= 90 ? '#1a7e3a' : ($sc >= 50 ? '#996800' : '#d63638');
        $bar_w = max(4, min(100, $sc));
    ?>
    <tr>
      <td width="72" style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;white-space:nowrap;"><?php echo esc_html($entry['date'] ?? ''); ?></td>
      <td style="padding:0 6px;">
        <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#e8e8e8;border-radius:3px;height:10px;">
          <tr><td width="<?php echo esc_attr($bar_w); ?>%" style="background:<?php echo $bg; ?>;border-radius:3px;height:10px;font-size:1px;">&nbsp;</td><td></td></tr>
        </table>
      </td>
      <td width="28" align="right" style="<?php echo $td; ?>font-size:12px;font-weight:700;color:<?php echo $bg; ?>;"><?php echo esc_html($sc); ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <!-- ── Tasks summary ─────────────────────────────────────── -->
  <?php if (!empty($data['tasks_summary'])): ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Resumen de Tareas</p>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:20px;">
    <tr style="background:<?php echo $c; ?>;">
      <th style="<?php echo $td; ?>color:#fff;font-size:12px;font-weight:600;padding:8px 10px;text-align:left;">Tarea</th>
      <th style="<?php echo $td; ?>color:#fff;font-size:12px;font-weight:600;padding:8px 10px;text-align:center;width:60px;">Total</th>
      <th style="<?php echo $td; ?>color:#fff;font-size:12px;font-weight:600;padding:8px 10px;text-align:center;width:60px;">&Eacute;xito</th>
      <th style="<?php echo $td; ?>color:#fff;font-size:12px;font-weight:600;padding:8px 10px;text-align:center;width:60px;">Errores</th>
      <th style="<?php echo $td; ?>color:#fff;font-size:12px;font-weight:600;padding:8px 10px;text-align:center;width:70px;">Tasa</th>
    </tr>
    <?php $row_i = 0; foreach ($data['tasks_summary'] as $task_type => $summary):
        $rate = (int) ($summary['success_rate'] ?? 0);
        $rate_c = $rate >= 90 ? '#1a7e3a' : ($rate >= 70 ? '#996800' : '#d63638');
        $bg_row = $row_i % 2 === 0 ? '#ffffff' : '#f7f9f7';
        $row_i++;
    ?>
    <tr style="background:<?php echo $bg_row; ?>;">
      <td style="<?php echo $td; ?>font-size:12px;color:#1a2e1c;padding:7px 10px;border-bottom:1px solid #e8eee8;"><?php echo esc_html(self::task_label($task_type)); ?></td>
      <td align="center" style="<?php echo $td; ?>font-size:12px;color:#4a6b4d;padding:7px 10px;border-bottom:1px solid #e8eee8;"><?php echo esc_html($summary['total_runs']); ?></td>
      <td align="center" style="<?php echo $td; ?>font-size:12px;color:#1a7e3a;font-weight:600;padding:7px 10px;border-bottom:1px solid #e8eee8;"><?php echo esc_html($summary['successful_runs']); ?></td>
      <td align="center" style="<?php echo $td; ?>font-size:12px;color:#d63638;padding:7px 10px;border-bottom:1px solid #e8eee8;"><?php echo esc_html($summary['failed_runs']); ?></td>
      <td align="center" style="padding:7px 10px;border-bottom:1px solid #e8eee8;">
        <span style="<?php echo $td; ?>font-size:11px;font-weight:700;color:<?php echo $rate_c; ?>;background:<?php echo $rate >= 90 ? '#e8f4ec' : ($rate >= 70 ? '#fef8e6' : '#fce8e8'); ?>;border-radius:2px;padding:1px 5px;"><?php echo esc_html($rate); ?>%</span>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>

  <!-- ── Updates done ──────────────────────────────────────── -->
  <?php
  $upd = $data['updates_result'] ?? null;
  if (is_array($upd) && isset($upd['actualizados'])):
  ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Actualizaciones Realizadas</p>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;margin-bottom:20px;">
    <tr><td style="padding:12px 14px;">
      <p style="margin:0 0 6px;<?php echo $td; ?>font-size:13px;color:#1a2e1c;">
        <strong><?php echo (int) $upd['actualizados']; ?></strong> elemento(s) actualizado(s)
        <?php if (!empty($upd['backup_previo'])): ?>
        &nbsp;<span style="background:#e8f4ec;color:#1a7e3a;border-radius:2px;padding:1px 6px;font-size:11px;font-weight:700;">con backup previo</span>
        <?php endif; ?>
      </p>
      <?php if (!empty($upd['lista'])): ?>
      <ul style="margin:4px 0 0 16px;padding:0;">
        <?php foreach (explode(' | ', $upd['lista']) as $item): ?>
        <li style="<?php echo $td; ?>font-size:12px;color:#4a6b4d;margin-bottom:2px;"><?php echo esc_html(trim($item)); ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php if (!empty($upd['errores'])): ?>
      <p style="<?php echo $td; ?>font-size:12px;color:#d63638;margin:6px 0 0;">Errores: <?php echo esc_html($upd['errores']); ?></p>
      <?php endif; ?>
    </td></tr>
  </table>
  <?php endif; ?>

  <!-- ── 404 errors ────────────────────────────────────────── -->
  <?php if (!empty($data['error_404_summary']['total_404s'])): ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Errores 404</p>
  <table width="100%" cellpadding="0" cellspacing="4" border="0" style="margin-bottom:8px;">
    <tr>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:8px;width:33%;">
        <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:#996800;"><?php echo esc_html($data['error_404_summary']['total_404s']); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">URLs 404</div>
      </td>
      <td width="4"></td>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:8px;width:33%;">
        <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:#4a6b4d;"><?php echo esc_html($data['error_404_summary']['total_hits']); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">Total hits</div>
      </td>
      <td width="4"></td>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:8px;width:33%;">
        <div style="<?php echo $td; ?>font-size:22px;font-weight:700;color:#1a7e3a;"><?php echo esc_html($data['error_404_summary']['resolved_404s']); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">Resueltos</div>
      </td>
    </tr>
  </table>
  <?php if (!empty($data['error_404_summary']['top_404s'])): ?>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin-bottom:20px;">
    <tr style="background:<?php echo $c; ?>;">
      <th style="<?php echo $td; ?>color:#fff;font-size:11px;padding:7px 8px;text-align:left;">URL</th>
      <th style="<?php echo $td; ?>color:#fff;font-size:11px;padding:7px 8px;width:40px;">Hits</th>
      <th style="<?php echo $td; ?>color:#fff;font-size:11px;padding:7px 8px;width:70px;">Estado</th>
    </tr>
    <?php $ri = 0; foreach ($data['error_404_summary']['top_404s'] as $r):
        $bg_r = $ri++ % 2 === 0 ? '#ffffff' : '#f7f9f7'; ?>
    <tr style="background:<?php echo $bg_r; ?>;">
      <td style="<?php echo $td; ?>font-size:11px;color:#1a2e1c;padding:6px 8px;border-bottom:1px solid #e8eee8;word-break:break-all;"><?php echo esc_html($r->url); ?></td>
      <td align="center" style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;padding:6px 8px;border-bottom:1px solid #e8eee8;"><?php echo esc_html($r->hits); ?></td>
      <td align="center" style="<?php echo $td; ?>font-size:11px;padding:6px 8px;border-bottom:1px solid #e8eee8;">
        <?php if ($r->status === 'resolved'): ?>
        <span style="background:#e8f4ec;color:#1a7e3a;border-radius:2px;padding:1px 5px;font-weight:700;">OK</span>
        <?php else: ?>
        <span style="background:#fef8e6;color:#996800;border-radius:2px;padding:1px 5px;font-weight:700;">Pendiente</span>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
  <?php endif; ?>

  <!-- ── Traffic / SEO / CDN ───────────────────────────────── -->
  <?php
  $ext = $data['external_metrics'] ?? null;
  $ga  = (is_array($ext) && !empty($ext['ga4']) && empty($ext['ga4']['error'])) ? $ext['ga4'] : null;
  $sc  = (is_array($ext) && !empty($ext['sc'])  && empty($ext['sc']['error']))  ? $ext['sc']  : null;
  $cf  = (is_array($ext) && !empty($ext['cloudflare']) && empty($ext['cloudflare']['error'])) ? $ext['cloudflare'] : null;
  if ($ga || $sc || $cf):
  ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Tr&aacute;fico, SEO y CDN</p>
  <table width="100%" cellpadding="4" cellspacing="4" border="0" style="margin-bottom:20px;">
    <tr>
      <?php if ($ga): ?>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;vertical-align:top;">
        <div style="<?php echo $td; ?>font-size:20px;font-weight:700;color:#1a7e3a;"><?php echo number_format_i18n((int)($ga['sessions'] ?? 0)); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">GA4 Sesiones (30d)</div>
        <div style="<?php echo $td; ?>font-size:10px;color:#88a88a;margin-top:2px;"><?php echo number_format_i18n((int)($ga['users'] ?? 0)); ?> usuarios</div>
      </td>
      <?php endif; ?>
      <?php if ($sc): ?>
      <td width="4"></td>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;vertical-align:top;">
        <div style="<?php echo $td; ?>font-size:20px;font-weight:700;color:#1a7e3a;"><?php echo number_format_i18n((int)($sc['clicks'] ?? 0)); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">Search Console clics</div>
        <div style="<?php echo $td; ?>font-size:10px;color:#88a88a;margin-top:2px;">CTR <?php echo esc_html($sc['ctr'] ?? '—'); ?>% &middot; pos <?php echo esc_html($sc['position'] ?? '—'); ?></div>
      </td>
      <?php endif; ?>
      <?php if ($cf): ?>
      <td width="4"></td>
      <td align="center" style="background:#f7f9f7;border:1px solid #dde8dd;border-radius:4px;padding:10px 6px;vertical-align:top;">
        <div style="<?php echo $td; ?>font-size:20px;font-weight:700;color:#1a7e3a;"><?php echo esc_html($cf['cache_ratio'] !== null ? $cf['cache_ratio'].'%' : '—'); ?></div>
        <div style="<?php echo $td; ?>font-size:11px;color:#4a6b4d;">Cloudflare cache hit</div>
        <div style="<?php echo $td; ?>font-size:10px;color:#88a88a;margin-top:2px;"><?php echo number_format_i18n((int)($cf['threats'] ?? 0)); ?> bloqueos</div>
      </td>
      <?php endif; ?>
    </tr>
  </table>
  <?php endif; ?>

  <!-- ── Recommendations ───────────────────────────────────── -->
  <?php if (!empty($data['recommendations'])): ?>
  <p style="margin:0 0 8px;<?php echo $td; ?>font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#4a6b4d;">Recomendaciones</p>
  <?php foreach ($data['recommendations'] as $rec):
      $prio_colors = ['high' => ['#d63638', '#fce8e8'], 'medium' => ['#996800', '#fef8e6'], 'low' => ['#1a7e3a', '#e8f4ec']];
      [$pc, $pb]  = $prio_colors[$rec['priority']] ?? ['#4a6b4d', '#f7f9f7'];
      $prio_labels = ['high' => 'ALTA', 'medium' => 'MEDIA', 'low' => 'BAJA'];
  ?>
  <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:8px;">
    <tr>
      <td width="4" style="background:<?php echo $pc; ?>;border-radius:2px 0 0 2px;">&nbsp;</td>
      <td style="background:<?php echo $pb; ?>;border-radius:0 2px 2px 0;padding:10px 12px;">
        <p style="margin:0 0 3px;<?php echo $td; ?>font-size:11px;">
          <span style="font-weight:700;color:<?php echo $pc; ?>;"><?php echo esc_html($prio_labels[$rec['priority']] ?? strtoupper($rec['priority'])); ?></span>
          &nbsp;<strong style="color:#1a2e1c;"><?php echo esc_html($rec['title']); ?></strong>
        </p>
        <p style="margin:0;<?php echo $td; ?>font-size:12px;color:#4a6b4d;"><?php echo esc_html($rec['description']); ?></p>
      </td>
    </tr>
  </table>
  <?php endforeach; ?>
  <div style="height:12px;"></div>
  <?php endif; ?>

  </td></tr>
  <!-- ── Body padding end ──────────────────────────────────── -->

  <!-- ── Footer ────────────────────────────────────────────── -->
  <tr>
    <td style="background:#f7f9f7;border-top:1px solid #d4e8d7;padding:16px 32px;text-align:center;">
      <p style="margin:0 0 4px;<?php echo $td; ?>font-size:11px;color:#88a88a;">Informe generado el <?php echo esc_html(current_time('d/m/Y H:i')); ?></p>
      <?php if (get_option('rpcare_branding_footer', true)): ?>
      <p style="margin:0;<?php echo $td; ?>font-size:11px;color:#88a88a;">
        <a href="https://replanta.net" style="color:<?php echo $c; ?>;text-decoration:none;font-weight:600;">Powered by Replanta Care</a>
      </p>
      <?php endif; ?>
    </td>
  </tr>

</table>
<!-- /Card -->

</td></tr>
</table>
<!-- /Wrapper -->

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
    private static function task_label($type) {
        $labels = [
            'updates' => 'Actualizaciones',
            'update' => 'Actualizaciones',
            'backup' => 'Copias de seguridad',
            'backups' => 'Copias de seguridad',
            'security' => 'Seguridad',
            'wpo' => 'Optimización WPO',
            'seo' => 'SEO',
            '404' => 'Errores 404',
            '404_check' => 'Errores 404',
            'cwv' => 'Core Web Vitals',
            'health' => 'Salud del sitio',
            'health_check' => 'Salud del sitio',
            'database' => 'Base de datos',
            'database_optimization' => 'Base de datos',
            'images' => 'Imágenes',
            'broken_links' => 'Enlaces rotos',
            'uptime' => 'Disponibilidad',
            'report_generation' => 'Informes',
            'notification' => 'Notificaciones',
        ];
        return $labels[$type] ?? ucfirst(str_replace('_', ' ', $type));
    }

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
        
        return $size ? $size . ' MB' : 'Desconocido';
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
