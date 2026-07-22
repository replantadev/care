# Changelog — Replanta Care

## [1.15.8]

- hub_ping(): añade ssl_expires_at + ssl_days_left (check cert SSL del propio site, caché 12h)
- hub_ping(): añade backup_stale (bool) — true si el último backup supera el umbral del plan
  - Semilla: stale si >8 días sin backup
  - Raíz: stale si >26 horas sin backup (SLA)
  - Ecosistema: stale si >14 horas sin backup (2× día)
- hub_config(): reschedule AS jobs (backup + updates) al cambiar de plan — evita que la
  frecuencia anterior quede activa tras el cambio
- class-plan: Ecosistema backups → twicedaily (era daily)
- class-plan: añade backup_stale_threshold_h por plan
- class-scheduler: añade rpcare_task_db_cleanup (mensual) → RP_Care_Utils::db_cleanup_wp
- class-utils: db_cleanup_wp() — limpieza WP DB: revisiones, trash, spam, meta huérfana,
  transients expirados

## [1.15.7]

- hub_update(): backup preventivo pre-update (DB + config) vía B2 antes de aplicar el ZIP
  - Si B2 está configurado y el backup falla → update abortado con error 500 (seguridad primero)
  - Si B2 no está configurado → update continúa sin backup (comportamiento previo)

## [1.15.6]

- integrations-backup: cleanup_b2_old_backups() — limpieza automática de backups en B2 según retención del plan
  - Siempre conserva mínimo 2 sets de backup en B2
  - Elimina sets más antiguos que backup_retention_days (Semilla 7d, Raíz 30d, Ecosistema 90d)
  - b2_delete_file_version(): nuevo método privado para borrar objetos B2 via b2_delete_file_version API
  - Se ejecuta en cada create_b2_backup() tras la limpieza local

## [1.15.5]

- hub_ping(): añade backup_last_at, backup_status y updates_pending a la respuesta
  - backup_last_at: timestamp del último backup completado (rpcare_last_b2_backup o rpcare_last_backup)
  - backup_status: 'completed' | 'partial' según rpcare_last_b2_backup.status
  - updates_pending: count de plugins con actualización disponible (get_site_transient update_plugins)

## [1.15.4]

- hub_update(): ZipArchive + atomic rename en lugar de Plugin_Upgrader — evita que security plugins (Wordfence, Cerber) bloqueen el update via hooks
- hub_update(): fallback ZipArchive → Plugin_Upgrader::install() si ZipArchive falla
- hub_update(): delete_site_option('external_updates-replanta-care') en fallback PUC y post-install — resetea cache interna de PUC (12h)
- hub_update(): validación magic bytes ZIP antes de instalar — evita instalar respuestas HTML de error de auth
- hub_update(): post-install opcache_reset() + limpieza de transients PUC
- Añadido helper privado rmdir_recursive() para limpieza de directorios temporales

## [1.15.3]

- hub_update(): bypass PUC 12h transient cache — fetch version+zip_url directamente del Hub, instala con Plugin_Upgrader::install() sin depender del mecanismo WP update_plugins

## [1.15.2]

- REST: nuevas rutas /run, /backup/list, /backup/restore en namespace de control Hub
- Admin: pagina "Mis Backups" para cliente (admin-backups.php) con restauracion granular BD/Archivos
- RP_Care_Task_Backup: metodo publico is_b2_configured_public()

## [1.14.5]

- Admin bar: etiqueta principal "Mantenimiento activo" cuando Care esta conectado al Hub.
- Admin bar: menu desplegable con filas flexibles, sin solapes entre texto y valores.
- Encoding: cabecera del plugin saneada para evitar mojibake en WordPress Admin.

## [1.13.1]

- Fix dark mode en pagina portal (body.toplevel_page_replanta-care-portal faltaba en admin.css)
- Rebrand: "Backblaze B2" renombrado a "Backup externo Replanta" en portal del cliente
- notify_hub(): logging de error si wp_remote_post falla localmente (sin afectar flujo fire-and-forget)
- deploy.yml: excluye directorio docs/ del ZIP de distribucion

## [1.13.0]

- Addon system: RP_Care_Addon_Manager singleton — Hub empuja addons activos via /config, Care los almacena y expone is_active() / get_config()
- Addon eCommerce — Checkout Monitor: verifica shop/carrito/checkout/WC REST/pasarelas cada 15 min, alerta Hub tras 2 fallos consecutivos
- Addon eCommerce — Peak Scheduler: analiza pedidos de 28 dias, detecta ventana de menor trafico y reprograma actualizaciones automaticamente
- Addon eCommerce — Revenue Anomaly: compara ingresos (12h actual vs misma ventana hace 7d), alerta Hub + email si caida >= umbral (defecto 35%)
- Addon eCommerce — Backups a 12h (twicedaily) cuando addon activo, con retencion de 90 dias
- Portal: seccion "addon eCommerce" en Mi Panel mostrando estado de checkout, ingresos, backups 12h y ventana pico
- REST /config: acepta parametros addons[] y ecommerce_config{}; reschedulea automaticamente al cambiar addons
- Scheduler: clear_addon_schedules() para reschedule limpio al activar/desactivar addons
- Hub: 2.3.0 / Care: 1.13.0

## [1.12.3]

- Portal: dark mode completo con paleta forest-green (#0D1A10 / #1E2F23 / #93F1C9)
- Portal: icono del menú de admin cambiado a escudo-estrella (shield-star)
- Portal: "Tu plan" muestra características legibles (actualizaciones, backups, WPO, revisiones)
- Configuración: dark mode restaurado tras migración del menú (selector body class actualizado)
- CI: fix overwrite_files en action-gh-release@v2

## [1.12.2]

- Fix crítico: class-client-portal.php no estaba siendo commiteado por build.ps1 — causaba Fatal error en producción (getInstance undefined)
- build.ps1: ahora incluye inc/ y build.ps1 en git add para evitar desincronías entre local y GitHub Actions ZIP

## [1.12.1]

- Fix: emojis eliminados del portal — reemplazados por SVG inline
- Fix: métodos renombrados a camelCase (SonarQube php:S100)
- Fix: complejidad cognitiva reducida extrayendo renderSecurityCard, renderUpdatesCard, renderBackupsCard, buildSecurityChecks, countBackupsThisMonth (php:S3776)
- Fix: humanTime y healthLabel reducidos a 3 returns máximo (php:S1142)
- Fix: ternario anidado eliminado con sslClass() (php:S3358)
- Fix: llaves en todos los if de una línea (php:S121)
- Fix: literal "hace " definido como variable $prefix (php:S1192)
- Fix: GitExec y landing check sin 2>&1 — compatible con PowerShell 5.1

## [1.12.0]

- ClientPortal rediseñado: panel orientado al cliente con lenguaje natural (sin jerga técnica)
- Barra de estado con mensaje claro ("Tu sitio está en perfectas condiciones") y dominio destacado
- Franja de estadísticas: actualizaciones, copias de seguridad, puntuación de salud, incidencias del mes
- Tarjeta de Seguridad: vulnerabilidades, SSL y estado del mantenimiento automatizado
- Tarjeta de Actualizaciones: historial de plugins sin puntuaciones de riesgo técnico
- Tarjeta de Copias de seguridad: última copia con fecha legible, contador mensual, Backblaze B2
- Timeline en lenguaje natural: "WooCommerce actualizado correctamente", no logs técnicos
- Pie con plan contratado + CTA de soporte (info@replanta.dev) + enlace a configuración técnica
- Fix: texto negro sobre fondo oscuro en el hero — todos los colores con !important para superar WP admin CSS
- Fix: "Hub conectado" → "Conectado a Replanta"
- Fix: páginas de configuración devolvían "no permissions" tras migrar URL (options-general.php → admin.php)
- Hub: 2.2.0 · Care: 1.12.0

## [1.11.0]

- ClientPortal: nuevo menú top-level "Replanta Care" con submenús "Mi Panel" y "Configuración"
- Panel de cliente con Hero (plan + stats mensuales), grid 3 columnas (actualizaciones/riesgo · backups B2 · gauge de salud) y timeline de actividad
- Hub empuja `portal_cache` a Care tras cada ciclo de actualización (historial, riesgo, health delta, estado B2)
- Care `/config` acepta y almacena `portal_cache` (datos precalculados, carga instantánea sin llamadas externas)
- Widget del dashboard: enlace actualizado a "Ver mi panel completo →" apuntando al portal
- Hub: 2.2.0 · Care: 1.11.0

## [1.10.0]

- Backups unificados en Backblaze B2 para todos los planes (semilla, raíz, ecosistema)
- Risk Scorer con Claude AI: evalúa el changelog de cada plugin antes de actualizar, bloquea auto-update si riesgo > 0.6
- Delta Reporter: captura snapshots SA + métricas antes/después de cada ciclo de actualización
- SmartUpdates: backup pre-update dispara Care REST `/run?task=backup` en lugar de Backuply
- Rollback post-update integrado con WP Toolkit, alerta admin con referencia B2 si no disponible
- Care `/config` acepta credenciales B2 desde Hub (`b2_key_id`, `b2_app_key`, `b2_bucket_id`, `b2_bucket_name`)
- Hub: 2.1.0 · Care: 1.10.0

## [1.9.0]

- Mejoras generales de estabilidad y compatibilidad con WordPress 6.7
- Sistema de reportes rediseñado con métricas de salud del sitio
- Integración Cloudflare mejorada con purgado selectivo de caché
- Dashboard widget actualizado con gradientes por plan

## [1.8.x]

- Escaneo de seguridad ampliado con detección de cambios en archivos críticos
- Detección de anomalías de tráfico (plan Ecosistema)
- Task-anomaly: alertas configurables por umbral

## [1.7.2]

- Fix: REST ping/auth con try/catch para evitar bloqueos en sites sin permalink amigables
- Mejora: token Hub con validez 1 año y regeneración AJAX desde admin

## [1.7.1]

- Fix: regeneración de token Hub disponible desde el panel sin necesidad de reinstalar
- Fix: token con validez configurada a 1 año (antes expiraba en 24h)

## [1.7.0]

- Backup automático antes de aplicar actualizaciones
- Rollback automático si el health check post-actualización falla
- Integración con Backuply para backups gestionados

## [1.6.0]

- Migración de WP Cron a Action Scheduler para mayor fiabilidad en sites con tráfico bajo
- Las tareas ya no dependen de visitas para ejecutarse

## [1.5.0]

- Sistema de auto-actualización GitHub unificado con Replanta Hub
- Repo y branch configurables via constantes (RPCARE_GITHUB_REPO_URL, RPCARE_GITHUB_BRANCH)
- Token GitHub con prioridad: opción WP > constante > variable de entorno
- Manejo robusto de errores del update checker

## [1.2.5]

- Dashboard widget premium rediseñado
- Iconos SVG en toda la UI
- Integración con Backuply para copias de seguridad
- Sincronización silenciosa con Hub cada 6 horas
- Métricas: última copia, última actualización, salud del sitio, seguridad

## [1.0.x]

- Versión inicial pública
- Actualizaciones automáticas vía GitHub (Plugin Update Checker)
- Tareas de health check, seguridad y reportes básicos
- Integración inicial con Replanta Hub
