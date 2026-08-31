# Auditoría de backups, staging e Impulso Ecommerce

Fecha de revisión: 2026-08-28. Este documento distingue capacidades verificadas,
declaradas y pendientes. Es la fuente fresca de decisiones para el piloto
`dev.banbancosmetics.com` → `dev2.banbancosmetics.com`.

## Veredicto actual del piloto

El diseño central falla cerrado y el canal Pipeline emparejado está operativo,
pero el piloto todavía no puede ejecutar un lote real. Estado medido el 28-08-2026:

| Instancia | Care | Pipeline | Último poll | Backup |
|---|---:|---|---|---|
| `dev.banbancosmetics.com` | 1.16.22 | activo, programado, 0 fallos Pipeline | reciente | `managed_by_host`, sin evidencia, no utilizable |
| `dev2.banbancosmetics.com` | 1.16.22 | activo, programado, 0 fallos Pipeline | reciente | staging emparejado; no se usa como prueba de backup de producción |

Bloqueo técnico confirmado por PC: `backup_not_usable@production`. Además no hay
un candidato ejecutable con la política actual: Astra Pro es `medium` y no expone
paquete; Elementor Pro es `high` aunque sí expone paquete. Con máximo `low` hay
cero actualizaciones elegibles. El inventario de staging puede ser `null/stale`
sin bloquear la selección, porque el manifiesto se congela desde producción.

### Cierre de los hallazgos P0 revisados

| Área | Estado vivo |
|---|---|
| Pairing, roles y URL de staging | Verificado |
| Executor Care y auto-updates nativos | Verificado, sin drift |
| Pull/poller en producción y staging | Verificado, reciente y autorrecuperable |
| Versiones Care | 1.16.22 en ambas instancias |
| Backup canónico por proveedor | Verificado; falla cerrado sin evidencia |
| Elegibilidad por paquete/riesgo | Verificado server-side; 0 elegibles actualmente |
| Release reproducible | Verificado en CI con ZIP smoke y License API |

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

Para el piloto Banban se adopta:

- tipo de hosting: cPanel externo;
- backup de producción: B2 verificable por sitio;
- staging actual: sitio Care emparejado (`dev2`);
- WP Toolkit: presente en el panel según confirmación del operador, pero no
  detectable desde PHP/WordPress y todavía no autorizado ni validado como executor;
- riesgo máximo: `low`, lote máximo: 1 y aprobación humana obligatoria.

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

## Hallazgos de la revisión 2026-08-26

### P0 — fiabilidad del Pipeline

1. **El poller no se autorrecupera en un staging sin plan.** Los callbacks se
   registran sin licencia, pero la reconciliación del schedule sigue dentro del
   scheduler condicionado por plan. Si Action Scheduler pierde la recurrencia,
   el staging queda permanentemente sin pull hasta volver a guardar la configuración.
2. **La acción “Configurar ambos Care” no actualiza Care.** Puede recuperar el
   schedule y el heartbeat, pero deja staging en una versión antigua y el gate
   `care_outdated` continúa activo. La UI debe ofrecer actualización/reparación
   explícita de cada instancia emparejada.
3. **WP Toolkit tiene tres estados distintos:** declarado por el operador,
   detectable desde WordPress y validado con credenciales/permisos reales. Care solo
   puede autodetectar el segundo. Plugin Center deberá guardar la declaración y la
   prueba de capacidades; nunca inferir permisos a partir del nombre del hosting.

### P0 — backups y rollback

4. **`managed_by_host` es observación, no garantía de rollback.** Puede detectar
   una copia reciente, pero no debe producir `backup_usable=true` sin identificador,
   integridad y restauración probada.
5. **La interfaz de proveedores existe pero no gobierna el Pipeline.** El flujo
   de actualización solo acepta B2 síncrono o snapshot local de laboratorio; los
   adaptadores observados de Backuply/hosting no están conectados al contrato de
   backup del lote.
6. **El estado de backup no es canónico por proveedor.** `/ping` y
   `/smart-updates/status` priorizan la opción histórica B2 incluso cuando el modo
   efectivo es `managed_by_host`, mezclando proveedor actual con evidencia antigua.
7. **El badge B2 de Operaciones es global.** Indica que Plugin Center tiene una
   master key B2, no que el site tenga credenciales ni una copia B2 utilizable.
   Debe sustituirse por estado por sitio/proveedor.
8. **R2/S3 continúan como placeholders fail-closed.** Para el primer piloto se
   utilizará B2. La compra de capacidad no equivale a configuración: hacen falta
   bucket, application key limitada, prueba de conexión, subida, listado y restore.

### P0 — selección de actualizaciones

9. **“Preparable” no significa “elegible”.** La UI muestra paquete disponible,
   pero no el riesgo ni todas las razones de política. Con máximo `low`, el
   inventario actual no ofrece candidato: Advanced Database Cleaner y Elementor
   son saltos mayores; Astra es menor y además no expone paquete.
10. **El contador superior puede quedar visualmente obsoleto** tras refrescar el
    inventario porque se actualiza la caché/estado JavaScript, pero no se repinta la
    fila hasta una recarga.

### P1 — release y mantenibilidad

11. **Documentación y CI discrepan.** `DEPLOY.md` exige `git archive`; el workflow
    construye mediante `rsync`. Debe existir un único proceso reproducible.
12. **El workflow usa `curl -k`** para License API, desactivando la validación TLS.
13. **La metadata declara WordPress probado hasta 6.8**, mientras el piloto usa
    WordPress 7.0.4.
14. **El vendor local de desarrollo está contaminado.** PHPUnit local no arranca;
    CI sí pasó 475 tests/1188 assertions. Las dependencias de test deben instalarse
    exclusivamente en `.vendor-dev` y el ZIP debe construirse desde un árbol limpio.

## Auditoría de Impulso Ecommerce

Contrato comercial actual: backups cada 12 h con retención 90 días, staging obligatorio,
monitorización de carrito/checkout/pasarela, ventanas fuera de horas pico y SLA crítico de
checkout de 30 minutos.

| Capacidad | Estado | Pendiente |
|---|---|---|
| Frecuencia 12 h | Parcial | El scheduler la aplica, pero debe respetar el modo de backup y mostrar evidencia del proveedor. |
| Retención 90 días | Implementado en 1.16.8 | La limpieza B2 usa el máximo entre plan/opción y la retención del addon. Pendiente E2E contra un bucket real. |
| Staging obligatorio | Verificado en vivo | PC proyecta la política a producción y staging; roles, pull y ausencia de drift confirmados en Banban. |
| Checkout/pasarela | Parcial | Comprueba páginas HTTP, REST y que haya una pasarela activa; no valida una sesión real carrito → confirmación ni el flujo de pago. |
| Ventana fuera de pico | Parcial | Analiza pedidos clásicos en `wp_posts`; falta compatibilidad HPOS y coordinación como fuente única con la ventana de PC. |
| Alerta/SLA 30 min | Parcial | Dos fallos a intervalos de 15 min generan alerta al Hub; falta medir acuse, escalado y tiempo real de respuesta. |
| Woo Ops | Parcial | Endpoints y vista existen; faltan gates E2E reales y evidencia operativa continuada antes de declararlo autónomo. |

## TODO ordenado por sprint

### Sprint 1 — recuperar y sostener el canal pull

- [x] Reconciliar poll/outbox en cada `init` cuando Pipeline esté habilitado,
  independientemente del plan.
- [x] Añadir tests de autorrecuperación, idempotencia y ausencia de duplicados.
- [x] Añadir acción PC para actualizar/reparar Care de staging emparejado.
- [x] Actualizar `dev2` y demostrar heartbeat reciente sin crear comandos de lote.

### Sprint 2 — backup canónico por sitio

- [x] Emitir proveedor, modo, configuración, evidencia, integridad y capacidad de
  restore en un único contrato Care.
- [x] No reutilizar evidencia B2 antigua cuando el modo efectivo sea otro.
- [x] Sustituir el badge B2 global por estado real del site.
- [ ] Conectar el contrato `RP_Care_Backup_Provider` al backup/rollback del Pipeline.
- [ ] Configurar B2 para Banban con credencial limitada y completar connection test.

### Sprint 3 — elegibilidad antes de preparar artefactos

- [x] Compartir una sola evaluación server-side de paquete, tipo, exclusiones,
  pin y riesgo.
- [x] Mostrar riesgo, elegibilidad y razones en el inventario.
- [x] Bloquear también la preparación del ZIP cuando la política lo rechaza.
- [x] Repintar el contador de Operaciones tras un inventario vivo.

### Sprint 4 — release reproducible y seguro

- [x] Construir desde `git archive HEAD` y regenerar vendor `--no-dev` en árbol temporal.
- [x] Instalar PHPUnit en `.vendor-dev`; nunca modificar vendor de producción.
- [x] Añadir smoke del ZIP: raíz, autoload, dependencias prohibidas, lint y versión.
- [x] Eliminar `curl -k` y actualizar `tested_wp`.
- [x] Alinear `DEPLOY.md` con el workflow único.

Los elementos marcados `[x]` están implementados, cubiertos y aceptados para
Care 1.16.23 / Plugin Center 1.2.26. Care pasa 486 tests y ya está publicado;
PC añade carga fragmentada, pasa 377 tests y su endpoint vivo confirma 1.2.26.
El ZIP de Care pasó reconstrucción
`vendor --no-dev`, smoke, publicación y registro en License API.

### P0 que queda para el primer lote

- [ ] Configurar en producción un perfil B2 con key limitada al bucket/prefijo.
- [ ] Superar connection test, crear una copia completa y obtener `backup_id` + artefactos.
- [ ] Verificar listado/integridad y realizar una prueba de restauración controlada.
- [ ] Seleccionar una actualización `low` con paquete exportable; no elevar el
  riesgo solo para forzar el piloto.
- [ ] Ejecutar el E2E completo de staging, pruebas, aprobación, producción y rollback.

### Inventario observado en Banban — 28-08-2026

- 31 plugins instalados: 24 activos y 7 inactivos.
- En Care 1.16.22, 13 mostraban la etiqueta invertida
  `Gestionado por Replanta`. Care 1.16.23 la sustituye por
  `Supervisado por Replanta` para todos: describe inventario/monitorización, no
  promete elegibilidad ni actualización automática.
- WordPress muestra 3 actualizaciones: Advanced Database Cleaner PRO, Astra Pro
  y Elementor Pro.
- PC mostraba 2 porque Advanced Database Cleaner PRO no aparece en absoluto en
  el transient estándar: su aviso lo genera el actualizador propio del proveedor.
  Care 1.16.23 expone además el inventario completo instalado; PC 1.2.25 permite
  aportar el ZIP oficial contra su `plugin_file` exacto, sin coincidencias
  ambiguas por nombre o slug.

### Feature apuntado — ZIP premium aportado desde PC

- [x] Permitir que un administrador aporte el ZIP oficial al preparar un plugin
  sin `package` en el transient.
- [x] Reutilizar `PC_Artifact_Store::store_uploaded_bytes()` y conservar el ZIP
  fuera del webroot, con SHA-256 y deduplicación.
- [x] Validar ZIP antes de aceptarlo: límites de tamaño/descompresión, traversal,
  symlinks, raíz única, cabecera de plugin, `plugin_file`, slug y versión destino.
- [x] Vincular el artefacto a `group_id`, site de producción, `inventory_hash`,
  plugin y versión; registrar procedencia `admin_uploaded_premium` y auditoría.
- [x] Mantener exactamente el mismo SHA-256 para staging y producción; nunca
  volver a descargar ni sustituir el ZIP después de aprobar el lote.
- [x] Añadir expiración/retención, eliminación segura y tests negativos. El ZIP
  manual no puede saltarse exclusiones, pins, riesgo máximo, backup o aprobación.

### Sprints posteriores

- [ ] Validar en vivo la proyección PC → Care y las coberturas de `Impulso Ecommerce`.
- [ ] Añadir adaptador cPanel UAPI de solo lectura y probarlo con el reseller real.
- [ ] Añadir adaptador JetBackup/Backuply de evidencia sin crear copias.
- [ ] Implementar R2 y S3 como proveedores distintos con tests de restore.
- [ ] Hacer preflight de disco/cuota y barrido seguro de temporales abortados.
- [ ] Validar WP Toolkit mediante credenciales/permisos reales del panel.
- [ ] Actualizar checkout monitor para HPOS y prueba sintética sin cobros/emails.
- [ ] E2E: backup verificable → staging → tests → aprobación → producción → rollback.

## Incidente del primer lote — 29-08-2026

El lote `a0ef3258-a29d-4f92-9678-f47dfb2b680a` permaneció toda la noche en
`staging_sync_requested` pese a mostrar heartbeats recientes en producción y
staging. El lote y el ZIP de Astra siguen siendo válidos; no deben recrearse.

Causa de contrato: PC no comprobaba el resultado de `enqueue(prepare_staging)` y
el panel no enlazaba el lote con el estado de su comando. Además, un heartbeat
solo probaba actividad del poller, no que Care consultase con el mismo
`instance_id` que el grupo canónico de PC. Tras un reemparejamiento podía existir
un canal verde hacia una cola distinta.

Corrección Care 1.16.24 / PC 1.2.29:

- [x] Care expone únicamente el SHA-256 de su identidad Pipeline; nunca el UUID.
- [x] PC compara esa huella con la instancia canónica y bloquea el falso verde.
- [x] “Actualizar y reparar ambos Care” reempareja una identidad obsoleta.
- [x] La creación del lote falla de forma explícita si no puede encolar staging.
- [x] Operaciones muestra estado, intentos, fase y caducidad de `prepare_staging`.
- [x] Recuperación idempotente: conserva lote/manifest/ZIP y solo reencola si la
  orden falta, caducó, falló o terminó sin producir la transición esperada.
- [ ] Validar en vivo que el lote #1 pasa a `waiting_manual_staging_refresh`.
- [ ] Refrescar dev2 desde dev mediante WP Toolkit, comprobar aislamiento y
  continuar con la actualización únicamente en staging.

Hotfix Care 1.16.25: el primer intento de reparar la identidad staging reveló
que el guard de webhooks bloqueaba también el callback de emparejamiento hacia
el Hub. Se permite ahora exclusivamente el origen configurado y el namespace
`/wp-json/replanta-pc/v1/pipeline/`; no se añade el dominio a la allowlist global
y el resto de POST externos o rutas del Hub siguen bloqueados.

## Incidente de identidad tras clonación — 2026-08-29

Al clonar `dev.banbancosmetics.com` sobre `dev2.banbancosmetics.com`, WP Toolkit
copió también los tokens del Hub, el UUID Pipeline, la protección de replay y la
cola de salida de producción. Un grupo que seguía completo en PC podía contener
dos instalaciones con la misma identidad lógica.

Corrección en Care 1.16.26 / PC 1.2.30:

- Pipeline muestra **Reparar o reemparejar** incluso en grupos completos.
- PC rota primero el token Hub exclusivo del staging y solicita rotación del UUID.
- Care limpia los command IDs heredados y pone en `dead_letter` los eventos no
  entregados copiados de producción, conservándolos para auditoría.
- PC rechaza cualquier UUID que ya pertenezca a otra URL/rol/grupo y sustituye
  el staging primario del grupo sin borrar la instancia anterior.
- **Eliminar grupo** solo funciona si el grupo nunca tuvo lotes; con lote activo
  o historial obliga a reparar/archivar para no dejar registros huérfanos.

Regla operativa: después de cada refresh/clonado completo de staging se debe
ejecutar **Reparar o reemparejar** antes de reencolar el lote.

### Cierre en vivo del reemparejamiento — 2026-08-29

El rechazo genérico `Pairing rejected by PC` no era un fallo de autorización.
La instrumentación segura de PC 1.2.31/1.2.32 situó el fallo en `staging_link`:
la instalación viva ya estaba marcada como schema `1.7.3`, pero no tenía la
columna `extra_staging_instance_ids` que se había añadido al migrador sin subir
su versión. MySQL rechazaba el enlace y la transacción devolvía el token a
estado no consumido.

Corrección y evidencia:

- [x] PC 1.2.33 / schema 1.8.0 fuerza la migración idempotente y no avanza la
  versión si faltan `extra_staging_instance_ids` o `result_json`.
- [x] Dev2 obtuvo un UUID nuevo (`e622fbd8-...`), rol `staging` y credenciales
  independientes; producción conservó su identidad.
- [x] El grupo `27514146-...` y el lote #1 se conservaron; no se borró historial.
- [x] La orden antigua, caducada y dirigida al UUID clonado quedó intacta para
  auditoría; se creó una nueva `prepare_staging` para el UUID canónico.
- [x] Care 1.16.27 añade `POST /pipeline/poll-now`, autenticado con Hub token;
  PC 1.2.34 lo usa después de reencolar y mantiene la cola durable como fallback.
- [x] El poll inmediato procesó una orden, la marcó `completed` y el lote #1
  avanzó de `staging_sync_requested` a `waiting_manual_staging_refresh`.
- [x] Care 1.16.28 corrige el falso positivo Redis (la clase core
  `WP_Object_Cache` no prueba Redis), reconoce el email sink también para Woo y
  emite `X-Robots-Tag` en respuestas staging.

Gate vivo antes de aplicar Astra en dev2 (cerrado el 2026-08-30):

- [x] Cambiar en el `wp-config.php` de **dev2** el valor efectivo de
  `WP_ENVIRONMENT_TYPE` de `production` a `staging`. Es el único fallo crítico
  actual y no debe relajarse desde un plugin, porque WordPress carga esa
  configuración antes que Care.
- [x] Repetir el informe de aislamiento. El informe autenticado devolvió 10/10
  controles correctos.
- [x] Confirmar el refresh manual y continuar el lote únicamente cuando el
  informe autenticado devolvió `passed=true`.

Observación: al actualizar Care en staging, el backup previo B2 fue bloqueado
por el guard de webhooks para `api003.backblazeb2.com`. La actualización terminó
correctamente, pero debe decidirse por contrato si un staging requiere backup
propio o hereda la garantía del backup verificable de producción; no se debe
ampliar la allowlist global de salida como atajo.

## Cierre del primer lote en staging — 2026-08-30

Resultado vivo: Astra Pro se actualizó **solo en dev2** de 4.10.1 a 4.13.8.
Producción permanece en 4.10.1, no existe aprobación registrada y el lote
`a0ef3258-a29d-4f92-9678-f47dfb2b680a` quedó en `awaiting_approval`, con
severidad `ok` y caducidad de aprobación `2026-09-01 17:09:19 UTC`.

Evidencia final del runner: **13 ok, 0 warning, 0 critical, 2 skipped**. Pasaron
home/login sin errores PHP, REST, base de datos, Action Scheduler, plugins,
WooCommerce, ausencia de gateways live, REST WC, tienda, carrito y supresión de
correo. Los dos skips son loopback de `WP_Site_Health` no disponible y huella
DOM sin baseline; deben evolucionar, pero no ocultan un fallo.

Incidentes descubiertos y cerrados durante el piloto:

- [x] Care 1.16.29/1.16.30 añadió recuperación autenticada de una orden local
  aceptada cuando Action Scheduler no ejecuta el callback, ligada exactamente a
  comando, lote, rol y estado del journal.
- [x] Care 1.16.31 alineó `SORT_STRING`, pero el piloto mostró que duplicar la
  serialización entre runtimes seguía siendo frágil.
- [x] PC 1.2.35–1.2.36 y Care 1.16.32 fijaron el contrato definitivo: PC exporta
  los bytes JSON canónicos que firmó y Care verifica su SHA-256 antes de
  decodificarlos. La variante autenticada y la administrativa quedan cubiertas.
- [x] PC 1.2.37 eliminó salida residual antes de servir ZIPs. El endpoint añadía
  un byte `0a` delante de `PK` y truncaba el último byte por `Content-Length`;
  ahora el ZIP servido conserva 5.010.807 bytes y coincide con el SHA-256
  congelado `865a61f2…19bac`.
- [x] Care 1.16.33 integra la outbox durable en el poll operativo y expone un
  resumen sin payloads, UUIDs ni secretos. Se entregaron 6/6 eventos pendientes.
- [x] PC 1.2.38 trata fallos repetidos como idempotentes y permite que un éxito
  posterior, entregado en orden FIFO, recupere staging hacia tests sin recrear
  lote, manifiesto ni artefacto.
- [x] Care 1.16.34 unifica el criterio del runner ecommerce con el email sink
  que realmente intercepta `wp_mail`; elimina el falso crítico de correo.
- [x] PC 1.2.39 permite repetir solo las pruebas tras remediar runner/config.
- [x] PC 1.2.40 normaliza el sobre de resultado de Care (`severity` exterior y
  suites dentro de `test_report`); las suites obligatorias ya no se interpretan
  falsamente como ausentes.

Suites al cierre: Care **501 tests / 1263 assertions**, PC **388 tests / 1152
assertions**; ambas verdes (skips de entorno documentados). Producción no debe
avanzar hasta una aprobación humana explícita y una nueva comprobación de drift
e inventario.

Pendientes no bloqueantes para el siguiente sprint:

- [ ] Definir el contrato de backup de staging. Hoy B2 está permitido para
  producción, pero el guard bloquea `api003.backblazeb2.com` en dev2. Elegir
  explícitamente entre «sin backup propio; staging es desechable» o un permiso
  B2 limitado por host/ruta; nunca allowlist global.
- [ ] Capturar baseline DOM antes del próximo lote para que la regresión HTML no
  quede en `skipped`.
- [ ] Habilitar/implementar el loopback de Site Health en el runner o sustituirlo
  por una comprobación equivalente auditable.
- [ ] Publicar Plugin Center 1.2.35–1.2.40 mediante su workflow de release. En
  este piloto se desplegó desde el commit validado por SSH porque GitHub Actions
  del repositorio no era consultable, aunque el push sí funcionaba.

## Auditoría posterior a la aprobación — 2026-08-31

La aprobación humana quedó registrada correctamente el 2026-08-30 17:54:18
UTC, pero producción **no se actualizó**: Astra Pro continúa en 4.10.1 y solo
dev2 tiene 4.13.8. La primera orden `report_inventory` agotó tres intentos y
caducó; el heartbeat de producción no dejó una traza operativa suficiente en
la interfaz.

La reproducción controlada descubrió y cerró dos defectos:

- [x] Care 1.16.35 carga explícitamente
  `wp-admin/includes/translation-install.php`. En una petición REST de un sitio
  `es_ES`, `RP_Care_Inventory_Snapshot` llamaba a la función administrativa
  `wp_get_available_translations()` sin que WordPress la hubiese cargado y
  provocaba el error crítico durante `report_inventory`. Build, smoke y release
  oficiales verdes; 502 tests / 1267 assertions. Desplegado en dev y dev2.
- [x] Plugin Center 1.2.41 distingue el retorno del gate de drift del éxito.
  Antes, la transición correcta a `production_drifted` devolvía `true` y el
  receptor la confundía con «sin drift», encolando `prepare_production`. Ahora
  drift devuelve `false`, no se encola preparación y existe una prueba REST de
  regresión. Suite: 389 tests / 1155 assertions. Desplegado en Cedro.

Estado final observado: lote #1 en `production_drifted`, `failure_code` =
`inventory_drift`, sin `production_backup_id` y sin orden de actualización de
producción. La aprobación queda invalidada por diseño y el lote debe reiniciarse
desde un inventario fresco; no se debe reutilizar ni alterar manualmente su hash.

Hallazgo de contrato para el siguiente sprint: el lote antiguo congeló el
`inventory_hash` del endpoint de actualizaciones, mientras `report_inventory`
envía el snapshot completo con otro esquema/hash. Además, el hash de
actualizaciones incluye metadatos transitorios que pueden desaparecer al
caducar el transient. Debe definirse un único `installed_state_hash` estable
(core/PHP, plugins y temas instalados, versiones y activación), compartido por
creación y drift, separado del inventario volátil de actualizaciones.

## Contrato estable y lote #2 — 2026-08-31

- [x] Care 1.16.36 implementó `installed-state-v1` y lo publica como
  `installed_state_hash` tanto en `/updates/inventory` como en
  `report_inventory`. Cubre WordPress, PHP, versiones y activación de plugins y
  temas; excluye transients, paquetes, timestamps, traducciones y el propio
  Care. Un transient ausente no cambia el hash.
- [x] PC 1.2.42 congeló el contrato en manifiesto 1.1 y lo exige para cualquier
  lote `staging_required`. Añadió traza de eventos/órdenes, estado efectivo de
  órdenes caducadas y recuperación limitada del inventario posterior a una
  aprobación todavía vigente.
- [x] Care 1.16.37 hace idempotente un objetivo ya aplicado: dev2 tenía Astra
  4.13.8 y continuó sin descarga ni reinstalación (`already_at_target=true`).
- [x] PC 1.2.43 trata `staging_provider=paired` como transporte manual hacia el
  Care staging ya emparejado y comprueba `WP_Error` antes de invocar cualquier
  proveedor. Se eliminó el fatal `WP_Error::capabilities()`.

El lote #1 quedó `cancelled` con `failure_code=inventory_contract_migrated`; no
se borró su historial. El lote #2 (`56ed79a8-ae68-41ee-b511-241e797ab791`)
congeló el hash estable `a18ddbb…9481`, superó 10/10 controles de aislamiento y
las suites staging (**13 ok, 0 warning, 0 critical, 2 skipped**). Está en
`awaiting_approval` hasta 2026-09-02 07:44:47 UTC. Producción permanece en Astra
4.10.1; no se ha ejecutado ninguna actualización de producción.

## Cierre del piloto end-to-end — 2026-08-31

El lote #2 fue aprobado por Luis Javier Gil a las 08:41:12 UTC. El inventario
fresco de producción devolvió exactamente el `installed_state_hash` congelado
`a18ddbb…9481`, por lo que el gate de drift avanzó legítimamente a backup.

- [x] Backup B2 nuevo y ligado al lote:
  `backup_2026-08-31_08-46-54`.
- [x] La orden `apply_production_batch` incluyó ese mismo `backup_id`, el
  `approval_id` y el `manifest_hash` congelado.
- [x] Astra Pro se actualizó en producción de 4.10.1 a **4.13.8**.
- [x] Verificación posterior: home 200, login 200, REST 200, base de datos OK,
  25 plugins activos y ausencia de errores PHP visibles.
- [x] Lote en estado terminal `completed` a las 08:49:23 UTC.

La verificación de producción terminó con severidad `warning`, no crítica:
detectó 21 acciones antiguas fallidas de Action Scheduler en las últimas 24 h.
No eran acciones del pipeline y el piloto no registró ningún fallo propio, pero
deben seguir siendo visibles para depuración. También quedaron los dos skips ya
conocidos: loopback de Site Health no disponible y baseline DOM sin capturar.

Defecto de auditoría encontrado al cerrar el lote: PC validaba el backup y lo
incluía en la orden de actualización, pero no persistía `production_backup_id`
en `pc_update_batches`. Plugin Center 1.2.44 corrige la transición para guardar
el ID verificado y añade una prueba contractual. PC 1.2.44 quedó desplegado y
activo en Cedro; suite **394/394** (1171 assertions, 7 skips de entorno). El
lote #2 recibió un backfill compare-and-set exacto desde su orden inmutable y
ahora conserva `production_backup_id=backup_2026-08-31_08-46-54`. Se registró
el evento `production_backup_evidence_backfilled`; no se infirió ni reutilizó
otro backup.
