---
title: Tareas de mantenimiento
layout: default
---

# Tareas de mantenimiento

Care ejecuta todas las tareas via **Action Scheduler** (WooCommerce) para garantizar que no afectan al rendimiento del sitio en produccion. Cada tarea es independiente: si una falla, las demas continuan.

## Actualizaciones (`task-updates`)

Gestiona el ciclo completo de actualizaciones de WordPress.

**Flujo:**
1. Detecta actualizaciones disponibles (core, plugins, temas)
2. Crea backup del sitio antes de actualizar
3. Aplica las actualizaciones
4. Ejecuta health check post-update
5. Si el sitio no responde: rollback automatico

Disponible en: Semilla, Raiz, Ecosistema

---

## Health Check (`task-health`)

Revision periodica del estado del sitio: HTTP, BD, PHP, disco, WooCommerce, REST API.

Disponible en: Semilla, Raiz, Ecosistema

---

## Seguridad (`task-security`)

Escaneo de vulnerabilidades e integridad: plugins con CVE, cambios en archivos criticos, admins no reconocidos, logins fallidos, permisos inseguros.

Disponible en: Raiz, Ecosistema

---

## Rendimiento WPO (`task-wpo`)

Limpieza de revisiones, cache de objetos, optimizacion de tablas BD, transients expirados.

Disponible en: Raiz, Ecosistema

---

## Core Web Vitals (`task-cwv`)

Monitorizacion de LCP, CLS e INP. Alerta cuando superan umbrales de Google.

Disponible en: Ecosistema

---

## Errores 404 (`task-404`)

Registro de URLs no encontradas con referrer. Sugerencia de redirecciones.

Disponible en: Raiz, Ecosistema

---

## Anomalias (`task-anomaly`)

Deteccion de picos/caidas de trafico, errores PHP/JS elevados, tasa de errores de formularios.

Disponible en: Ecosistema

---

## Medios huerfanos (`task-orphan-media`)

Lista y limpieza de archivos de media sin adjuntar. Nunca elimina sin confirmacion explicita.

Disponible en: Ecosistema

---

## SEO basico (`task-seo`)

Titulo/desc de home, robots.txt, sitemap, canonicals, links rotos en paginas clave.

Disponible en: Ecosistema

---

## Staging (`task-staging`)

Sincronizacion BD produccion→staging, desactivacion de emails, modo mantenimiento.

Disponible en: Ecosistema

---

## Backup (`integrations-backup`)

Backup externo via Backblaze B2. Frecuencia segun plan: semanal (Semilla), diario (Raiz/Ecosistema), cada 12h (Ecommerce). Backup automatico previo a cada ciclo de actualizaciones. Credenciales configuradas desde Replanta Hub.

---

## Cloudflare (`task-cloudflare`)

Purgado de cache post-actualizacion, estado DNS/SSL, estadisticas via API Cloudflare.

Disponible en: Ecosistema (requiere API token Cloudflare)

---

## Reportes (`task-report`)

Resumen de tareas, actualizaciones aplicadas, incidencias y metricas. Mensual (Semilla), semanal (Raiz/Ecosistema).

Disponible en: Todos

---

[Ver planes y precios](https://replanta.net/mantenimiento-wordpress/#planes) | [Volver al inicio](index.md)
