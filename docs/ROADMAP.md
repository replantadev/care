---
title: Hoja de ruta
layout: default
---

# Hoja de ruta de Replanta Care

Este documento recoge capacidades previstas. No debe utilizarse para declarar una
funcion disponible en produccion hasta que su criterio de finalizacion este
completo y exista una release desplegable y validada.

## Impulso Ecommerce: Woo Autonomous Maintenance

**Estado:** Capa 1 implementada en Care v1.15.74 + PC v1.1.88 — pendiente E2E con WordPress/WooCommerce reales y los REST endpoints de Care que exponen los datos al Plugin Center  
**Prioridad:** alta  
**Componentes:** Replanta Care, Plugin Center, pipeline staging-first y, cuando
proceda, WP Toolkit Bridge.

Objetivo: convertir Impulso Ecommerce en una capa de mantenimiento WooCommerce
continua, observable y gobernada desde Plugin Center. Care detectara degradaciones,
aplicara automaticamente solo reparaciones de bajo riesgo y utilizara staging,
backup verificado y aprobacion del operador para cualquier cambio sensible.

### 1. Politicas WooCommerce desde Plugin Center

- Inventario versionado de opciones sensibles, capacidades y estado operativo.
- Politicas deseadas por sitio/grupo con modos `observe`, `enforce_safe` y
  `approval_required`.
- Clasificacion explicita de cada opcion: informativa, autocorregible, sensible o
  prohibida para automatizacion.
- Deteccion de drift con valor observado, valor esperado, origen de la politica,
  fecha, evidencia y resultado de la ultima correccion.
- Ordenes firmadas, idempotentes y auditables; Care no aceptara una lista arbitraria
  de nombres de opciones ni ejecutara escrituras genericas en `wp_options`.
- Los ajustes de pagos, impuestos, moneda, stock, pedidos, cuentas, privacidad,
  checkout o integraciones comerciales requeriran staging y aprobacion humana.

### 2. Monitor de integridad de rutas

- Matriz de URLs criticas: home, pagina, tienda, categoria, producto, carrito,
  checkout, mi cuenta y REST API; repetida por idioma cuando exista WPML,
  Polylang u otro proveedor soportado.
- Comparacion entre respuesta publica/cacheada y origen o peticion con bypass de
  cache cuando la infraestructura lo permita.
- Registro de estado HTTP, redirecciones, URL final, canonical, idioma, firma de
  contenido y cabeceras de cache, sin almacenar contenido personal.
- Ejecucion programada y tambien despues de actualizaciones, clonados, cambios de
  permalink/idioma/URL, purgas y operaciones del proveedor de hosting.
- Deteccion especifica del falso positivo `home=200 desde cache` mientras rutas
  criticas devuelven 404 en origen.

### 3. Snapshots golden y reparacion escalonada

- Snapshot versionado de reglas WordPress, estructura de permalinks, `home_url`,
  `site_url`, configuracion multidioma, servidor/cache detectados y resultados
  esperados de las rutas criticas.
- Snapshot ligado al dominio, entorno, grupo de sitio y fingerprint de
  configuracion; nunca se copian reglas de staging a produccion de forma ciega.
- Escalera de reparacion: observar y alertar; purgar caches y regenerar reglas
  WordPress; probar restauracion en staging; backup verificado; aprobacion;
  restauracion atomica; validacion final; rollback o intervencion manual.
- `.htaccess` solo se modifica si Care tiene permisos, el fingerprint coincide y
  existe copia previa. Reglas Nginx/LiteSpeed externas a WordPress se gestionan
  mediante un proveedor autorizado (por ejemplo WP Toolkit Bridge) o por operador.

### 4. Higiene automatica de WooCommerce

- Limpieza por lotes, con lock y presupuesto temporal, de sesiones y transients
  expirados, logs fuera de retencion y acciones completadas/canceladas antiguas.
- Diagnostico de autoload, tablas y huerfanos mediante reglas conocidas y
  versionadas; modo simulacion y conteo antes/despues.
- Nunca eliminar automaticamente sesiones activas, acciones pendientes/en curso,
  fallos recientes, opciones desconocidas ni metadatos de pedidos, productos o
  clientes sin una regla demostrable.
- Historial de ejecucion, volumen recuperado, filas afectadas, errores y evidencia
  de backup cuando la operacion sea material.

### 5. Captura de errores sin depender de `WP_DEBUG_LOG`

- Telemetria propia de Care con buffer local acotado y envio por lotes a Plugin
  Center. No se activa `WP_DEBUG`, no se muestra informacion al visitante y no se
  cambia la configuracion de logging del cliente.
- Captura de fatales mediante shutdown handler y APIs de recuperacion de WordPress;
  captura encadenada y prudente de errores durante el ciclo en el que Care esta
  cargado; integracion con el logger de WooCommerce y lectura incremental de logs
  accesibles cuando exista autorizacion.
- Diagnosticos activos de base de datos, Action Scheduler, cron, REST y checkout.
  El sistema documentara los limites: no puede observar un fallo anterior a la
  carga de WordPress ni leer logs del servidor sin permisos/proveedor.
- Normalizacion y fingerprint de eventos, deduplicacion, severidad, contador y
  ventana temporal. Redaccion de tokens, cookies, cabeceras, credenciales, PII,
  cuerpos de pedidos y parametros sensibles antes de persistir o transmitir.
- Retencion corta y configurable, limites de tamano/frecuencia, backoff, outbox e
  idempotencia. Plugin Center recibe evidencia estructurada, no volcados completos.

### 6. Deteccion de anomalias de integraciones

- Correlacion de error normalizado, stack, hook/peticion, plugin o clase cercana,
  versiones de WordPress/Woo/PHP/MySQL, Action Scheduler y prueba de checkout.
- Detector especifico para familias como `Commands out of sync`, sin atribuir la
  causa a un plugin unicamente por proximidad temporal.
- Al superar un umbral: alerta, preservacion de evidencia, congelacion de cambios
  de riesgo, reproduccion en staging y prueba de aislamiento quirurgico de la
  integracion sospechosa.
- Care no desactiva automaticamente pasarelas, impuestos, stock, tracking u otras
  integraciones comerciales en produccion. El cambio probado se liga a un lote y
  requiere aprobacion del operador.

### 7. Centro de operaciones en Plugin Center

- Vista por sitio de salud Woo, rutas, checkout, cola Action Scheduler, higiene,
  drift de opciones, errores recientes, integraciones sospechosas y acciones
  pendientes.
- Timeline inmutable: deteccion, evidencia redactada, politica aplicada, comando,
  lote/hash, staging, pruebas, aprobacion, backup, produccion y resultado.
- Controles de pausa global/por sitio, mantenimiento manual, promocion de snapshot
  golden, aprobacion/rechazo y rollback.
- Politicas y valores sensibles sujetos a RBAC, confirmacion reforzada y auditoria.

### 8. Niveles de actuacion

| Nivel | Ejemplos | Ejecucion |
|---|---|---|
| Observar | Drift, error aislado, autoload alto | Telemetria y alerta |
| Auto seguro | Expirados, purga controlada, revalidacion | Care con lock e idempotencia |
| Staging obligatorio | Rewrites, plugins, integraciones, cambios de configuracion | Pipeline staging-first |
| Aprobacion obligatoria | Pagos, impuestos, stock, checkout, produccion | Operador de Replanta Care |
| Emergencia | Checkout caido o rutas criticas rotas | Contencion segura, rollback o intervencion |

### Criterio de finalizacion

- Contrato de politicas versionado y allowlist cerrada; ninguna escritura arbitraria
  de opciones.
- Tests unitarios y de contrato en Care y Plugin Center, tests MariaDB de
  concurrencia y E2E con WordPress/WooCommerce reales en Docker.
- Casos E2E: rutas 404 con home cacheada, drift multidioma, limpieza idempotente,
  `Commands out of sync` sintetico, deduplicacion, PII redaction, fallo de envio,
  aprobacion, rollback y limites de retencion.
- Prueba negativa que demuestre que una opcion sensible nunca se autocorrige en
  produccion sin aprobacion.
- Documentacion para operador y cliente, threat model, runbook de incidentes y
  evidencia reproducible desde volúmenes limpios.
- Piloto primero en laboratorio; despues staging controlado; finalmente un sitio
  real no critico con ventana, backup verificado y plan de rollback.

### Estado de implementacion (Care v1.15.75 + PC v1.1.88)

| Componente | Clase | Estado |
|---|---|---|
| Politicas WooCommerce | `RP_Care_Woo_Policy_Registry` | Implementado — allowlist cerrada de ~60 opciones con AUTOCORRIGIBLE/SENSIBLE/INFORMATIVA; double-safety guard; 16 tests |
| Captura de errores | `RP_Care_Telemetry` | Implementado — shutdown handler, WC logger hook, ring buffer 50/24h, deduplicacion, redaccion PII/hex/Bearer; 14 tests |
| Monitor de rutas | `RP_Care_Route_Monitor` | Implementado — home/shop/cart/checkout/my-account + REST + WPML, falso positivo cache vs origen; 12 tests |
| Snapshot golden | `RP_Care_Golden_Snapshot` | Implementado — 10 campos rastreados, historial MAX_HISTORY=10, escalera de reparacion 8 pasos; 15 tests |
| Higiene WooCommerce | `RP_Care_Woo_Hygiene` | Implementado — sesiones, transients, AS completadas/canceladas, logs WC; lock+budget; dry_run; 10 tests |
| Detector de anomalias | `RP_Care_Integration_Detector` | Implementado — 6 familias, umbral/ventana/escalacion, message_hash no contenido, nunca desactiva pasarelas; 15 tests |
| Vista PC Woo Ops | `ajax_care_woo_health` + JS | Implementado — tab "Woo Ops" en panel per-site de PC; cinco secciones visuales |
| REST endpoints Care | `RP_Care_REST` (Capa 2) | Implementado — 5 endpoints Hub-autenticados bajo `replanta-care/v1`; 40 tests |

### Endpoints REST Capa 2 (replanta-care/v1)

Todos usan metodo POST, autenticacion X-Hub-Token (sha256 del site_token), fail-closed cuando el token no esta configurado.

| Ruta | Handler | Descripcion |
|---|---|---|
| `POST /woo/telemetry` | `hub_woo_telemetry` | Eventos warning+ del buffer de telemetria; max 20 entradas; sin contenido PII |
| `POST /woo/routes` | `hub_woo_routes` | Resultados cacheados del monitor de rutas; nunca hace probe en tiempo real |
| `POST /woo/snapshot` | `hub_woo_snapshot` | Diff del snapshot golden contra estado actual; sin promover ni persistir |
| `POST /woo/hygiene-dry` | `hub_woo_hygiene_dry` | Estimacion de higiene en modo dry_run ESTRICTO — nunca modifica la BD |
| `POST /woo/anomalies` | `hub_woo_anomalies` | Anomalias activas del detector de integraciones; sin contenido de mensaje |

**Envelope de respuesta comun (schema_version=1):**
```json
{ "schema_version": 1, "generated_at": "ISO-8601", ...datos }
```

**Pendiente:**
- Tests E2E con WordPress/WooCommerce reales en Docker.
- Escenarios de laboratorio: rutas 404 con home cacheada, drift multidioma, limpieza idempotente, `Commands out of sync` sintetico, PII redaction, aprobacion, rollback.

## Integracion WP Toolkit

La capa generica de proveedores, la boveda de conexiones, los jobs asincronos, el
Bridge semantico y el proveedor fake estan implementados. Sigue pendiente cerrar el
adaptador real de Plugin Center que invoque el Bridge para cPanel/Plesk y validar el
contrato contra una instalacion real aislada de WP Toolkit. Hasta entonces esta
capacidad se considera implementada parcialmente, no lista para produccion.

