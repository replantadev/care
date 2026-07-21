<?php
/**
 * Care — Mis Backups (panel admin cliente).
 *
 * Submenú bajo Replanta Care > Mis Backups.
 * Lista backups B2 del site y permite restore granular (DB / uploads)
 * directamente desde el WP admin del cliente, sin Bearer token.
 */
if (!defined('ABSPATH')) {
    exit;
}

class RP_Care_Admin_Backups {

    private static $instance = null;

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu',            [$this, 'register_menu'], 15);
        add_action('wp_ajax_rpcare_bk_list',    [$this, 'ajax_list']);
        add_action('wp_ajax_rpcare_bk_restore', [$this, 'ajax_restore']);
    }

    // ── Menú ──────────────────────────────────────────────────────────────────

    public function register_menu() {
        add_submenu_page(
            'replanta-care-portal',
            'Mis Backups — Replanta Care',
            'Mis Backups',
            'manage_options',
            'replanta-care-backups',
            [$this, 'render']
        );
    }

    // ── AJAX: list ────────────────────────────────────────────────────────────

    public function ajax_list() {
        check_ajax_referer('rpcare_bk', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $limit  = max(1, min(20, (int) ($_POST['limit'] ?? 10)));
        $result = RP_Care_Task_Backup::list_b2_backups($limit);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        wp_send_json_success($result);
    }

    // ── AJAX: restore ─────────────────────────────────────────────────────────

    public function ajax_restore() {
        check_ajax_referer('rpcare_bk', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Forbidden', 403);
        }

        $backup_id = sanitize_key($_POST['backup_id'] ?? '');
        if (!$backup_id) {
            wp_send_json_error('backup_id requerido');
        }

        $scopes_raw = $_POST['scopes'] ?? 'database';
        $scopes     = is_array($scopes_raw)
            ? array_map('sanitize_key', $scopes_raw)
            : [sanitize_key($scopes_raw)];

        $result = RP_Care_Task_Backup::restore_b2_backup($backup_id, $scopes);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        if (empty($result['success'])) {
            wp_send_json_error($result['errors'][0] ?? 'Restore fallido');
        }
        wp_send_json_success($result);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public function render() {
        $b2_ok   = RP_Care_Task_Backup::is_b2_configured_public();
        $nonce   = wp_create_nonce('rpcare_bk');
        $ajax_url = admin_url('admin-ajax.php');
        ?>
        <div class="wrap">
            <h1 style="display:flex;align-items:center;gap:10px">
                <span style="font-size:1.5em">🗂️</span> Mis Backups
            </h1>

            <?php if (!$b2_ok) : ?>
            <div class="notice notice-warning">
                <p>
                    <strong>Backup B2 no configurado.</strong>
                    Los backups en la nube de Backblaze B2 no están activos para este site.
                    Contacta con tu proveedor de mantenimiento para activarlos.
                </p>
            </div>
            <?php else : ?>

            <p class="description" style="margin-bottom:16px">
                Lista de puntos de restauración en Backblaze B2. Cada restauración crea automáticamente
                un <strong>backup de seguridad previo</strong> antes de aplicar cambios.
            </p>

            <div id="rpcare-bk-status" style="display:none;padding:10px 14px;margin-bottom:12px;border-radius:6px;font-size:14px"></div>

            <div id="rpcare-bk-list-wrap">
                <p style="color:#888">Cargando backups…</p>
            </div>

            <?php endif; ?>
        </div>

        <style>
        .rpcare-bk-table { width:100%;border-collapse:collapse;font-size:13px }
        .rpcare-bk-table th { text-align:left;padding:8px 10px;background:#f6f7f7;border-bottom:1px solid #ddd;font-weight:600 }
        .rpcare-bk-table td { padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle }
        .rpcare-bk-table tr:hover td { background:#fafafa }
        .rpcare-bk-badge { display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px }
        .rpcare-bk-badge--ok   { background:#d1fae5;color:#065f46 }
        .rpcare-bk-badge--part { background:#fef3c7;color:#92400e }
        .rpcare-bk-scope { display:inline-block;padding:1px 6px;border-radius:8px;font-size:11px;background:#e5e7eb;color:#374151;margin:1px }
        .rpcare-bk-restore-row { background:#fafbff;border-top:1px dashed #c7d2fe }
        .rpcare-bk-restore-row td { padding:10px 14px }
        .rpcare-bk-spinner { display:inline-block;width:14px;height:14px;border:2px solid #aaa;border-top-color:#000;border-radius:50%;animation:rpcare-spin .7s linear infinite;vertical-align:middle;margin-right:6px }
        @keyframes rpcare-spin { to { transform:rotate(360deg) } }
        </style>

        <?php if ($b2_ok) : ?>
        <script>
        (function($) {
            var nonce    = <?php echo wp_json_encode($nonce); ?>;
            var ajaxUrl  = <?php echo wp_json_encode($ajax_url); ?>;

            function fmt_size(bytes) {
                if (!bytes) return '—';
                var mb = bytes / 1048576;
                return mb < 1 ? (bytes/1024).toFixed(1) + ' KB' : mb.toFixed(1) + ' MB';
            }

            function fmt_date(dt) {
                if (!dt) return '—';
                return new Date(dt.replace(' ', 'T') + 'Z').toLocaleString('es-ES', {
                    day:'2-digit', month:'short', year:'numeric',
                    hour:'2-digit', minute:'2-digit'
                });
            }

            function scope_label(scope) {
                var labels = { database:'Base de datos', uploads:'Archivos (uploads)', plugins:'Plugins',
                               themes:'Temas', config:'Config', manifest:'Manifiesto' };
                return labels[scope] || scope;
            }

            function show_status(msg, type) {
                var el = document.getElementById('rpcare-bk-status');
                el.style.display = 'block';
                el.style.background = type === 'ok' ? '#d1fae5' : type === 'err' ? '#fee2e2' : '#fef9c3';
                el.style.color = type === 'ok' ? '#065f46' : type === 'err' ? '#991b1b' : '#78350f';
                el.style.border = '1px solid ' + (type === 'ok' ? '#a7f3d0' : type === 'err' ? '#fca5a5' : '#fde68a');
                el.innerHTML = msg;
            }

            function load_backups() {
                var wrap = document.getElementById('rpcare-bk-list-wrap');
                wrap.innerHTML = '<p style="color:#888"><span class="rpcare-bk-spinner"></span>Consultando B2…</p>';

                $.post(ajaxUrl, { action:'rpcare_bk_list', nonce:nonce, limit:10 }, function(res) {
                    if (!res.success) {
                        wrap.innerHTML = '<div class="notice notice-error inline"><p>' + (res.data || 'Error al obtener backups') + '</p></div>';
                        return;
                    }
                    var data = res.data;
                    if (!data.backups || !data.backups.length) {
                        wrap.innerHTML = '<div class="notice notice-info inline"><p>No hay backups disponibles todavía. El primer backup se creará en el próximo ciclo programado.</p></div>';
                        return;
                    }
                    render_table(data.backups);
                }).fail(function() {
                    wrap.innerHTML = '<div class="notice notice-error inline"><p>Error de red al obtener backups.</p></div>';
                });
            }

            function render_table(backups) {
                var html = '<div style="overflow-x:auto"><table class="rpcare-bk-table">';
                html += '<thead><tr><th>Fecha</th><th>Estado</th><th>Componentes</th><th>Tamaño</th><th></th></tr></thead><tbody>';

                backups.forEach(function(bk, idx) {
                    var scopes = (bk.artifacts || []).map(function(a) { return a.scope; })
                                  .filter(function(s) { return s !== 'manifest'; });
                    var scopes_html = scopes.map(function(s) {
                        return '<span class="rpcare-bk-scope">' + scope_label(s) + '</span>';
                    }).join(' ');
                    var badge_cls = bk.status === 'completed' ? 'rpcare-bk-badge--ok' : 'rpcare-bk-badge--part';
                    var badge_lbl = bk.status === 'completed' ? 'Completo' : 'Parcial';

                    html += '<tr>';
                    html += '<td><strong>' + fmt_date(bk.created_at) + '</strong><br><small style="color:#888">' + bk.id + '</small></td>';
                    html += '<td><span class="rpcare-bk-badge ' + badge_cls + '">' + badge_lbl + '</span></td>';
                    html += '<td>' + (scopes_html || '—') + '</td>';
                    html += '<td style="white-space:nowrap">' + fmt_size(bk.size) + '</td>';
                    html += '<td><button class="button button-small rpcare-bk-expand" data-idx="' + idx + '">Restaurar ↓</button></td>';
                    html += '</tr>';

                    // Restore panel row (initially hidden)
                    html += '<tr id="rpcare-bk-panel-' + idx + '" class="rpcare-bk-restore-row" style="display:none">';
                    html += '<td colspan="5">';
                    html += '<p style="margin:0 0 10px;font-weight:600;font-size:13px">¿Qué quieres restaurar?</p>';
                    html += '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center">';

                    if (scopes.indexOf('database') >= 0) {
                        html += '<button class="button button-primary rpcare-bk-do-restore" data-bid="' + bk.id + '" data-scopes="database">🗄️ Restaurar Base de Datos</button>';
                    }
                    if (scopes.indexOf('uploads') >= 0) {
                        html += '<button class="button rpcare-bk-do-restore" data-bid="' + bk.id + '" data-scopes="uploads">📁 Restaurar Archivos (uploads)</button>';
                    }
                    if (scopes.indexOf('database') >= 0 && scopes.indexOf('uploads') >= 0) {
                        html += '<button class="button rpcare-bk-do-restore" data-bid="' + bk.id + '" data-scopes="database,uploads">🔄 Restaurar BD + Archivos</button>';
                    }

                    html += '</div>';
                    html += '<p style="margin:10px 0 0;font-size:12px;color:#888">⚠️ La restauración de la base de datos sobrescribirá los datos actuales. ';
                    html += 'Se creará un backup de seguridad automático antes de proceder.</p>';
                    html += '</td></tr>';
                });

                html += '</tbody></table></div>';
                html += '<p class="description" style="margin-top:8px">' + backups.length + ' punto(s) de restauración disponibles.</p>';

                document.getElementById('rpcare-bk-list-wrap').innerHTML = html;
                bind_events(backups);
            }

            function bind_events(backups) {
                // Toggle panel
                document.querySelectorAll('.rpcare-bk-expand').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var idx = this.dataset.idx;
                        var row = document.getElementById('rpcare-bk-panel-' + idx);
                        var open = row.style.display !== 'none';
                        row.style.display = open ? 'none' : 'table-row';
                        this.textContent = open ? 'Restaurar ↓' : 'Cerrar ↑';
                    });
                });

                // Restore button
                document.querySelectorAll('.rpcare-bk-do-restore').forEach(function(btn) {
                    btn.addEventListener('click', function() {
                        var bid    = this.dataset.bid;
                        var scopes = this.dataset.scopes;
                        var label  = this.textContent.trim();
                        var warn   = '⚠️ ¿Restaurar ' + scopes + ' desde el backup ' + bid + '?\n\n';
                        warn += 'Esta acción sobrescribirá datos actuales. Se creará un backup previo de seguridad antes de proceder.';

                        if (!confirm(warn)) return;

                        var me = this;
                        me.disabled = true;
                        me.innerHTML = '<span class="rpcare-bk-spinner"></span> Restaurando…';
                        show_status('<span class="rpcare-bk-spinner"></span> Restaurando ' + label + '…', 'info');

                        $.post(ajaxUrl, {
                            action: 'rpcare_bk_restore',
                            nonce: nonce,
                            backup_id: bid,
                            scopes: scopes.split(',')
                        }, function(res) {
                            me.disabled = false;
                            me.textContent = label;
                            if (res.success) {
                                var r = res.data;
                                var pre = r.pre_restore_backup_id ? ' (backup previo: ' + r.pre_restore_backup_id + ')' : '';
                                show_status('✅ Restauración completada. Componentes restaurados: ' + (r.restored || []).join(', ') + '.' + pre, 'ok');
                            } else {
                                show_status('❌ Error en restauración: ' + (res.data || 'desconocido'), 'err');
                            }
                        }).fail(function() {
                            me.disabled = false;
                            me.textContent = label;
                            show_status('❌ Error de red durante la restauración.', 'err');
                        });
                    });
                });
            }

            $(document).ready(function() {
                load_backups();
            });

        })(jQuery);
        </script>
        <?php endif;
    }
}
