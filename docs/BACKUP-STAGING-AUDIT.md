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
PC añade carga fragmentada y pasa 377 tests. La publicación y validación en vivo
de PC 1.2.26 queda pendiente. El ZIP de Care pasó reconstrucción
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
