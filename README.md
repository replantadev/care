# Replanta Care

Plugin de mantenimiento WordPress automático para clientes de Replanta. Gestiona actualizaciones, backups, seguridad y reportes en segundo plano sin afectar al rendimiento del sitio.

**Versión:** 1.10.0 | **Requiere:** WordPress 6.0+, PHP 7.4+ | **Probado hasta:** WordPress 6.7

---

## Qué hace

Replanta Care instala un agente de mantenimiento en el sitio del cliente. Todas las tareas se ejecutan vía **Action Scheduler** y se orquestan desde **Replanta Hub** (panel central de gestión).

| Área | Qué automatiza |
|---|---|
| Actualizaciones | WordPress core, plugins y temas — con evaluación de riesgo (Claude AI), backup previo y rollback |
| Backups | Backblaze B2 — copia externa cifrada, frecuencia según plan |
| Seguridad | Escaneo de vulnerabilidades, cambios en archivos críticos, logins fallidos |
| Rendimiento | Core Web Vitals, WPO, caché, optimización de base de datos |
| Contenido | Medios huérfanos, errores 404, SEO básico |
| Reportes | Informe mensual al cliente con métricas antes/después de cada ciclo |
| Integraciones | Cloudflare, Replanta Hub, Backblaze B2 |

## Planes

| Plan | Descripción |
|---|---|
| **Semilla** | Actualizaciones + health check + backup semanal + reporte mensual |
| **Raíz** | Semilla + seguridad + WPO + 404 + backup diario + SSL monitoring |
| **Ecosistema** | Raíz + CWV + anomalías + staging + backup dual + scoring AI de updates |

Modificador disponible: **Ecommerce** — backup cada 12h, monitoreo de checkout, ventana de actualización fuera de hora pico.

## Instalación

```bash
# Vía git (entornos con SSH)
cd wp-content/plugins/
git clone https://github.com/replantadev/care.git replanta-care
cd replanta-care && composer install --no-dev
```

O bien: descarga el ZIP desde el último [GitHub Release](https://github.com/replantadev/care/releases) e instálalo desde **Plugins > Añadir nuevo > Subir plugin**.

## Constantes wp-config.php

| Constante | Descripción |
|---|---|
| `RPCARE_LICENSE_KEY` | License key del plan contratado |
| `RPCARE_HUB_URL` | URL del Replanta Hub |
| `RPCARE_HUB_TOKEN` | Token de autenticación con el Hub |
| `RPCARE_GITHUB_REPO_URL` | URL del repo de auto-actualizaciones |
| `RPCARE_GITHUB_BRANCH` | Branch para auto-update (default: `main`) |

## Documentación

Documentación completa en: **[replantadev.github.io/care-docs](https://replantadev.github.io/care-docs/)**

## Changelog

### v1.10.0

- Backups unificados en Backblaze B2 — cobertura total para todos los planes
- Evaluación de riesgo de actualizaciones con Claude AI (Risk Scorer) — bloqueo automático si riesgo > 0.6
- Delta Reporter: snapshots antes/después de cada ciclo, tendencia mensual de SA score
- SmartUpdates: backup pre-update vía Care REST, rollback integrado con WP Toolkit
- Care `/config` acepta credenciales B2 desde Hub (`push_config_to_care`)

### v1.9.0

- Mejoras de estabilidad y compatibilidad con WordPress 6.7
- Sistema de reportes rediseñado con métricas de salud del sitio
- Integración Cloudflare mejorada con purgado selectivo de caché
- Dashboard widget actualizado con gradientes por plan

### v1.8.x

- Escaneo de seguridad ampliado con detección de cambios en archivos críticos
- Detección de anomalías de tráfico (plan Ecosistema)

### v1.7.x

- Backup automático antes de aplicar actualizaciones
- Rollback automático si el health check post-actualización falla
- Token Hub con validez 1 año y regeneración AJAX desde admin

### v1.6.0

- Migración de WP Cron a Action Scheduler

### v1.5.0

- Sistema de auto-actualización GitHub unificado con Replanta Hub

### v1.0.x — v1.2.5

- Versión inicial pública, widget premium, integración inicial Hub + Backuply

---

**Web:** [replanta.net/plugins](https://replanta.net/plugins) | **Contacto:** info@replanta.dev
