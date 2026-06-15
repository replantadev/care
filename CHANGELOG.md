# Changelog — Replanta Care

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
