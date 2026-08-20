# Checklist operativa de Care y Smart Updates

Fecha de control: 2026-08-20  
Objetivo: entregar Care con monitorizacion fiable y actualizaciones inteligentes
staging-first, primero mediante sitios emparejados y despues mediante WP Toolkit,
sin presentar una capacidad como lista antes de validarla en vivo.

## Como se usa

- `[x]` implementado y verificado con la evidencia indicada.
- `[ ]` pendiente o no demostrado.
- Un commit o un test unitario no equivale a un despliegue ni a una prueba E2E.
- Ninguna actualizacion llega a produccion sin backup/rollback util, staging aislado,
  aprobacion exigida por la politica y comprobaciones posteriores.
- `dev.banbancosmetics.com` representa produccion en el piloto y
  `dev2.banbancosmetics.com` es su staging.

## Estado ejecutivo

- Care en repositorio: `1.16.3`.
- Plugin Center en repositorio: `1.2.8`.
- Suite Care: 441 tests, 1092 assertions, 0 fallos, 6 omitidos.
- Suite Plugin Center: 336 tests, 934 assertions, 0 fallos, 7 omitidos.
- Modo prioritario para el primer piloto: `paired`, con dev y dev2 ya existentes.
- WP Toolkit: linea posterior; no bloquea el piloto paired y no esta aceptado en un
  entorno WP Toolkit real.
- Veredicto actual: **piloto bloqueado**. No activar `pipeline_enabled` todavia.

## 1. Release e integridad

- [x] `REL-01` Suite completa de Care verde tras los commits actuales.
  Evidencia: ejecucion local del 2026-08-20, 441/441.
- [x] `REL-02` Suite completa de Plugin Center verde tras los commits actuales.
  Evidencia: ejecucion local del 2026-08-20, 336/336.
- [x] `REL-03` El build usa `git archive` sobre el commit probado y
  `.gitattributes export-ignore`; el `vendor` local modificado por PHPUnit queda
  aislado y no puede entrar en el ZIP.
- [x] `REL-04` Changelog, contrato, cabecera, stable tag e indice coinciden con
  inventario canonico y versiones Care 1.16.3 / PC 1.2.8.
- [x] `REL-05` ZIPs Care 1.16.3 y PC 1.2.8 construidos desde Git, inspeccionados
  sin tests/docs/build ni dependencias dev; hashes SHA-256 registrados en la
  entrega operativa.
- [ ] `REL-06` Desplegar las versiones aprobadas en dev/dev2 y PC en Cedro.
- [ ] `REL-07` Repetir smoke, estado de versiones y contratos despues del
  despliegue.

## 2. Identidad, emparejamiento y vista de Operaciones

- [x] `PAIR-01` PC dispone de grupos e instancias y de relacion
  produccion-staging.
- [x] `PAIR-02` dev y dev2 ya estan emparejados en `Admin -> Pipeline`; esa relacion
  es la fuente de verdad. Usan tokens distintos y el acceso sin autenticacion se
  rechaza segun la evidencia previa.
- [x] `PAIR-03` Operaciones proyecta la relacion canonica de Pipeline. No
  crear, reparar ni volver a emparejar dev2 mediante `pc_care_sites`.
- [x] `PAIR-04` `pc_care_sites` queda relegado al transporte autenticado y cache de
  salud; Pipeline gobierna identidad, grupo y entorno en Operaciones.
- [x] `PAIR-05` Produccion y staging se muestran de forma jerarquica. El staging
  hereda cliente y plan, lleva distintivo `STAGING` y no consume una licencia
  comercial adicional.
- [x] `PAIR-05B` Admin -> Pipeline -> Grupos tambien usa exclusivamente
  `production_instance_id` y `staging_instance_id`; eliminada la creacion legacy
  basada en `pc_care_sites` y en una URL manual.
- [ ] `PAIR-06` Configurar y comprobar `staging_role=production` en dev y
  `staging_role=staging` en dev2.
- [ ] `PAIR-07` Mostrar en PC URL, rol, metodo efectivo, version Care, ultima
  comunicacion y estado de aislamiento de ambas instancias.

## 3. Inventario de actualizaciones

- [x] `INV-01` Care cruza el transient con plugins instalados y separa entradas
  accionables y huerfanas. Commit `6712722`.
- [x] `INV-02` `/ping` usa el total accionable y `/updates/inventory` schema v2
  devuelve `plugins[]` y `orphaned_plugins[]`. Commit `6712722`.
- [x] `INV-03` PC prefiere `updates_pending_total`, conserva compatibilidad con
  Care antiguo y almacena `updates_orphaned`. Commit `14cbab4`.
- [ ] `INV-04` Tras el despliegue, demostrar la igualdad: WP admin = Care = PC = 4
  accionables y 1 huerfano diagnosticado.
- [ ] `INV-05` La UI debe diferenciar `0`, `N`, `sin datos`, `stale` y `error`; nunca
  convertir ausencia/error en "todo al dia".

## 4. Politica y checklist visible en Smart Updates

- [x] `POL-01` Configuracion conservadora del piloto: `staging_required`,
  aprobacion siempre, `care_pipeline`, lote 1 y auto-updates nativos desactivados.
- [x] `POL-02` La pestaña Smart Updates renderiza los datos vivos ya devueltos
  por el backend: Care production/staging, drift, blockers y
  `can_activate_staging_required`.
- [x] `POL-03` Se muestra checklist tecnico por gate con estado y motivo. Quedan
  pendientes los gates operativos de aislamiento, backup y artefactos.
- [ ] `POL-04` Definir una unica resolucion del metodo efectivo entre
  `staging_method`, `staging_provider`, `update_executor` y rol de instancia.
- [x] `POL-05` `staging_required` con metodo `none` se rechaza.
- [x] `POL-06` El guardado falla cerrado si faltan instancias, los roles no son
  validos, Care no responde, hay drift o algun blocker; eliminado el bypass de
  "flujo simplificado".
- [ ] `POL-07` Mantener `pipeline_enabled=false` hasta que todos los gates del
  piloto esten en verde.

## 5. Aislamiento de dev2

- [ ] `ISO-01` `WP_ENVIRONMENT_TYPE=staging` y rol Care `staging`.
- [ ] `ISO-02` `noindex` verificable tanto en cabecera/meta como en robots.
- [ ] `ISO-03` Correo capturado o bloqueado; prueba sin entrega externa.
- [ ] `ISO-04` Pasarelas de pago desactivadas o en sandbox.
- [ ] `ISO-05` Webhooks y APIs salientes bloqueados salvo allowlist explicita.
- [ ] `ISO-06` Cron, cache, sesiones y prefijo/base de datos aislados de dev.
- [ ] `ISO-07` Comprobar que una mutacion sembrada en dev2 no aparece en dev.
- [x] `ISO-08` Care expone un informe de aislamiento de solo lectura, autenticado,
  que rechaza el rol production y conserva el detalle de cada check. El resultado
  unitario no sustituye la prueba real de ISO-01..07.

## 6. Arranque y ejecucion del pipeline paired

- [x] `PIPE-01` Smart Updates permite seleccionar exactamente un plugin, preparar
  su artefacto y crear el lote mediante un caller vivo de
  `create_batch_from_inventory()`.
- [x] `PIPE-02` Comando HMAC production-only obtiene el paquete PRO del inventario
  vivo; PC recibe ZIP + SHA-256, lo verifica y congela. La URL privada nunca sale
  de Care. Pendiente validacion E2E real antes de usarlo en clientes.
- [x] `PIPE-03` El flujo paired mantiene `manual_required` en
  `waiting_manual_staging_refresh`; nunca devolver `staging_ready` sin refresco e
  aislamiento confirmados.
- [x] `PIPE-03B` PC solo confirma el refresco manual tras consultar el informe de
  aislamiento de la instancia staging emparejada; un fallo no avanza el lote y un
  PASS encola una unica aplicacion en dev2.
- [x] `PIPE-04` Seleccion batch=1, reutilizacion idempotente de orden/artefacto y
  estados del lote visibles en PC; cubierto por suites, aun sin piloto real.
- [ ] `PIPE-05` Instalar solo en dev2 y ejecutar smoke/health, rutas criticas,
  WooCommerce y comparacion de snapshot.
- [ ] `PIPE-06` Detenerse en espera de aprobacion humana con evidencia legible.
- [ ] `PIPE-07` Aplicar en dev solo tras aprobacion y repetir comprobaciones.
- [ ] `PIPE-08` Verificar rollback real y conservar auditoria completa.

Primer candidato recomendado para el piloto: WP All Import - WooCommerce Import
Add-On Pro `4.0.0 -> 4.0.6`. Evitar inicialmente Advanced Database Cleaner PRO
(salto mayor), Astra Pro (licencia/compatibilidad) y Elementor Pro (salto mayor).

## 7. Backup y restauracion

- [x] `BKP-01` Estado canonico `complete|partial|failed`; cero artefactos es
  `failed`, no `partial`. Commit `e688a91`.
- [x] `BKP-02` Errores B2 estructurados y endpoint de diagnostico por fases que no
  registra secretos. Commit `e688a91`.
- [x] `BKP-03` Modelo de perfiles PC `replanta_global|replanta_dedicated|customer_managed`,
  secretos write-only y fingerprint. Commit `f1cd4b8`.
- [ ] `BKP-04` Ejecutar el connection test B2 de dev y conservar codigo de error por
  fase; no asumir que el problema es cuota sin evidencia.
- [ ] `BKP-05` Completar asignacion, UI y entrega segura de perfil por sitio. Para
  el piloto, usar perfil dedicado de pruebas o un proveedor S3-compatible gratuito;
  no mezclar con el bucket global lleno.
- [ ] `BKP-06` Definir el gate por metodo: el pipeline paired puede aceptar snapshot
  local completo y verificado para ensayar staging; aplicar en produccion exige un
  rollback considerado suficiente por la politica.
- [ ] `BKP-07` Registrar metodo, artefactos, hash, tamano, fecha, caducidad y prueba
  de restauracion; no confiar solo en `backup_verified=true`.
- [ ] `BKP-08` Restaurar realmente una copia en dev2 y comprobar base de datos,
  plugins, temas, uploads y configuracion.

## 8. Seguridad

- [x] `SEC-01` Eliminado `sslverify=false` de las comprobaciones staging; busqueda
  completa sin coincidencias fuera de dependencias el 2026-08-20.
- [ ] `SEC-02` Migrar endpoints mutables que aun dependen solo de
  `X-Hub-Token` a firma HMAC con timestamp, nonce, anti-replay y rotacion.
- [ ] `SEC-03` Guardar secretos operativos en KeePass, nunca en repositorios,
  argumentos, logs, AJAX o artefactos.
- [ ] `SEC-04` Master key unica por instalacion en `wp-config.php`; PC no la conoce.
- [ ] `SEC-05` Confirmar fail-closed para falta de ZipArchive, secretos, rol,
  aislamiento, backup o conectividad.

## 9. WP Toolkit (despues del paired MVP)

- [ ] `WPT-01` Elegir y documentar una sola arquitectura efectiva: Care local o
  PC->Bridge->WP Toolkit. No mantener dos ejecutores ambiguos.
- [ ] `WPT-02` Corregir la llamada a
  `PC_Bridge_Job_Runner::get_client_for_connection()` o implementar su contrato;
  actualmente puede producir fatal en verificacion de aislamiento.
- [ ] `WPT-03` Tras clonar, enrolar/emparejar de forma segura el Care de staging y
  obtener `staging_instance_id` antes de iniciar actualizaciones.
- [ ] `WPT-04` Conectar restore points de WP Toolkit al gate real de rollback.
- [ ] `WPT-05` Ejecutar E2E en un entorno WP Toolkit aislado: clonar, aislar,
  actualizar, verificar, promover segun politica y restaurar.

## 10. Secuencia inmediata

1. Desplegar Care 1.16.3 en dev y dev2 y PC 1.2.8 en Cedro con ZIPs verificados.
2. Mantener el emparejamiento existente de Pipeline, proyectarlo correctamente en
   Operaciones y confirmar los roles efectivos production/staging.
3. Confirmar conteo canonico 4/4/4 y una entrada huerfana separada.
4. Implementar el checklist visible y los guards fail-closed de politica.
5. Cerrar los siete controles de aislamiento de dev2.
6. Ejecutar diagnostico B2 y decidir perfil dedicado; paralelamente validar el
   snapshot local del pipeline.
7. Implementar la accion de crear lote y el flujo seguro de artefactos PRO.
8. Ejecutar el piloto de un solo plugin hasta staging, detenerse para aprobacion y
   documentar evidencia antes de tocar dev.
9. Completar actualizacion en dev y un simulacro de rollback.
10. Liberar primero Care de monitorizacion/manual seguro; habilitar Smart Updates
    solo en sitios que cumplan todos los gates. Validar WP Toolkit despues.

## Criterio de salida para clientes

Care puede distribuirse en modo monitorizacion/manual cuando inventario, salud,
backups y controles manuales sean fiables. Smart Updates solo se habilita por sitio
cuando el emparejamiento, aislamiento, artefacto, backup/rollback, aprobacion y E2E
esten demostrados. WP Toolkit permanece marcado como experimental hasta superar su
propia prueba real.
