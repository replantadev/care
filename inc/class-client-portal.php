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

    const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNTYgMjU2IiBmaWxsPSIjYTdhYWFkIj48cGF0aCBkPSJNODAuNTcsMTE3QTgsOCwwLDAsMSw5MSwxMTIuNTdsMjksMTEuNjFWOTZhOCw4LDAsMCwxLDE2LDB2MjguMThsMjktMTEuNjFBOCw4LDAsMSwxLDE3MSwxMjcuNDNsLTMwLjMxLDEyLjEyTDE1OC40LDE2My4yYTgsOCwwLDEsMS0xMi44LDkuNkwxMjgsMTQ5LjMzLDExMC40LDE3Mi44YTgsOCwwLDEsMS0xMi44LTkuNmwxNy43NC0yMy42NUw4NSwxMjcuNDNBOCw4LDAsMCwxLDgwLjU3LDExN1pNMjI0LDU2djU2YzAsNTIuNzItMjUuNTIsODQuNjctNDYuOTMsMTAyLjE5LTIzLjA2LDE4Ljg2LTQ2LDI1LjI3LTQ3LDI1LjUzYTgsOCwwLDAsMS00LjIsMGMtMS0uMjYtMjMuOTEtNi42Ny00Ny0yNS41M0M1Ny41MiwxOTYuNjcsMzIsMTY0LjcyLDMyLDExMlY1NkExNiwxNiwwLDAsMSw0OCw0MEgyMDhBMTYsMTYsMCwwLDEsMjI0LDU2Wm0tMTYsMEw0OCw1NmwwLDU2YzAsMzcuMywxMy44Miw2Ny41MSw0MS4wNyw4OS44MUExMjguMjUsMTI4LjI1LDAsMCwwLDEyOCwyMjMuNjJhMTI5LjMsMTI5LjMsMCwwLDAsMzkuNDEtMjIuMkMxOTQuMzQsMTc5LjE2LDIwOCwxNDkuMDcsMjA4LDExMloiLz48L3N2Zz4=';

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'registerMenus'], 5);
    }

    // -------------------------------------------------------------------------
    // Menú
    // -------------------------------------------------------------------------

    public function registerMenus() {
        add_menu_page(
            'Replanta Care',
            'Replanta Care',
            'manage_options',
            'replanta-care-portal',
            [$this, 'renderPortal'],
            self::MENU_ICON,
            59
        );

        add_submenu_page(
            'replanta-care-portal',
            'Mi Panel — Replanta Care',
            'Mi Panel',
            'manage_options',
            'replanta-care-portal',
            [$this, 'renderPortal']
        );
        // "Configuración" la registra settings-page.php en prioridad 10
    }

    // -------------------------------------------------------------------------
    // Render principal
    // -------------------------------------------------------------------------

    public function renderPortal() {
        $d = $this->collectData();
        $this->renderCss();
        ?>
        <div class="rcp-wrap">

            <?php $this->renderStatusBar($d); ?>
            <?php $this->renderStatsStrip($d); ?>
            <?php $this->renderCards($d); ?>
            <?php $this->renderEcommerceSection($d); ?>
            <?php $this->renderTimeline($d); ?>
            <?php $this->renderFooterRow($d); ?>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Secciones
    // -------------------------------------------------------------------------

    private function renderStatusBar($d) {
        $ok  = $d['overall_ok'];
        $msg = $ok ? 'Tu sitio est&aacute; en perfectas condiciones' : 'Hay algo que requiere atenci&oacute;n';
        $cls = $ok ? 'rcp-st-ok' : 'rcp-st-warn';
        ?>
        <div class="rcp-status-bar <?php echo $cls; ?>">
            <div class="rcp-st-left">
                <span class="rcp-st-icon">
                    <?php if ($ok): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php endif; ?>
                </span>
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

    private function renderStatsStrip($d) {
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

    private function renderCards($d) {
        ?>
        <div class="rcp-cards">
            <?php $this->renderSecurityCard($d); ?>
            <?php $this->renderUpdatesCard($d); ?>
            <?php $this->renderBackupsCard($d); ?>
        </div>
        <?php
    }

    private function renderSecurityCard($d) {
        ?>
        <div class="rcp-card">
            <h2 class="rcp-card-h">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Seguridad y protecci&oacute;n
            </h2>
            <ul class="rcp-check-list">
                <?php foreach ($d['security_checks'] as $chk): ?>
                <li class="rcp-chk rcp-chk-<?php echo $chk['ok'] ? 'ok' : 'warn'; ?>">
                    <span class="rcp-chk-ico">
                        <?php if ($chk['ok']): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php endif; ?>
                    </span>
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
        <?php
    }

    private function renderUpdatesCard($d) {
        ?>
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
                    <span class="rcp-upd-time"><?php echo esc_html($this->humanTime($entry['timestamp'] ?? '')); ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php elseif ($d['hub_connected']): ?>
            <div class="rcp-empty-state">
                <p>El historial aparecer&aacute; tras el primer ciclo de mantenimiento automatizado.</p>
            </div>
            <?php else: ?>
            <div class="rcp-empty-state">
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
        <?php
    }

    private function renderBackupsCard($d) {
        $b2   = $d['last_b2_backup'];
        $b2ok = !empty($b2['timestamp']) || !empty($d['last_backup']);
        ?>
        <div class="rcp-card">
            <h2 class="rcp-card-h">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Copias de seguridad
            </h2>

            <div class="rcp-backup-hero rcp-bh-<?php echo $b2ok ? 'ok' : 'warn'; ?>">
                <span class="rcp-bh-icon">
                    <?php if ($b2ok): ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 10h-1.26A8 8 0 1 0 9 20h9a5 5 0 0 0 0-10z"/></svg>
                    <?php else: ?>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    <?php endif; ?>
                </span>
                <div>
                    <strong class="rcp-bh-title">
                    <?php
                    if (!empty($b2['timestamp'])) {
                        echo '&Uacute;ltima copia: ' . esc_html($this->humanTime($b2['timestamp']));
                    } elseif ($d['last_backup']) {
                        echo '&Uacute;ltima copia: ' . esc_html($this->humanTime($d['last_backup']));
                    } else {
                        echo 'Sin copias registradas a&uacute;n';
                    }
                    ?>
                    </strong>
                    <span class="rcp-bh-sub">Almacenada en Backup externo Replanta &mdash; nube externa segura</span>
                </div>
            </div>

            <ul class="rcp-check-list" style="margin-top:12px">
                <li class="rcp-chk rcp-chk-<?php echo $d['backups_this_month'] > 0 ? 'ok' : 'sub'; ?>">
                    <span class="rcp-chk-ico">
                        <?php if ($d['backups_this_month'] > 0): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        <?php endif; ?>
                    </span>
                    <div><span class="rcp-chk-lbl"><?php echo intval($d['backups_this_month']); ?> copias confirmadas este mes</span></div>
                </li>
                <li class="rcp-chk rcp-chk-ok">
                    <span class="rcp-chk-ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg></span>
                    <div><span class="rcp-chk-lbl">Backup autom&aacute;tico antes de cada actualizaci&oacute;n</span></div>
                </li>
                <?php if ($d['ssl_days'] !== null): ?>
                <?php $sslCls = $this->sslClass($d['ssl_days']); ?>
                <li class="rcp-chk rcp-chk-<?php echo $sslCls; ?>">
                    <span class="rcp-chk-ico">
                        <?php if ($d['ssl_days'] > 30): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        <?php endif; ?>
                    </span>
                    <div><span class="rcp-chk-lbl">SSL v&aacute;lido &mdash; <?php echo intval($d['ssl_days']); ?> d&iacute;as restantes</span></div>
                </li>
                <?php endif; ?>
            </ul>
        </div>
        <?php
    }

    private function renderTimeline($d) {
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

    private function planFeaturesLabels($plan) {
        $features = RP_Care_Plan::get_features($plan);
        if (empty($features)) {
            return [];
        }
        $freq = [
            'daily'     => 'diarias',
            'weekly'    => 'semanales',
            'monthly'   => 'mensuales',
            'quarterly' => 'trimestrales',
            'biannual'  => 'bianuales',
        ];
        $wpo = ['basic' => 'básica', 'basic_plus' => 'media', 'advanced' => 'avanzada'];
        $labels = [];
        $upd_fr = $freq[$features['updates_frequency'] ?? 'monthly'] ?? 'mensuales';
        if (!empty($features['update_control'])) {
            $labels[] = 'Actualizaciones ' . $upd_fr;
        }
        if (!empty($features['automatic_updates'])) {
            $labels[] = 'Actualizaciones automáticas con análisis de riesgo';
        }
        if (!empty($features['backup'])) {
            $bk_fr = $freq[$features['backup_frequency'] ?? 'weekly'] ?? 'semanales';
            $labels[] = 'Copias de seguridad ' . $bk_fr . ' en Backup externo Replanta';
        }
        if (!empty($features['monitoring'])) {
            $labels[] = 'Monitorización 24/7';
        }
        if (!empty($features['priority_support'])) {
            $labels[] = 'Soporte prioritario';
        }
        if (!empty($features['hosting_included'])) {
            $labels[] = 'Hosting ecológico incluido';
        }
        $wl = $wpo[$features['wpo_level'] ?? 'basic'] ?? 'básica';
        $labels[] = 'Optimización WPO ' . $wl;
        $rv_fr = $freq[$features['review_frequency'] ?? 'quarterly'] ?? 'trimestrales';
        $labels[] = 'Revisiones ' . $rv_fr;
        return $labels;
    }

    private function renderFooterRow($d) {
        $plan_labels = $this->planFeaturesLabels($d['plan']);
        ?>
        <div class="rcp-footer-row">

            <div class="rcp-footer-plan">
                <h3 class="rcp-footer-h">Tu plan: <strong><?php echo esc_html($d['plan_name']); ?></strong></h3>
                <?php if (!empty($plan_labels)): ?>
                <ul class="rcp-plan-feat">
                    <?php foreach ($plan_labels as $label): ?>
                    <li>
                        <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3,8 6,11 13,4"/></svg>
                        <?php echo esc_html($label); ?>
                    </li>
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
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=replanta-care')); ?>" class="rcp-link-sm">Configuraci&oacute;n t&eacute;cnica</a></p>
                <p class="rcp-version-note">Replanta Care v<?php echo esc_html(RPCARE_VERSION); ?></p>
                <?php if ($d['cache_age_label']): ?>
                <p class="rcp-version-note">Datos: <?php echo esc_html($d['cache_age_label']); ?></p>
                <?php endif; ?>
            </div>

        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Seccion eCommerce addon
    // -------------------------------------------------------------------------

    private function renderEcommerceSection($d) {
        if (empty($d['ecommerce_active'])) {
            return;
        }

        $checkout = $d['checkout_status'];
        $revenue  = $d['revenue_last_check'];
        $peak     = $d['peak_window'];

        $checkout_ok  = !empty($checkout['ok']);
        $checkout_ts  = !empty($checkout['ts']) ? $this->humanTime($checkout['ts']) : 'nunca';
        $checkout_str = $checkout_ok
            ? sprintf('OK &mdash; %d/%d checks', $checkout['passed'] ?? 0, $checkout['total'] ?? 0)
            : sprintf('Fallo &mdash; %d/%d checks pasados', $checkout['passed'] ?? 0, $checkout['total'] ?? 0);

        $rev_str   = '';
        $rev_alert = false;
        if (!empty($revenue['current'])) {
            $rev_str   = sprintf('%.2f&nbsp;EUR hoy &mdash; %.2f&nbsp;EUR hace 7&nbsp;d&iacute;as', $revenue['current']['total'] ?? 0, $revenue['baseline']['total'] ?? 0);
            $rev_alert = !empty($revenue['alert']);
        }

        $peak_str = '';
        if (!empty($peak['hour'])) {
            $peak_str = sprintf('%02d:00&ndash;%02d:00', $peak['hour'], ($peak['hour'] + 2) % 24);
        }
        ?>
        <div class="rcp-ecom-section">
            <h3 class="rcp-ecom-title">addon eCommerce</h3>
            <div class="rcp-ecom-grid">

                <div class="rcp-ecom-card<?php echo $checkout_ok ? '' : ' rcp-ecom-card--warn'; ?>">
                    <span class="rcp-ecom-card__label">Checkout</span>
                    <span class="rcp-ecom-card__value"><?php echo $checkout_str; ?></span>
                    <span class="rcp-ecom-card__sub">comprobado <?php echo $checkout_ts; ?></span>
                </div>

                <?php if ($rev_str): ?>
                <div class="rcp-ecom-card<?php echo $rev_alert ? ' rcp-ecom-card--warn' : ''; ?>">
                    <span class="rcp-ecom-card__label">Ingresos</span>
                    <span class="rcp-ecom-card__value"><?php echo $rev_str; ?></span>
                    <?php if ($rev_alert): ?>
                    <span class="rcp-ecom-card__sub rcp-ecom-alert">Ca&iacute;da de <?php echo esc_html($revenue['drop_pct'] ?? 0); ?>% &mdash; Hub notificado</span>
                    <?php else: ?>
                    <span class="rcp-ecom-card__sub">sin anomal&iacute;as</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="rcp-ecom-card">
                    <span class="rcp-ecom-card__label">Backups</span>
                    <span class="rcp-ecom-card__value">cada 12&nbsp;h</span>
                    <span class="rcp-ecom-card__sub">Backup&nbsp;externo&nbsp;Replanta &mdash; retenci&oacute;n 90&nbsp;d&iacute;as</span>
                </div>

                <?php if ($peak_str): ?>
                <div class="rcp-ecom-card">
                    <span class="rcp-ecom-card__label">Ventana de actualizaciones</span>
                    <span class="rcp-ecom-card__value"><?php echo $peak_str; ?></span>
                    <span class="rcp-ecom-card__sub">horario de menor tr&aacute;fico calculado autom&aacute;ticamente</span>
                </div>
                <?php endif; ?>

            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Datos
    // -------------------------------------------------------------------------

    private function collectData() {
        $cache  = get_option('rpcare_portal_cache', []);
        $plan   = RP_Care_Plan::get_current();
        $b2_raw = get_option('rpcare_last_b2_backup');
        $b2     = is_array($b2_raw) ? $b2_raw : [];

        $health_score    = intval(get_option('rpcare_health_score', 0));
        $update_history  = (array) ($cache['update_history'] ?? []);
        $incidents       = intval($cache['incidents'] ?? $this->countFailedUpdates($update_history));
        $ssl_days        = $cache['ssl_days'] ?? null;
        $vuln_data       = get_option('rpcare_vulnerability_data', []);
        $vuln_ok         = empty($vuln_data['vulnerabilities_found']);
        $hub_connected   = $this->isHubConnected();
        $overall_ok      = $health_score >= 60 && $vuln_ok && $incidents === 0 && ($ssl_days === null || $ssl_days > 14);

        $cache_age_label = '';
        if (!empty($cache['pushed_at'])) {
            $cache_age_label = 'actualizado ' . $this->humanTime($cache['pushed_at']);
        }

        return [
            'domain'             => parse_url(home_url(), PHP_URL_HOST) ?: get_bloginfo('name'),
            'plan'               => $plan,
            'plan_name'          => RP_Care_Plan::get_plan_name($plan),
            'health_score'       => $health_score,
            'health_label'       => $this->healthLabel($health_score),
            'overall_ok'         => $overall_ok,
            'pending_updates'    => $this->countPendingUpdates(),
            'security_checks'    => $this->buildSecurityChecks($cache, $vuln_data),
            'backup_health'      => $cache['backup_health'] ?? ($b2 ? 'ok' : 'unknown'),
            'last_backup'        => get_option('rpcare_last_backup'),
            'last_b2_backup'     => $b2,
            'backups_this_month' => $this->countBackupsThisMonth($cache, $b2),
            'ssl_days'           => $ssl_days,
            'monthly'            => $cache['monthly_summary'] ?? [],
            'update_history'     => $update_history,
            'incidents'          => $incidents,
            'activity'           => $this->buildActivity($update_history, $b2, $cache),
            'hub_connected'      => $hub_connected,
            'cache_age_label'    => $cache_age_label,
            'ecommerce_active'   => class_exists('RP_Care_Addon_Manager') && RP_Care_Addon_Manager::get()->is_active('ecommerce'),
            'checkout_status'    => get_option('rpcare_checkout_status', []),
            'revenue_last_check' => get_option('rpcare_revenue_last_check', []),
            'peak_window'        => get_option('rpcare_peak_window', []),
        ];
    }

    private function buildSecurityChecks($cache, $vuln_data) {
        $vuln_ok  = empty($vuln_data['vulnerabilities_found']);
        $ssl_days = $cache['ssl_days'] ?? null;
        $connected = $this->isHubConnected();

        $vuln_label  = $vuln_ok ? 'Sin vulnerabilidades conocidas en plugins' : count($vuln_data['vulnerabilities_found']) . ' vulnerabilidades detectadas';
        $ssl_label   = $ssl_days !== null ? 'Certificado SSL: ' . intval($ssl_days) . ' días restantes' : 'Certificado SSL activo';
        $ssl_detail  = ($ssl_days !== null && $ssl_days <= 30) ? 'Renovar pronto' : '';
        $conn_label  = $connected ? 'Mantenimiento automatizado activo' : 'Mantenimiento no configurado';
        $conn_detail = $connected ? '' : 'Configura la conexión para activarlo';

        return [
            ['ok' => $vuln_ok,                                   'label' => $vuln_label,  'detail' => !$vuln_ok ? 'Ver configuración para detalles' : ''],
            ['ok' => $ssl_days === null || $ssl_days > 30,       'label' => $ssl_label,   'detail' => $ssl_detail],
            ['ok' => $connected,                                  'label' => $conn_label,  'detail' => $conn_detail],
        ];
    }

    private function countBackupsThisMonth($cache, $b2) {
        $month_start = strtotime('first day of this month midnight');
        $count       = 0;

        if (!empty($cache['backup_history'])) {
            foreach ((array) $cache['backup_history'] as $bk) {
                if (strtotime($bk['timestamp'] ?? '') >= $month_start) {
                    $count++;
                }
            }
        } elseif ($b2 && strtotime($b2['timestamp'] ?? '') >= $month_start) {
            $count = 1;
        }

        return $count;
    }

    private function buildActivity($history, $b2, $cache) {
        $events = [];

        foreach (array_slice($history, 0, 6) as $entry) {
            $ok   = ($entry['event_type'] ?? '') === 'update_completed';
            $name = $entry['data']['plugin_name'] ?? ($entry['data']['type'] ?? 'Actualización');
            $events[] = [
                'type'      => $ok ? 'ok' : 'fail',
                'text'      => $ok ? $name . ' actualizado correctamente' : $name . ' — error en la actualización',
                'time'      => $this->humanTime($entry['timestamp'] ?? ''),
                'timestamp' => strtotime($entry['timestamp'] ?? '') ?: 0,
            ];
        }

        if ($b2 && !empty($b2['timestamp'])) {
            $events[] = [
                'type'      => 'backup',
                'text'      => 'Copia de seguridad completada y almacenada en Backup externo Replanta',
                'time'      => $this->humanTime($b2['timestamp']),
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

    private function healthLabel($score) {
        $labels = [
            90 => 'Excelente',
            75 => 'Muy bien',
            60 => 'Correcto',
            40 => 'Mejorable',
        ];
        foreach ($labels as $threshold => $label) {
            if ($score >= $threshold) {
                return $label;
            }
        }
        return 'Requiere revisión';
    }

    private function sslClass($days) {
        if ($days > 30) {
            return 'ok';
        }
        if ($days > 14) {
            return 'warn';
        }
        return 'fail';
    }

    private function countPendingUpdates() {
        $core    = get_site_transient('update_core');
        $plugins = get_site_transient('update_plugins');
        $themes  = get_site_transient('update_themes');
        $count   = 0;
        if ($core && !empty($core->updates)) {
            $count += count($core->updates);
        }
        if ($plugins && !empty($plugins->response)) {
            $count += count($plugins->response);
        }
        if ($themes && !empty($themes->response)) {
            $count += count($themes->response);
        }
        return $count;
    }

    private function countFailedUpdates($history) {
        $month_start = strtotime('first day of this month midnight');
        $fail        = 0;
        foreach ($history as $entry) {
            if (strtotime($entry['timestamp'] ?? '') >= $month_start && ($entry['event_type'] ?? '') === 'update_failed') {
                $fail++;
            }
        }
        return $fail;
    }

    private function isHubConnected() {
        $opts = get_option('rpcare_options', []);
        $hub  = $opts['hub_url'] ?? get_option('rpcare_hub_url', '');
        $tok  = get_option('rpcare_token', '');
        return !empty($hub) && !empty($tok);
    }

    private function humanTime($mysqlOrTs) {
        if (!$mysqlOrTs) {
            return '—';
        }
        $ts   = is_numeric($mysqlOrTs) ? (int) $mysqlOrTs : strtotime($mysqlOrTs);
        $diff = $ts > 0 ? (time() - $ts) : -1;
        if ($ts <= 0 || $diff < 0) {
            return '—';
        }
        if ($diff >= 7 * 86400) {
            return date_i18n('d M Y', $ts);
        }
        $prefix = 'hace ';
        if ($diff < 60) {
            $label = 'un momento';
        } elseif ($diff < 3600) {
            $label = round($diff / 60) . ' min';
        } elseif ($diff < 86400) {
            $label = round($diff / 3600) . 'h';
        } else {
            $label = round($diff / 86400) . ' días';
        }
        return $prefix . $label;
    }

    // -------------------------------------------------------------------------
    // CSS
    // -------------------------------------------------------------------------

    private function renderCss() {
        ?>
        <style>
        /* ── Dark background para la página del portal ──────────────── */
        body.toplevel_page_replanta-care-portal #wpcontent,
        body.toplevel_page_replanta-care-portal #wpfooter { background: #0D1A10; }
        body.toplevel_page_replanta-care-portal #wpbody-content { padding-bottom: 0; }
        body.toplevel_page_replanta-care-portal .wrap { margin: 0; padding: 0; max-width: none; }

        /* ── Variables ─────────────────────────────────────────────── */
        .rcp-wrap {
            --rp-green:   #93F1C9;
            --rp-accent:  #93F1C9;
            --rp-teal:    #41999F;
            --rp-bg:      #0D1A10;
            --rp-card:    #1E2F23;
            --rp-card-2:  #253C2A;
            --rp-border:  rgba(147,241,201,0.13);
            --rp-border-s:rgba(147,241,201,0.30);
            --rp-text:    #F7FBF9;
            --rp-muted:   rgba(247,251,249,0.52);
            --rp-ok:      #4ade80;
            --rp-warn:    #fbbf24;
            --rp-fail:    #f87171;
            --rp-shadow:  0 4px 24px rgba(0,0,0,0.45);
            max-width: 1180px;
            margin: 0 -20px;
            padding: 24px 24px 60px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            font-size: 14px;
            color: var(--rp-text) !important;
            background: var(--rp-bg);
            min-height: calc(100vh - 32px);
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
            display: flex;
            align-items: center;
            justify-content: center;
            width: 40px; height: 40px;
            flex-shrink: 0;
        }
        .rcp-st-icon svg { width: 28px; height: 28px; color: #fff !important; stroke: #fff !important; }
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
        .rcp-stat-box.rcp-stat-warn { border-color: rgba(251,191,36,0.35); background: rgba(251,191,36,0.08); }
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
            width: 18px; height: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
        }
        .rcp-chk-ico svg { width: 14px; height: 14px; }
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
            background: rgba(251,191,36,0.08);
            border: 1px solid rgba(251,191,36,0.25);
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 12px !important;
            color: #fbbf24 !important;
        }
        .rcp-empty-state {
            text-align: center;
            padding: 20px 8px;
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
        .rcp-bh-ok   { background: rgba(74,222,128,0.08); border: 1px solid rgba(74,222,128,0.22); }
        .rcp-bh-warn { background: rgba(251,191,36,0.08); border: 1px solid rgba(251,191,36,0.22); }
        .rcp-bh-icon {
            flex-shrink: 0;
            width: 28px; height: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 1px;
        }
        .rcp-bh-icon svg { width: 22px; height: 22px; }
        .rcp-bh-ok   .rcp-bh-icon { color: var(--rp-ok); }
        .rcp-bh-warn .rcp-bh-icon { color: var(--rp-warn); }
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
            line-height: 1.7;
        }
        .rcp-plan-feat li {
            display: flex !important;
            align-items: center !important;
            gap: 6px !important;
            padding: 2px 0 !important;
        }
        .rcp-plan-feat li svg {
            width: 13px; height: 13px;
            flex-shrink: 0;
            color: var(--rp-accent) !important;
            stroke: var(--rp-accent) !important;
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

        /* ── Addon eCommerce ─────────────────────────────────────────── */
        .rcp-ecom-section {
            margin-top: 16px;
            background: var(--rp-card);
            border: 1px solid var(--rp-border);
            border-radius: 12px;
            padding: 20px 24px;
        }
        .rcp-ecom-title {
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--rp-green) !important;
            margin: 0 0 14px !important;
        }
        .rcp-ecom-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }
        .rcp-ecom-card {
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 14px 16px;
            border-radius: 10px;
            background: rgba(147,241,201,0.05);
            border: 1px solid var(--rp-border);
        }
        .rcp-ecom-card--warn {
            background: rgba(251,191,36,0.07);
            border-color: rgba(251,191,36,0.25);
        }
        .rcp-ecom-card__label {
            font-size: 10px !important;
            font-weight: 700 !important;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: var(--rp-muted) !important;
        }
        .rcp-ecom-card__value {
            font-size: 13px !important;
            font-weight: 600 !important;
            color: var(--rp-text) !important;
            line-height: 1.4;
        }
        .rcp-ecom-card--warn .rcp-ecom-card__value { color: var(--rp-warn) !important; }
        .rcp-ecom-card__sub {
            font-size: 11px !important;
            color: var(--rp-muted) !important;
        }
        .rcp-ecom-alert {
            color: var(--rp-warn) !important;
            font-weight: 600 !important;
        }
        </style>
        <?php
    }
}
