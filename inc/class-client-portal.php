<?php
/**
 * Client Portal — Panel de cliente de Replanta Care.
 *
 * Menú top-level "Replanta Care" (posición 59) con dos submenús:
 *   · Mi Panel    — dashboard orientado al cliente (este archivo)
 *   · Configuración — ajustes técnicos (settings-page.php)
 *
 * Datos: opciones locales (rpcare_*) + rpcare_portal_cache empujado por Hub.
 * Sin llamadas externas en render — carga instantánea.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Client_Portal {

    private static $instance = null;

    const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0ibm9uZSIgc3Ryb2tlPSIjYTdhYWFkIiBzdHJva2Utd2lkdGg9IjIiIHN0cm9rZS1saW5lY2FwPSJyb3VuZCIgc3Ryb2tlLWxpbmVqb2luPSJyb3VuZCI+PHBhdGggZD0iTTExIDIwQTcgNyAwIDAgMSA5IDE5QzcgMTcgMyAxNCAzIDloMGE1IDUgMCAwIDEgNS01aDFhNyA3IDAgMCAxIDYgNGwxIDJhMyAzIDAgMCAxIDMgM2gwYTMgMyAwIDAgMS0zIDNoLTFsLTEgM2EzIDMgMCAwIDEtMyAxeiIvPjxwYXRoIGQ9Ik0xMiAxMkw5IDE1Ii8+PC9zdmc+';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'register_menus'], 5);
    }

    // -------------------------------------------------------------------------
    // Menú
    // -------------------------------------------------------------------------

    public function register_menus() {
        add_menu_page(
            'Replanta Care',
            'Replanta Care',
            'manage_options',
            'replanta-care-portal',
            [$this, 'render_portal'],
            self::MENU_ICON,
            59
        );

        add_submenu_page(
            'replanta-care-portal',
            'Mi Panel — Replanta Care',
            'Mi Panel',
            'manage_options',
            'replanta-care-portal',
            [$this, 'render_portal']
        );
        // "Configuración" la registra settings-page.php en prioridad 10
    }

    // -------------------------------------------------------------------------
    // Render principal
    // -------------------------------------------------------------------------

    public function render_portal() {
        $d = $this->collect_data();
        $this->render_css();
        ?>
        <div class="rcp-wrap">

            <?php $this->render_status_bar($d); ?>
            <?php $this->render_stats_strip($d); ?>
            <?php $this->render_cards($d); ?>
            <?php $this->render_timeline($d); ?>
            <?php $this->render_footer_row($d); ?>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Secciones
    // -------------------------------------------------------------------------

    private function render_status_bar($d) {
        $ok   = $d['overall_ok'];
        $icon = $ok ? '&#10003;' : '&#9888;';
        $msg  = $ok ? 'Tu sitio est&aacute; en perfectas condiciones' : 'Hay algo que requiere atenci&oacute;n';
        $cls  = $ok ? 'rcp-st-ok' : 'rcp-st-warn';
        ?>
        <div class="rcp-status-bar <?php echo $cls; ?>">
            <div class="rcp-st-left">
                <span class="rcp-st-icon"><?php echo $icon; ?></span>
                <div>
                    <p class="rcp-st-msg"><?php echo $msg; ?></p>
                    <p class="rcp-st-domain"><?php echo esc_html($d['domain']); ?></p>
                </div>
            </div>
            <div class="rcp-st-right">
                <span class="rcp-plan-badge rcp-plan-<?php echo esc_attr($d['plan']); ?>">
                    <?php echo esc_html($d['plan_name']); ?>
                </span>
                <?php if ($d['hub_connected']): ?>
                <span class="rcp-connected-pill">
                    <span class="rcp-conn-dot"></span>
                    Conectado a Replanta
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_stats_strip($d) {
        $stats = [
            [
                'num'   => $d['monthly']['updates_ok'] ?? 0,
                'label' => 'actualizaciones',
                'sub'   => 'aplicadas este mes',
                'warn'  => false,
            ],
            [
                'num'   => $d['backups_this_month'],
                'label' => 'copias de seguridad',
                'sub'   => 'realizadas este mes',
                'warn'  => $d['backups_this_month'] === 0,
            ],
            [
                'num'   => $d['health_score'],
                'label' => 'puntuaci&oacute;n de salud',
                'sub'   => esc_html($d['health_label']),
                'warn'  => $d['health_score'] < 70,
            ],
            [
                'num'   => $d['incidents'],
                'label' => 'incidencias',
                'sub'   => 'detectadas este mes',
                'warn'  => $d['incidents'] > 0,
            ],
        ];
        ?>
        <div class="rcp-stats-strip">
            <?php foreach ($stats as $s): ?>
            <div class="rcp-stat-box<?php echo $s['warn'] ? ' rcp-stat-warn' : ''; ?>">
                <span class="rcp-stat-big"><?php echo intval($s['num']); ?></span>
                <span class="rcp-stat-lbl"><?php echo $s['label']; ?></span>
                <span class="rcp-stat-sub"><?php echo $s['sub']; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_cards($d) {
        ?>
        <div class="rcp-cards">

            <div class="rcp-card">
                <h2 class="rcp-card-h">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    Seguridad y protecci&oacute;n
                </h2>
                <ul class="rcp-check-list">
                    <?php foreach ($d['security_checks'] as $chk): ?>
                    <li class="rcp-chk rcp-chk-<?php echo $chk['ok'] ? 'ok' : 'warn'; ?>">
                        <span class="rcp-chk-ico"><?php echo $chk['ok'] ? '&#10003;' : '&#9888;'; ?></span>
                        <div>
                            <span class="rcp-chk-lbl"><?php echo esc_html($chk['label']); ?></span>
                            <?php if ($chk['detail']): ?>
                            <span class="rcp-chk-detail"><?php echo esc_html($chk['detail']); ?></span>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="rcp-card">
                <h2 class="rcp-card-h">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
                    Actualizaciones aplicadas
                </h2>

                <?php if (!empty($d['update_history'])): ?>
                <ul class="rcp-update-list">
                    <?php foreach (array_slice($d['update_history'], 0, 5) as $entry):
                        $ok   = ($entry['event_type'] ?? '') === 'update_completed';
                        $name = $entry['data']['plugin_name'] ?? ($entry['data']['type'] ?? 'Actualizaci&oacute;n');
                    ?>
                    <li class="rcp-upd-item rcp-upd-<?php echo $ok ? 'ok' : 'fail'; ?>">
                        <span class="rcp-upd-dot"></span>
                        <span class="rcp-upd-name"><?php echo esc_html($name); ?></span>
                        <span class="rcp-upd-time"><?php echo esc_html($this->human_time($entry['timestamp'] ?? '')); ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php elseif ($d['hub_connected']): ?>
                <div class="rcp-empty-state">
                    <span class="rcp-empty-ico">&#128260;</span>
                    <p>El historial aparecer&aacute; tras el primer ciclo de mantenimiento automatizado.</p>
                </div>
                <?php else: ?>
                <div class="rcp-empty-state">
                    <span class="rcp-empty-ico">&#128268;</span>
                    <p>Conecta tu sitio a Replanta para activar el mantenimiento autom&aacute;tico.</p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=replanta-care')); ?>" class="rcp-link-sm">Configurar conexi&oacute;n &rarr;</a>
                </div>
                <?php endif; ?>

                <?php if ($d['pending_updates'] > 0): ?>
                <div class="rcp-pending-notice">
                    <?php echo intval($d['pending_updates']); ?> actualizaci&oacute;n<?php echo $d['pending_updates'] > 1 ? 'es pendientes' : ' pendiente'; ?> &mdash; se aplicar&aacute; autom&aacute;ticamente esta semana
                </div>
                <?php endif; ?>
            </div>

            <div class="rcp-card">
                <h2 class="rcp-card-h">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                    Copias de seguridad
                </h2>

                <?php
                $b2   = $d['last_b2_backup'];
                $bh   = $d['backup_health'];
                $b2ok = !empty($b2['timestamp']) || !empty($d['last_backup']);
                ?>
                <div class="rcp-backup-hero rcp-bh-<?php echo $b2ok ? 'ok' : 'warn'; ?>">
                    <span class="rcp-bh-icon"><?php echo $b2ok ? '&#9729;' : '&#9888;'; ?></span>
                    <div>
                        <strong class="rcp-bh-title">
                        <?php
                        if (!empty($b2['timestamp'])) {
                            echo '&Uacute;ltima copia: ' . esc_html($this->human_time($b2['timestamp']));
                        } elseif ($d['last_backup']) {
                            echo '&Uacute;ltima copia: ' . esc_html($this->human_time($d['last_backup']));
                        } else {
                            echo 'Sin copias registradas a&uacute;n';
                        }
                        ?>
                        </strong>
                        <span class="rcp-bh-sub">Almacenada en Backblaze B2 &mdash; nube externa segura</span>
                    </div>
                </div>

                <ul class="rcp-check-list" style="margin-top:12px">
                    <li class="rcp-chk rcp-chk-<?php echo $d['backups_this_month'] > 0 ? 'ok' : 'sub'; ?>">
                        <span class="rcp-chk-ico"><?php echo $d['backups_this_month'] > 0 ? '&#10003;' : '&middot;'; ?></span>
                        <div><span class="rcp-chk-lbl"><?php echo intval($d['backups_this_month']); ?> copias confirmadas este mes</span></div>
                    </li>
                    <li class="rcp-chk rcp-chk-ok">
                        <span class="rcp-chk-ico">&#10003;</span>
                        <div><span class="rcp-chk-lbl">Backup autom&aacute;tico antes de cada actualizaci&oacute;n</span></div>
                    </li>
                    <?php if ($d['ssl_days'] !== null): ?>
                    <?php $ssl_cls = $d['ssl_days'] > 30 ? 'ok' : ($d['ssl_days'] > 14 ? 'warn' : 'fail'); ?>
                    <li class="rcp-chk rcp-chk-<?php echo $ssl_cls; ?>">
                        <span class="rcp-chk-ico"><?php echo $d['ssl_days'] > 30 ? '&#10003;' : '&#9888;'; ?></span>
                        <div><span class="rcp-chk-lbl">SSL v&aacute;lido &mdash; <?php echo intval($d['ssl_days']); ?> d&iacute;as restantes</span></div>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

        </div>
        <?php
    }

    private function render_timeline($d) {
        if (empty($d['activity'])) {
            return;
        }
        ?>
        <div class="rcp-card rcp-card-wide">
            <h2 class="rcp-card-h">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                Actividad reciente de tu sitio
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

    private function render_footer_row($d) {
        $plan_features = RP_Care_Plan::get_features($d['plan']);
        ?>
        <div class="rcp-footer-row">

            <div class="rcp-footer-plan">
                <h3 class="rcp-footer-h">Tu plan: <strong><?php echo esc_html($d['plan_name']); ?></strong></h3>
                <?php if (!empty($plan_features)): ?>
                <ul class="rcp-plan-feat">
                    <?php foreach ((array) $plan_features as $feat): ?>
                    <li>&middot; <?php echo esc_html($feat); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <div class="rcp-footer-support">
                <h3 class="rcp-footer-h">&iquest;Tienes alguna pregunta?</h3>
                <p class="rcp-footer-p">Estamos aqu&iacute; para ayudarte.</p>
                <a href="mailto:info@replanta.dev" class="rcp-btn-support">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    info@replanta.dev
                </a>
                <a href="https://replanta.net" target="_blank" rel="noopener" class="rcp-btn-web">replanta.net</a>
            </div>

            <div class="rcp-footer-meta">
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=replanta-care')); ?>" class="rcp-link-sm">&#9881; Configuraci&oacute;n t&eacute;cnica</a></p>
                <p class="rcp-version-note">Replanta Care v<?php echo esc_html(RPCARE_VERSION); ?></p>
                <?php if ($d['cache_age_label']): ?>
                <p class="rcp-version-note">Datos: <?php echo esc_html($d['cache_age_label']); ?></p>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Datos
    // -------------------------------------------------------------------------

    private function collect_data() {
        $cache  = get_option('rpcare_portal_cache', []);
        $plan   = RP_Care_Plan::get_current();
        $b2_raw = get_option('rpcare_last_b2_backup');
        $b2     = is_array($b2_raw) ? $b2_raw : [];

        $health_score = intval(get_option('rpcare_health_score', 0));
        $health_label = $this->health_label($health_score);

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

        $update_history = (array) ($cache['update_history'] ?? []);
        $incidents      = intval($cache['incidents'] ?? $this->count_failed_updates($update_history));
        $pending        = $this->count_pending_updates();

        $vuln_data = get_option('rpcare_vulnerability_data', []);
        $vuln_ok   = empty($vuln_data['vulnerabilities_found']);
        $ssl_days  = $cache['ssl_days'] ?? null;

        $security_checks = [
            [
                'ok'     => $vuln_ok,
                'label'  => $vuln_ok ? 'Sin vulnerabilidades conocidas en plugins' : count($vuln_data['vulnerabilities_found']) . ' vulnerabilidades detectadas',
                'detail' => !$vuln_ok ? 'Ver configuración para detalles' : '',
            ],
            [
                'ok'     => $ssl_days === null || $ssl_days > 30,
                'label'  => $ssl_days !== null ? 'Certificado SSL: ' . intval($ssl_days) . ' días restantes' : 'Certificado SSL activo',
                'detail' => ($ssl_days !== null && $ssl_days <= 30) ? 'Renovar pronto' : '',
            ],
            [
                'ok'     => $this->is_hub_connected(),
                'label'  => $this->is_hub_connected() ? 'Mantenimiento automatizado activo' : 'Mantenimiento no configurado',
                'detail' => $this->is_hub_connected() ? '' : 'Configura la conexión para activarlo',
            ],
        ];

        $backup_health   = $cache['backup_health'] ?? ($b2 ? 'ok' : 'unknown');
        $last_backup     = get_option('rpcare_last_backup');
        $hub_connected   = $this->is_hub_connected();
        $overall_ok      = $health_score >= 60 && $vuln_ok && $incidents === 0 && ($ssl_days === null || $ssl_days > 14);

        $cache_age_label = '';
        if (!empty($cache['pushed_at'])) {
            $cache_age_label = 'actualizado ' . $this->human_time($cache['pushed_at']);
        }

        $activity = $this->build_activity($update_history, $b2, $cache);

        return [
            'domain'             => parse_url(home_url(), PHP_URL_HOST) ?: get_bloginfo('name'),
            'plan'               => $plan,
            'plan_name'          => RP_Care_Plan::get_plan_name($plan),
            'health_score'       => $health_score,
            'health_label'       => $health_label,
            'overall_ok'         => $overall_ok,
            'pending_updates'    => $pending,
            'security_checks'    => $security_checks,
            'backup_health'      => $backup_health,
            'last_backup'        => $last_backup,
            'last_b2_backup'     => $b2,
            'backups_this_month' => $backups_this_month,
            'ssl_days'           => $ssl_days,
            'monthly'            => $cache['monthly_summary'] ?? [],
            'update_history'     => $update_history,
            'incidents'          => $incidents,
            'activity'           => $activity,
            'hub_connected'      => $hub_connected,
            'cache_age_label'    => $cache_age_label,
        ];
    }

    private function build_activity($history, $b2, $cache) {
        $events = [];

        foreach (array_slice($history, 0, 6) as $entry) {
            $ok   = ($entry['event_type'] ?? '') === 'update_completed';
            $name = $entry['data']['plugin_name'] ?? ($entry['data']['type'] ?? 'Actualización');
            $events[] = [
                'type'      => $ok ? 'ok' : 'fail',
                'text'      => $ok ? $name . ' actualizado correctamente' : $name . ' — error en la actualización',
                'time'      => $this->human_time($entry['timestamp'] ?? ''),
                'timestamp' => strtotime($entry['timestamp'] ?? '') ?: 0,
            ];
        }

        if ($b2 && !empty($b2['timestamp'])) {
            $events[] = [
                'type'      => 'backup',
                'text'      => 'Copia de seguridad completada y almacenada en Backblaze B2',
                'time'      => $this->human_time($b2['timestamp']),
                'timestamp' => strtotime($b2['timestamp']) ?: 0,
            ];
        }

        if (!empty($cache['ssl_days']) && intval($cache['ssl_days']) < 30) {
            $events[] = [
                'type'      => 'warn',
                'text'      => 'SSL caduca pronto — quedan ' . intval($cache['ssl_days']) . ' días',
                'time'      => '',
                'timestamp' => time(),
            ];
        }

        usort($events, fn($a, $b) => $b['timestamp'] - $a['timestamp']);
        return array_slice($events, 0, 8);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function health_label($score) {
        if ($score >= 90) return 'Excelente';
        if ($score >= 75) return 'Muy bien';
        if ($score >= 60) return 'Correcto';
        if ($score >= 40) return 'Mejorable';
        return 'Requiere revisión';
    }

    private function count_pending_updates() {
        $core    = get_site_transient('update_core');
        $plugins = get_site_transient('update_plugins');
        $themes  = get_site_transient('update_themes');
        $count   = 0;
        if ($core    && !empty($core->updates))     $count += count($core->updates);
        if ($plugins && !empty($plugins->response)) $count += count($plugins->response);
        if ($themes  && !empty($themes->response))  $count += count($themes->response);
        return $count;
    }

    private function count_failed_updates($history) {
        $month_start = strtotime('first day of this month midnight');
        $fail        = 0;
        foreach ($history as $entry) {
            if (strtotime($entry['timestamp'] ?? '') >= $month_start && ($entry['event_type'] ?? '') === 'update_failed') {
                $fail++;
            }
        }
        return $fail;
    }

    private function is_hub_connected() {
        $opts = get_option('rpcare_options', []);
        $hub  = $opts['hub_url'] ?? get_option('rpcare_hub_url', '');
        $tok  = get_option('rpcare_token', '');
        return !empty($hub) && !empty($tok);
    }

    private function human_time($mysql_or_ts) {
        if (!$mysql_or_ts) return '—';
        $ts   = is_numeric($mysql_or_ts) ? (int) $mysql_or_ts : strtotime($mysql_or_ts);
        if (!$ts) return '—';
        $diff = time() - $ts;
        if ($diff < 60)        return 'hace un momento';
        if ($diff < 3600)      return 'hace ' . round($diff / 60) . ' min';
        if ($diff < 86400)     return 'hace ' . round($diff / 3600) . 'h';
        if ($diff < 7 * 86400) return 'hace ' . round($diff / 86400) . ' días';
        return date_i18n('d M Y', $ts);
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function render_css() {
        ?>
        <style>
        /* ── Variables ─────────────────────────────────────────────── */
        .rcp-wrap {
            --rp-green:   #1E2F23;
            --rp-accent:  #93F1C9;
            --rp-teal:    #41999F;
            --rp-bg:      #F4F8F6;
            --rp-card:    #FFFFFF;
            --rp-border:  #DDE8E3;
            --rp-text:    #2D3A33;
            --rp-muted:   #6B7B72;
            --rp-ok:      #16A34A;
            --rp-warn:    #D97706;
            --rp-fail:    #DC2626;
            max-width: 1180px;
            padding: 24px 20px 60px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            color: var(--rp-text) !important;
        }

        /* ── Status bar ──────────────────────────────────────────────── */
        .rcp-status-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            padding: 22px 28px;
            border-radius: 14px;
            margin-bottom: 20px;
        }
        .rcp-st-ok   { background: linear-gradient(135deg, #1E2F23 0%, #2A5A40 60%, #41999F 100%); }
        .rcp-st-warn { background: linear-gradient(135deg, #451a03 0%, #92400e 100%); }
        .rcp-st-left { display: flex; align-items: center; gap: 16px; }
        .rcp-st-icon {
            font-size: 32px !important;
            line-height: 1 !important;
            color: #fff !important;
        }
        .rcp-st-msg {
            font-size: 20px !important;
            font-weight: 700 !important;
            color: #fff !important;
            margin: 0 0 2px !important;
            line-height: 1.2 !important;
        }
        .rcp-st-domain {
            font-size: 13px !important;
            color: rgba(255,255,255,.75) !important;
            margin: 0 !important;
        }
        .rcp-st-right {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .rcp-plan-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px !important;
            font-weight: 700 !important;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: #fff !important;
        }
        .rcp-plan-semilla    { background: rgba(255,255,255,.2); }
        .rcp-plan-raiz       { background: rgba(147,241,201,.3); color: #93F1C9 !important; }
        .rcp-plan-ecosistema { background: rgba(65,153,159,.4); }
        .rcp-connected-pill {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px !important;
            color: rgba(255,255,255,.85) !important;
            background: rgba(255,255,255,.12);
            padding: 4px 12px;
            border-radius: 20px;
        }
        .rcp-conn-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: #93F1C9;
            box-shadow: 0 0 0 3px rgba(147,241,201,.3);
        }

        /* ── Stats strip ─────────────────────────────────────────────── */
        .rcp-stats-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        @media(max-width:780px) { .rcp-stats-strip { grid-template-columns: repeat(2,1fr); } }
        .rcp-stat-box {
            background: var(--rp-card);
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            padding: 18px 16px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .rcp-stat-box.rcp-stat-warn { border-color: #fcd34d; background: #fffbeb; }
        .rcp-stat-big {
            display: block !important;
            font-size: 36px !important;
            font-weight: 800 !important;
            line-height: 1 !important;
            color: var(--rp-green) !important;
            margin-bottom: 4px !important;
        }
        .rcp-stat-warn .rcp-stat-big { color: var(--rp-warn) !important; }
        .rcp-stat-lbl {
            display: block !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            color: var(--rp-text) !important;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        .rcp-stat-sub {
            display: block !important;
            font-size: 11px !important;
            color: var(--rp-muted) !important;
            margin-top: 2px !important;
        }

        /* ── Cards ───────────────────────────────────────────────────── */
        .rcp-cards {
            display: grid;
            grid-template-columns: repeat(3,1fr);
            gap: 16px;
            margin-bottom: 16px;
        }
        @media(max-width:900px) { .rcp-cards { grid-template-columns: 1fr 1fr; } }
        @media(max-width:600px) { .rcp-cards { grid-template-columns: 1fr; } }
        .rcp-card {
            background: var(--rp-card);
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,.05);
        }
        .rcp-card-wide { grid-column: 1/-1; }
        .rcp-card-h {
            display: flex !important;
            align-items: center !important;
            gap: 8px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            text-transform: uppercase !important;
            letter-spacing: .5px !important;
            color: var(--rp-green) !important;
            margin: 0 0 16px !important;
            padding: 0 !important;
            border: none !important;
        }
        .rcp-card-h svg { width: 15px; height: 15px; flex-shrink: 0; opacity: .8; }

        /* ── Check list ──────────────────────────────────────────────── */
        .rcp-check-list {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .rcp-chk {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 8px 0;
            border-bottom: 1px solid var(--rp-border);
        }
        .rcp-chk:last-child { border-bottom: none; }
        .rcp-chk-ico {
            flex-shrink: 0;
            font-size: 13px;
            width: 18px;
            text-align: center;
            margin-top: 1px;
        }
        .rcp-chk-ok   .rcp-chk-ico { color: var(--rp-ok) !important; }
        .rcp-chk-warn .rcp-chk-ico { color: var(--rp-warn) !important; }
        .rcp-chk-fail .rcp-chk-ico { color: var(--rp-fail) !important; }
        .rcp-chk-sub  .rcp-chk-ico { color: var(--rp-muted) !important; }
        .rcp-chk-lbl {
            display: block;
            font-size: 13px !important;
            color: var(--rp-text) !important;
            line-height: 1.4 !important;
        }
        .rcp-chk-detail {
            display: block;
            font-size: 11px !important;
            color: var(--rp-muted) !important;
            margin-top: 1px;
        }

        /* ── Updates ─────────────────────────────────────────────────── */
        .rcp-update-list {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .rcp-upd-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid var(--rp-border);
        }
        .rcp-upd-item:last-child { border-bottom: none; }
        .rcp-upd-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
        .rcp-upd-ok   .rcp-upd-dot { background: var(--rp-ok); }
        .rcp-upd-fail .rcp-upd-dot { background: var(--rp-fail); }
        .rcp-upd-name {
            flex: 1;
            font-size: 13px !important;
            font-weight: 500 !important;
            color: var(--rp-text) !important;
        }
        .rcp-upd-time {
            font-size: 11px !important;
            color: var(--rp-muted) !important;
            white-space: nowrap;
        }
        .rcp-pending-notice {
            margin-top: 12px;
            background: #fefce8;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px !important;
            color: #854d0e !important;
        }
        .rcp-empty-state {
            text-align: center;
            padding: 20px 8px;
        }
        .rcp-empty-ico {
            display: block;
            font-size: 28px;
            margin-bottom: 8px;
        }
        .rcp-empty-state p {
            font-size: 13px !important;
            color: var(--rp-muted) !important;
            margin: 0 0 8px !important;
        }

        /* ── Backup hero ─────────────────────────────────────────────── */
        .rcp-backup-hero {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px;
            border-radius: 8px;
        }
        .rcp-bh-ok   { background: #f0fdf4; border: 1px solid #bbf7d0; }
        .rcp-bh-warn { background: #fffbeb; border: 1px solid #fde68a; }
        .rcp-bh-icon { font-size: 22px; line-height: 1; flex-shrink: 0; margin-top: 2px; }
        .rcp-bh-title {
            display: block;
            font-size: 13px !important;
            font-weight: 600 !important;
            color: var(--rp-text) !important;
            margin-bottom: 2px;
        }
        .rcp-bh-sub {
            font-size: 11px !important;
            color: var(--rp-muted) !important;
        }

        /* ── Timeline ────────────────────────────────────────────────── */
        .rcp-timeline {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
        }
        .rcp-tl-item {
            display: grid;
            grid-template-columns: 12px 1fr auto;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid var(--rp-border);
        }
        .rcp-tl-item:last-child { border-bottom: none; }
        .rcp-tl-dot { width: 10px; height: 10px; border-radius: 50%; justify-self: center; }
        .rcp-tl-ok     .rcp-tl-dot { background: var(--rp-ok); }
        .rcp-tl-fail   .rcp-tl-dot { background: var(--rp-fail); }
        .rcp-tl-backup .rcp-tl-dot { background: var(--rp-teal); }
        .rcp-tl-warn   .rcp-tl-dot { background: var(--rp-warn); }
        .rcp-tl-text {
            font-size: 13px !important;
            color: var(--rp-text) !important;
        }
        .rcp-tl-time {
            font-size: 11px !important;
            color: var(--rp-muted) !important;
            white-space: nowrap;
        }

        /* ── Footer row ──────────────────────────────────────────────── */
        .rcp-footer-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 16px;
            margin-top: 16px;
            background: var(--rp-card);
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            padding: 20px 24px;
            align-items: start;
        }
        @media(max-width:700px) { .rcp-footer-row { grid-template-columns: 1fr; } }
        .rcp-footer-h {
            font-size: 13px !important;
            font-weight: 700 !important;
            color: var(--rp-green) !important;
            margin: 0 0 8px !important;
        }
        .rcp-plan-feat {
            list-style: none !important;
            margin: 0 !important;
            padding: 0 !important;
            font-size: 12px !important;
            color: var(--rp-muted) !important;
            line-height: 1.8;
        }
        .rcp-footer-p {
            font-size: 13px !important;
            color: var(--rp-muted) !important;
            margin: 0 0 10px !important;
        }
        .rcp-btn-support, .rcp-btn-web {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 13px !important;
            font-weight: 600 !important;
            padding: 7px 14px;
            border-radius: 8px;
            text-decoration: none !important;
            margin-right: 8px;
            margin-bottom: 4px;
        }
        .rcp-btn-support {
            background: var(--rp-green) !important;
            color: #fff !important;
        }
        .rcp-btn-support:hover { background: #2A5A40 !important; color: #fff !important; }
        .rcp-btn-support svg { width: 14px; height: 14px; }
        .rcp-btn-web {
            background: var(--rp-bg) !important;
            color: var(--rp-green) !important;
            border: 1px solid var(--rp-border) !important;
        }
        .rcp-btn-web:hover { background: var(--rp-border) !important; }
        .rcp-footer-meta { text-align: right; }
        .rcp-link-sm {
            font-size: 12px !important;
            color: var(--rp-teal) !important;
            text-decoration: none !important;
        }
        .rcp-link-sm:hover { text-decoration: underline !important; }
        .rcp-version-note {
            font-size: 11px !important;
            color: var(--rp-muted) !important;
            margin: 3px 0 !important;
        }
        </style>
        <?php
    }
}
