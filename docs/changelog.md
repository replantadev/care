---
title: Changelog
layout: default
---

# Changelog

## [1.9.0]

- Mejoras generales de estabilidad y compatibilidad con WordPress 6.7
- Sistema de reportes rediseniado con metricas de salud del sitio
- Integracion Cloudflare mejorada con purgado selectivo de cache
- Dashboard widget actualizado con gradientes por plan

## [1.8.x]

- Escaneo de seguridad ampliado con deteccion de cambios en archivos criticos
- Deteccion de anomalias de trafico (plan Ecosistema)
- Task-anomaly: alertas configurables por umbral

## [1.7.2]

- Fix: REST ping/auth con try/catch para evitar bloqueos en sites sin permalink amigables
- Mejora: token Hub con validez 1 ano y regeneracion AJAX desde admin

## [1.7.1]

- Fix: regeneracion de token Hub disponible desde el panel sin necesidad de reinstalar
- Fix: token con validez configurada a 1 ano (antes expiraba en 24h)

## [1.7.0]

- Backup automatico antes de aplicar actualizaciones
- Rollback automatico si el health check post-actualizacion falla
- Integracion con Backuply para backups gestionados

## [1.6.0]

- Migracion de WP Cron a Action Scheduler para mayor fiabilidad en sites con trafico bajo
- Las tareas ya no dependen de visitas para ejecutarse

## [1.5.0]

- Sistema de auto-actualizacion GitHub unificado con Replanta Hub
- Repo y branch configurables via constantes (RPCARE_GITHUB_REPO_URL, RPCARE_GITHUB_BRANCH)
- Token GitHub con prioridad: opcion WP > constante > variable de entorno
- Manejo robusto de errores del update checker

## [1.2.5]

- Dashboard widget premium rediseniado
- Iconos SVG en toda la UI
- Integracion con Backuply para copias de seguridad
- Sincronizacion silenciosa con Hub cada 6 horas
- Metricas: ultima copia, ultima actualizacion, salud del sitio, seguridad

## [1.0.x]

- Version inicial publica
- Actualizaciones automaticas via GitHub (Plugin Update Checker)
- Tareas de health check, seguridad y reportes basicos
- Integracion inicial con Replanta Hub
