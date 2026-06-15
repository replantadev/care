<?php
/**
 * Client Portal — Panel de cliente de Replanta Care.
 *
 * Registra un menú top-level "Replanta Care" con dos submenús:
 *   · Mi Panel    — dashboard de datos orientado al cliente
 *   · Configuración — la página de ajustes existente
 *
 * Los datos se leen de opciones locales (rpcare_*) y del cache
 * empujado por Hub (rpcare_portal_cache). Sin llamadas externas
 * en el render — carga instantánea.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Client_Portal {

    private static $instance = null;

    // SVG hoja — base64 para icono del menú admin (monocromático, color adaptado por WP)
    const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjYTdhYWFkIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+PHBhdGggZD0iTTExIDIwQTcgNyAwIDAgMSA5IDE5QzcgMTcgMyAxNCAzIDloMGE1IDUgMCAwIDEgNS01aDFhNyA3IDAgMCAxIDYgNGwxIDJhMyAzIDAgMCAxIDMgM2gwYTMgMyAwIDAgMS0zIDNoLTFsLTEgM2EzIDMgMCAwIDEtMyAxeiIvPjxwYXRoIGQ9Ik0xMiAxMkw5IDE1Ii8+PC9zdmc+';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menus'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    // -------------------------------------------------------------------------
    // Menú
    // -------------------------------------------------------------------------

    public function register_menus() {
        // Top-level — Mi Panel (portal)
        add_menu_page(
            'Replanta Care',
            'Replanta Care',
            'manage_options',
            'replanta-care-portal',
            [$this, 'render_portal'],
            self::MENU_ICON,
            59
        );

        // Submenu: Mi Panel (mismo que el padre — evita duplicado en el title)
        add_submenu_page(
            'replanta-care-portal',
            'Mi Panel — Replanta Care',
            'Mi Panel',
            'manage_options',
            'replanta-care-portal',
            [$this, 'render_portal']
        );

        // Submenu: Configuración — apunta al slug existente de settings-page.php
        // settings-page.php lo registrará como submenu bajo este padre
        // (nada que registrar aquí — settings-page.php lo hace en prioridad 10)
    }

    // -------------------------------------------------------------------------
    // Assets — CSS inline, sin ficheros extra
    // -------------------------------------------------------------------------

    public function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_replanta-care-portal') {
            return;
        }
        // No external deps — todo inline en render_portal()
    }

    // -------------------------------------------------------------------------
    // Render principal
    // -------------------------------------------------------------------------

    public function render_portal() {
        $d = $this->collect_data();
        ?>
        <div class="rcp-wrap">
        <?php $this->render_portal_css(); ?>

        <?php $this->render_hero($d); ?>
        <?php $this->render_grid($d); ?>
        <?php $this->render_timeline($d); ?>
        <?php $this->render_tech_bar($d); ?>

        <p class="rcp-footer-note">
            Replanta Care v<?php echo esc_html(RPCARE_VERSION); ?>
            <?php if ($d['cache_age_label']): ?>
            · Datos Hub: <?php echo esc_html($d['cache_age_label']); ?>
            <?php endif; ?>
            · <a href="<?php echo esc_url(admin_url('admin.php?page=replanta-care')); ?>">Configuración</a>
        </p>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Secciones HTML
    // -------------------------------------------------------------------------

    private function render_hero($d) {
        $status_class = $d['overall_ok'] ? 'ok' : 'warn';
        $status_text  = $d['overall_ok'] ? 'Todo en orden' : 'Requiere atención';
        ?>
        <div class="rcp-hero">
            <div class="rcp-hero-brand">
                <span class="rcp-plan-badge rcp-plan-<?php echo esc_attr($d['plan']); ?>">
                    <?php echo esc_html($d['plan_name']); ?>
                </span>
                <h1 class="rcp-hero-title">Panel de mantenimiento</h1>
                <p class="rcp-hero-domain"><?php echo esc_html(parse_url(home_url(), PHP_URL_HOST)); ?></p>
            </div>

            <div class="rcp-hero-stats">
                <div class="rcp-hero-stat">
                    <span class="rcp-stat-num"><?php echo intval($d['monthly']['updates_ok'] ?? 0); ?></span>
                    <span class="rcp-stat-label">actualizaciones<br>este mes</span>
                </div>
                <div class="rcp-hero-stat">
                    <span class="rcp-stat-num"><?php echo intval($d['backups_this_month']); ?></span>
                    <span class="rcp-stat-label">backups<br>confirmados</span>
                </div>
                <div class="rcp-hero-stat">
                    <span class="rcp-stat-num rcp-score-num"><?php echo intval($d['health_score']); ?></span>
                    <span class="rcp-stat-label">salud<br>del sitio</span>
                </div>
            </div>

            <div class="rcp-status-pill rcp-status-<?php echo $status_class; ?>">
                <span class="rcp-status-dot"></span>
                <?php echo esc_html($status_text); ?>
            </div>
        </div>
        <?php
    }

    private function render_grid($d) {
        ?>
        <div class="rcp-grid">

            <?php /* ── COL 1: Actualizaciones ── */ ?>
            <div class="rcp-card">
                <h2 class="rcp-card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Actualizaciones
                </h2>

                <?php if (!empty($d['update_history'])): ?>
                <ul class="rcp-update-list">
                    <?php foreach (array_slice($d['update_history'], 0, 6) as $entry):
                        $ok   = ($entry['event_type'] ?? '') === 'update_completed';
                        $risk = $d['risk_map'][$entry['data']['plugin_slug'] ?? ''] ?? null;
                        $rl   = $this->risk_level($risk);
                    ?>
                    <li class="rcp-update-item rcp-update-<?php echo $ok ? 'ok' : 'fail'; ?>">
                        <span class="rcp-update-icon"><?php echo $ok ? '✓' : '✕'; ?></span>
                        <span class="rcp-update-name"><?php echo esc_html($entry['data']['plugin_name'] ?? ($entry['data']['type'] ?? '—')); ?></span>
                        <?php if ($risk !== null): ?>
                        <span class="rcp-risk-badge rcp-risk-<?php echo $rl; ?>" title="Riesgo <?php echo round($risk, 2); ?>">
                            <?php echo $rl === 'low' ? '●' : ($rl === 'med' ? '●' : '●'); ?>
                        </span>
                        <?php endif; ?>
                        <span class="rcp-update-time"><?php echo esc_html($this->human_time($entry['timestamp'] ?? '')); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <div class="rcp-empty">
                    <p>Historial disponible tras el primer ciclo Hub</p>
                    <?php if (!$d['hub_connected']): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=replanta-care')); ?>" class="rcp-link-small">Verificar conexión →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($d['pending_updates'])): ?>
                <div class="rcp-pending-bar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <?php echo intval($d['pending_updates']); ?> pendientes — se aplicarán automáticamente
                </div>
                <?php endif; ?>
            </div>

            <?php /* ── COL 2: Backups B2 ── */ ?>
            <div class="rcp-card">
                <h2 class="rcp-card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Backups B2
                </h2>

                <?php
                $bh = $d['backup_health'];
                $b2 = $d['last_b2_backup'];
                $bh_class = $bh === 'ok' ? 'ok' : ($bh === 'warning' ? 'warn' : 'fail');
                ?>
                <div class="rcp-backup-status rcp-backup-<?php echo $bh_class; ?>">
                    <span class="rcp-backup-dot"></span>
                    <?php
                    if ($b2 && !empty($b2['timestamp'])) {
                        echo 'Último: ' . esc_html($this->human_time($b2['timestamp']));
                    } elseif ($d['last_backup']) {
                        echo 'Local: ' . esc_html($this->human_time($d['last_backup']));
                    } else {
                        echo 'Sin backups registrados';
                    }
                    ?>
                </div>

                <?php if ($b2 && !empty($b2['files'])): ?>
                <ul class="rcp-backup-files">
                    <?php foreach ((array)$b2['files'] as $f): ?>
                    <li><?php echo esc_html(basename($f)); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <?php if ($b2 && !empty($b2['prefix'])): ?>
                <p class="rcp-backup-prefix">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
                    <?php echo esc_html($b2['prefix']); ?>
                </p>
                <?php endif; ?>

                <?php if ($d['ssl_days'] !== null): ?>
                <div class="rcp-ssl-row rcp-ssl-<?php echo $d['ssl_days'] > 30 ? 'ok' : ($d['ssl_days'] > 14 ? 'warn' : 'fail'); ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    SSL: <?php echo intval($d['ssl_days']); ?> días restantes
                </div>
                <?php endif; ?>
            </div>

            <?php /* ── COL 3: Salud del sitio ── */ ?>
            <div class="rcp-card">
                <h2 class="rcp-card-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    Salud del sitio
                </h2>

                <?php
                $score = intval($d['health_score']);
                $dash_fill = round($score * 2.199, 1); // 2.199 ≈ 219.9/100 (circ r=35)
                $dash_empty = round(219.9 - $dash_fill, 1);
                $score_color = $score >= 80 ? '#22C55E' : ($score >= 60 ? '#F59E0B' : '#EF4444');
                ?>
                <div class="rcp-gauge-wrap">
                    <svg class="rcp-gauge" viewBox="0 0 100 100">
                        <circle class="rcp-gauge-bg" cx="50" cy="50" r="35" fill="none" stroke="#E8F0EC" stroke-width="8"/>
                        <circle class="rcp-gauge-fill" cx="50" cy="50" r="35" fill="none"
                            stroke="<?php echo $score_color; ?>" stroke-width="8"
                            stroke-dasharray="<?php echo $dash_fill . ' ' . $dash_empty; ?>"
                            stroke-dashoffset="54.975"
                            stroke-linecap="round"/>
                        <text x="50" y="46" text-anchor="middle" class="rcp-gauge-num" fill="<?php echo $score_color; ?>"><?php echo $score; ?></text>
                        <text x="50" y="59" text-anchor="middle" class="rcp-gauge-sub" fill="#6B7280">/ 100</text>
                    </svg>
                </div>

                <?php if (!empty($d['sa_delta']) && $d['sa_delta']['change'] !== 0): ?>
                <div class="rcp-delta <?php echo $d['sa_delta']['change'] > 0 ? 'rcp-delta-up' : 'rcp-delta-down'; ?>">
                    <?php echo $d['sa_delta']['change'] > 0 ? '↑' : '↓'; ?>
                    <?php echo abs($d['sa_delta']['change']); ?> pts este mes
                    <span class="rcp-delta-range">(<?php echo intval($d['sa_delta']['first_score']); ?> → <?php echo intval($d['sa_delta']['last_score']); ?>)</span>
                </div>
                <?php endif; ?>

                <?php if (!empty($d['monthly']['avg_risk_score'])): ?>
                <div class="rcp-risk-avg">
                    Riesgo medio aplicado:
                    <strong class="rcp-risk-<?php echo $this->risk_level($d['monthly']['avg_risk_score']); ?>">
                        <?php echo round($d['monthly']['avg_risk_score'], 2); ?>
                    </strong>
                    <span class="rcp-muted">/ 1.0</span>
                </div>
                <?php endif; ?>
            </div>

        </div><?php // .rcp-grid ?>
        <?php
    }

    private function render_timeline($d) {
        if (empty($d['activity'])) return;
        ?>
        <div class="rcp-card rcp-card-full">
            <h2 class="rcp-card-title">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                Actividad reciente
            </h2>
            <ol class="rcp-timeline">
                <?php foreach ($d['activity'] as $ev): ?>
                <li class="rcp-tl-item rcp-tl-<?php echo esc_attr($ev['type']); ?>">
                    <span class="rcp-tl-dot"></span>
                    <span class="rcp-tl-text"><?php echo esc_html($ev['text']); ?></span>
                    <span class="rcp-tl-time"><?php echo esc_html($ev['time']); ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
        <?php
    }

    private function render_tech_bar($d) {
        ?>
        <div class="rcp-tech-bar">
            <span>WordPress <?php echo esc_html($d['wp_version']); ?></span>
            <span>PHP <?php echo esc_html($d['php_version']); ?></span>
            <span><?php echo intval($d['plugins_count']); ?> plugins activos</span>
            <?php if ($d['hub_connected']): ?>
            <span class="rcp-hub-ok">● Hub conectado</span>
            <?php else: ?>
            <span class="rcp-hub-off">○ Hub no conectado</span>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Datos
    // -------------------------------------------------------------------------

    private function collect_data() {
        $cache   = get_option('rpcare_portal_cache', []);
        $plan    = RP_Care_Plan::get_current();
        $b2_raw  = get_option('rpcare_last_b2_backup');
        $b2      = is_array($b2_raw) ? $b2_raw : [];

        // Salud
        $health_score = intval(get_option('rpcare_health_score', 0));

        // Actualizaciones pendientes
        $pending_updates = $this->count_pending_updates();

        // Estado global
        $backup_health = $cache['backup_health'] ?? ($b2 ? 'ok' : 'unknown');
        $overall_ok    = $health_score >= 60 && $backup_health !== 'critical' && $pending_updates < 15;

        // Historial + risk map
        $update_history = $cache['update_history'] ?? [];
        $risk_raw       = $cache['risk_assessments'] ?? [];
        $risk_map       = [];
        foreach ($risk_raw as $slug => $ra) {
            $risk_map[$slug] = $ra['risk_score'] ?? null;
        }

        // Backups contados este mes
        $month_start        = strtotime('first day of this month midnight');
        $backups_this_month = 0;
        if (!empty($cache['backup_history'])) {
            foreach ((array) $cache['backup_history'] as $bk) {
                if (strtotime($bk['timestamp'] ?? '') >= $month_start) {
                    $backups_this_month++;
                }
            }
        } elseif ($b2 && strtotime($b2['timestamp'] ?? '') >= $month_start) {
            $backups_this_month = 1;
        }

        // Edad del cache
        $cache_age_label = '';
        if (!empty($cache['pushed_at'])) {
            $cache_age_label = 'actualizado ' . $this->human_time($cache['pushed_at']);
        }

        // Timeline de actividad
        $activity = $this->build_activity($update_history, $b2, $cache);

        return [
            'plan'               => $plan,
            'plan_name'          => RP_Care_Plan::get_plan_name($plan),
            'health_score'       => $health_score,
            'pending_updates'    => $pending_updates,
            'overall_ok'         => $overall_ok,
            'backup_health'      => $backup_health,
            'last_backup'        => get_option('rpcare_last_backup'),
            'last_b2_backup'     => $b2,
            'backups_this_month' => $backups_this_month,
            'ssl_days'           => $cache['ssl_days'] ?? null,
            'sa_delta'           => $cache['sa_delta'] ?? null,
            'monthly'            => $cache['monthly_summary'] ?? [],
            'update_history'     => $update_history,
            'risk_map'           => $risk_map,
            'activity'           => $activity,
            'wp_version'         => get_bloginfo('version'),
            'php_version'        => PHP_VERSION_ID >= 80000 ? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION : PHP_VERSION,
            'plugins_count'      => count(get_option('active_plugins', [])),
            'hub_connected'      => $this->is_hub_connected(),
            'cache_age_label'    => $cache_age_label,
        ];
    }

    private function build_activity($update_history, $b2, $cache) {
        $events = [];

        // Updates desde historial
        foreach (array_slice((array) $update_history, 0, 5) as $entry) {
            $ok   = ($entry['event_type'] ?? '') === 'update_completed';
            $type = $ok ? 'ok' : 'fail';
            $name = $entry['data']['plugin_name'] ?? ($entry['data']['type'] ?? 'Actualización');
            $events[] = [
                'type'      => $type,
                'text'      => $ok ? "{$name} actualizado correctamente" : "{$name} — error en la actualización",
                'time'      => $this->human_time($entry['timestamp'] ?? ''),
                'timestamp' => strtotime($entry['timestamp'] ?? '') ?: 0,
            ];
        }

        // Último B2 backup
        if ($b2 && !empty($b2['timestamp'])) {
            $events[] = [
                'type'      => 'backup',
                'text'      => 'Backup B2 completado — ' . esc_html($b2['domain'] ?? ''),
                'time'      => $this->human_time($b2['timestamp']),
                'timestamp' => strtotime($b2['timestamp']) ?: 0,
            ];
        }

        // Alerta SSL desde cache
        if (!empty($cache['ssl_days']) && intval($cache['ssl_days']) < 30) {
            $events[] = [
                'type'      => 'warn',
                'text'      => 'SSL: ' . intval($cache['ssl_days']) . ' días para renovar',
                'time'      => '',
                'timestamp' => 0,
            ];
        }

        // Ordenar por fecha desc
        usort($events, fn($a, $b) => $b['timestamp'] - $a['timestamp']);

        return array_slice($events, 0, 8);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function count_pending_updates() {
        $core    = get_site_transient('update_core');
        $plugins = get_site_transient('update_plugins');
        $themes  = get_site_transient('update_themes');
        $count   = 0;
        if ($core    && !empty($core->updates))         $count += count($core->updates);
        if ($plugins && !empty($plugins->response))     $count += count($plugins->response);
        if ($themes  && !empty($themes->response))      $count += count($themes->response);
        return $count;
    }

    private function is_hub_connected() {
        $opts = get_option('rpcare_options', []);
        $hub  = $opts['hub_url'] ?? get_option('rpcare_hub_url', '');
        $tok  = get_option('rpcare_token', '');
        return !empty($hub) && !empty($tok);
    }

    private function risk_level($score) {
        if ($score === null) return 'none';
        if ($score < 0.4)   return 'low';
        if ($score < 0.65)  return 'med';
        return 'high';
    }

    private function human_time($mysql_or_ts) {
        if (!$mysql_or_ts) return '—';
        $ts   = is_numeric($mysql_or_ts) ? (int) $mysql_or_ts : strtotime($mysql_or_ts);
        if (!$ts) return '—';
        $diff = time() - $ts;
        if ($diff < 60)         return 'hace un momento';
        if ($diff < 3600)       return 'hace ' . round($diff / 60) . ' min';
        if ($diff < 86400)      return 'hace ' . round($diff / 3600) . 'h';
        if ($diff < 7 * 86400)  return 'hace ' . round($diff / 86400) . ' días';
        return date_i18n('d M', $ts);
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function render_portal_css() {
        ?>
        <style>
        :root {
            --rp-primary:#1E2F23; --rp-accent:#93F1C9; --rp-accent-dk:#41999F;
            --rp-bg:#F7FBF9; --rp-card:#fff; --rp-border:#E8F0EC;
            --rp-text:#374151; --rp-muted:#6B7280;
            --rp-ok:#22C55E; --rp-warn:#F59E0B; --rp-fail:#EF4444;
        }
        .rcp-wrap { max-width:1200px; padding:24px 20px 48px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; color:var(--rp-text); }

        /* Hero */
        .rcp-hero { display:flex; align-items:center; gap:32px; flex-wrap:wrap;
            background:linear-gradient(135deg,var(--rp-primary) 0%,#2A5A40 60%,var(--rp-accent-dk) 100%);
            color:#fff; padding:28px 32px; border-radius:16px; margin-bottom:24px; }
        .rcp-hero-brand { flex:1; min-width:180px; }
        .rcp-plan-badge { display:inline-block; padding:3px 12px; border-radius:20px; font-size:12px;
            font-weight:600; text-transform:uppercase; letter-spacing:.5px; margin-bottom:8px; }
        .rcp-plan-semilla  { background:rgba(147,241,201,.25); color:var(--rp-accent); }
        .rcp-plan-raiz     { background:rgba(65,153,159,.3);   color:#7FE0E6; }
        .rcp-plan-ecosistema { background:rgba(255,255,255,.2); color:#fff; }
        .rcp-hero-title  { font-size:22px; font-weight:700; margin:0 0 4px; }
        .rcp-hero-domain { font-size:13px; opacity:.7; margin:0; }
        .rcp-hero-stats  { display:flex; gap:24px; flex-wrap:wrap; }
        .rcp-hero-stat   { text-align:center; }
        .rcp-stat-num    { display:block; font-size:36px; font-weight:800; line-height:1; }
        .rcp-score-num   { color:var(--rp-accent); }
        .rcp-stat-label  { font-size:11px; opacity:.75; line-height:1.3; margin-top:4px; display:block; }
        .rcp-status-pill { display:flex; align-items:center; gap:8px; padding:8px 18px;
            border-radius:24px; font-size:13px; font-weight:600; white-space:nowrap; margin-left:auto; }
        .rcp-status-ok   { background:rgba(34,197,94,.2); color:#86EFAC; }
        .rcp-status-warn { background:rgba(245,158,11,.2); color:#FDE68A; }
        .rcp-status-dot  { width:8px; height:8px; border-radius:50%; background:currentColor; }

        /* Grid */
        .rcp-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; margin-bottom:20px; }
        @media(max-width:900px){ .rcp-grid{ grid-template-columns:1fr 1fr; } }
        @media(max-width:600px){ .rcp-grid{ grid-template-columns:1fr; } }

        /* Cards */
        .rcp-card { background:var(--rp-card); border:1px solid var(--rp-border); border-radius:12px;
            padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
        .rcp-card-full { grid-column:1/-1; }
        .rcp-card-title { font-size:14px; font-weight:700; color:var(--rp-primary);
            margin:0 0 16px; display:flex; align-items:center; gap:8px; text-transform:uppercase;
            letter-spacing:.4px; }
        .rcp-card-title svg { width:16px; height:16px; opacity:.7; flex-shrink:0; }

        /* Updates */
        .rcp-update-list { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }
        .rcp-update-item { display:flex; align-items:center; gap:8px; font-size:13px; padding:6px 0;
            border-bottom:1px solid var(--rp-border); }
        .rcp-update-item:last-child { border-bottom:none; }
        .rcp-update-icon { font-size:12px; width:16px; text-align:center; }
        .rcp-update-ok .rcp-update-icon  { color:var(--rp-ok); }
        .rcp-update-fail .rcp-update-icon { color:var(--rp-fail); }
        .rcp-update-name  { flex:1; font-weight:500; }
        .rcp-update-time  { font-size:11px; color:var(--rp-muted); white-space:nowrap; }
        .rcp-risk-badge   { width:10px; height:10px; border-radius:50%; display:inline-block; flex-shrink:0; }
        .rcp-risk-low  .rcp-risk-badge, .rcp-risk-badge.rcp-risk-low  { color:var(--rp-ok); }
        .rcp-risk-med  .rcp-risk-badge, .rcp-risk-badge.rcp-risk-med  { color:var(--rp-warn); }
        .rcp-risk-high .rcp-risk-badge, .rcp-risk-badge.rcp-risk-high { color:var(--rp-fail); }
        .rcp-pending-bar { margin-top:12px; background:#FEF3C7; border-radius:8px; padding:8px 12px;
            font-size:12px; color:#92400E; display:flex; align-items:center; gap:6px; }
        .rcp-pending-bar svg { width:14px; height:14px; flex-shrink:0; }
        .rcp-empty { text-align:center; padding:24px 0; color:var(--rp-muted); font-size:13px; }
        .rcp-link-small { font-size:12px; color:var(--rp-accent-dk); text-decoration:none; }

        /* Backups */
        .rcp-backup-status { display:flex; align-items:center; gap:8px; font-size:13px;
            font-weight:600; margin-bottom:12px; }
        .rcp-backup-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
        .rcp-backup-ok   .rcp-backup-dot { background:var(--rp-ok); }
        .rcp-backup-warn .rcp-backup-dot { background:var(--rp-warn); }
        .rcp-backup-fail .rcp-backup-dot { background:var(--rp-fail); }
        .rcp-backup-files { list-style:none; margin:0 0 10px; padding:0; }
        .rcp-backup-files li { font-size:12px; color:var(--rp-muted); padding:2px 0;
            font-family:monospace; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .rcp-backup-prefix { font-size:11px; color:var(--rp-muted); font-family:monospace;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
            display:flex; align-items:center; gap:5px; margin:6px 0 0; }
        .rcp-backup-prefix svg { width:12px; height:12px; flex-shrink:0; }
        .rcp-ssl-row { font-size:12px; font-weight:600; margin-top:10px;
            display:flex; align-items:center; gap:6px; }
        .rcp-ssl-row svg { width:13px; height:13px; }
        .rcp-ssl-ok   { color:var(--rp-ok); }
        .rcp-ssl-warn { color:var(--rp-warn); }
        .rcp-ssl-fail { color:var(--rp-fail); }

        /* Gauge */
        .rcp-gauge-wrap { display:flex; justify-content:center; margin-bottom:12px; }
        .rcp-gauge { width:130px; height:130px; transform:rotate(-90deg); }
        .rcp-gauge-fill { transition:stroke-dasharray 1s ease-out; }
        .rcp-gauge-num  { font-size:22px; font-weight:800; transform:rotate(90deg);
            dominant-baseline:middle; }
        .rcp-gauge-sub  { font-size:10px; transform:rotate(90deg); dominant-baseline:middle; }
        .rcp-delta { text-align:center; font-size:14px; font-weight:700; margin-bottom:8px; }
        .rcp-delta-up   { color:var(--rp-ok); }
        .rcp-delta-down { color:var(--rp-fail); }
        .rcp-delta-range { font-size:11px; font-weight:400; color:var(--rp-muted); margin-left:4px; }
        .rcp-risk-avg { text-align:center; font-size:12px; color:var(--rp-muted); }
        .rcp-risk-avg strong { font-size:15px; }
        .rcp-risk-avg strong.rcp-risk-low  { color:var(--rp-ok); }
        .rcp-risk-avg strong.rcp-risk-med  { color:var(--rp-warn); }
        .rcp-risk-avg strong.rcp-risk-high { color:var(--rp-fail); }
        .rcp-muted { color:var(--rp-muted); }

        /* Timeline */
        .rcp-timeline { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:0; }
        .rcp-tl-item  { display:grid; grid-template-columns:20px 1fr auto;
            align-items:center; gap:12px; padding:10px 0;
            border-bottom:1px solid var(--rp-border); font-size:13px; position:relative; }
        .rcp-tl-item:last-child { border-bottom:none; }
        .rcp-tl-dot   { width:10px; height:10px; border-radius:50%; justify-self:center; }
        .rcp-tl-ok     .rcp-tl-dot { background:var(--rp-ok); }
        .rcp-tl-fail   .rcp-tl-dot { background:var(--rp-fail); }
        .rcp-tl-backup .rcp-tl-dot { background:var(--rp-accent-dk); }
        .rcp-tl-warn   .rcp-tl-dot { background:var(--rp-warn); }
        .rcp-tl-time { font-size:11px; color:var(--rp-muted); white-space:nowrap; }

        /* Tech bar */
        .rcp-tech-bar { display:flex; gap:20px; flex-wrap:wrap; padding:14px 20px;
            background:var(--rp-card); border:1px solid var(--rp-border); border-radius:10px;
            font-size:12px; color:var(--rp-muted); margin-top:20px; }
        .rcp-tech-bar span { display:flex; align-items:center; gap:4px; }
        .rcp-hub-ok  { color:var(--rp-ok); font-weight:600; }
        .rcp-hub-off { color:var(--rp-muted); }

        /* Footer */
        .rcp-footer-note { margin-top:16px; font-size:11px; color:var(--rp-muted); text-align:right; }
        .rcp-footer-note a { color:var(--rp-accent-dk); text-decoration:none; }
        </style>
        <?php
    }
}
