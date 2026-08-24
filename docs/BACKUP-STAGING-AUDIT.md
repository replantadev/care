# Auditoría de backups, staging e Impulso Ecommerce

Fecha: 2026-08-24. Este documento distingue capacidades verificadas de trabajo pendiente.

## Contrato operativo

Backup y staging son decisiones independientes. La detección del alojamiento solo puede
recomendar valores; nunca debe iniciar una copia por una deducción ambigua.

| Contexto | Backup recomendado | Staging recomendado |
|---|---|---|
| Laboratorio explícito | Local temporal, con cuota y limpieza | Care emparejado o local |
| Cedro / CyberPanel | B2; R2/S3 cuando tengan adaptador real | Care emparejado |
| WHM/cPanel Replanta | Hosting observado (Backuply/cPanel) o B2 explícito | WP Toolkit si existe y está autorizado; si no, Care emparejado |
| cPanel externo | Hosting observado (JetBackup/Backuply/cPanel UAPI) o remoto explícito | WP Toolkit si existe y está autorizado; si no, Care emparejado/manual |

Regla de seguridad: fuera de `local_dev`, Care no conserva backups en
`wp-content/uploads`. Si no hay un backup verificable compatible con el Pipeline, la
actualización se bloquea; no existe fallback local silencioso.

## Estado de proveedores

- B2: implementado. Debe usar área temporal fuera de uploads y limpieza `finally`.
- Hosting observado: solo lectura. No debe disparar cron de Backuply ni generar archivos.
- cPanel UAPI: pendiente adaptador de evidencia mediante `Backup/list_backups` y, donde
  esté habilitado, APIs de Restore. Requiere credencial de cuenta/reseller, no root.
- JetBackup 5: pendiente adaptador de evidencia. No asumir acceso a su API hasta probar
  permisos del reseller/usuario en un hosting real.
- R2 y S3: pendientes. No son compatibles con el protocolo B2 y deben fallar cerrados
  hasta tener firma, endpoint, listado, verificación y restore propios.
- UpdraftPlus: pendiente exigir destino remoto configurado y evidencia de finalización;
  una tarea asíncrona iniciada no equivale a backup verificable.
- WP Toolkit: staging separado del backup. La CLI requiere permisos del panel; detectar
  un binario desde PHP no demuestra autorización para clonarlo o sincronizarlo.

## Auditoría de Impulso Ecommerce

Contrato comercial actual: backups cada 12 h con retención 90 días, staging obligatorio,
monitorización de carrito/checkout/pasarela, ventanas fuera de horas pico y SLA crítico de
checkout de 30 minutos.

| Capacidad | Estado | Pendiente |
|---|---|---|
| Frecuencia 12 h | Parcial | El scheduler la aplica, pero debe respetar el modo de backup y mostrar evidencia del proveedor. |
| Retención 90 días | Implementado en 1.16.8 | La limpieza B2 usa el máximo entre plan/opción y la retención del addon. Pendiente E2E contra un bucket real. |
| Staging obligatorio | Implementado en 1.16.8/PC 1.2.13 | PC proyecta la política a producción y stagings y Care la muestra localmente. Pendiente validación en vivo. |
| Checkout/pasarela | Parcial | Comprueba páginas HTTP, REST y que haya una pasarela activa; no valida una sesión real carrito → confirmación ni el flujo de pago. |
| Ventana fuera de pico | Parcial | Analiza pedidos clásicos en `wp_posts`; falta compatibilidad HPOS y coordinación como fuente única con la ventana de PC. |
| Alerta/SLA 30 min | Parcial | Dos fallos a intervalos de 15 min generan alerta al Hub; falta medir acuse, escalado y tiempo real de respuesta. |
| Woo Ops | Parcial | Endpoints y vista existen; faltan gates E2E reales y evidencia operativa continuada antes de declararlo autónomo. |

## TODO ordenado

1. Validar en vivo la proyección PC → Care y las coberturas visibles de `Impulso Ecommerce`.
2. Añadir adaptador cPanel UAPI de solo lectura y probarlo con el reseller real.
3. Añadir adaptador JetBackup/Backuply de evidencia sin crear copias.
4. Implementar R2 y S3 como proveedores distintos con tests de restore; hasta entonces bloquearlos.
5. Hacer preflight de disco/cuota para toda área temporal y barrido seguro de restos de ejecuciones abortadas.
6. Validar WP Toolkit en cPanel/Plesk real con el usuario efectivo de PHP; no basta detectar el binario.
7. Actualizar checkout monitor para HPOS y una prueba sintética segura que nunca cobre ni envíe emails.
8. E2E completo: backup verificable → staging → tests → aprobación → producción → rollback.
