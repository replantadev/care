# Deploy pipeline — Replanta Care

La única ruta normal de release es `.github/workflows/deploy.yml`. Un push a
`main` ejecuta lint, tests, build reproducible, smoke del ZIP, GitHub Release,
License API y actualización del Hub. Todos los pasos son bloqueantes.

## Preparación local

Las dependencias de producción viven en `vendor/`. PHPUnit y sus dependencias
se instalan exclusivamente en `.vendor-dev`:

```bash
COMPOSER_VENDOR_DIR=.vendor-dev composer install --prefer-dist --no-interaction
.vendor-dev/bin/phpunit --configuration phpunit.xml.dist
```

Nunca ejecutar `composer require --dev` contra `vendor/` ni construir un ZIP
desde el working tree local.

## Crear una release

1. Ejecutar tests y lint local.
2. Actualizar en `replanta-care.php` tanto el header `Version:` como
   `RPCARE_VERSION`.
3. Actualizar `CHANGELOG.md` y comprobar `git diff --check`.
4. Commit y push a `main`.
5. Esperar a que `Deploy Replanta Care` finalice en verde.
6. Verificar desde Plugin Center que la versión latest y el asset coinciden.

## Contrato del workflow

### Tests

- PHP 8.1.
- Dependencias dev en `.vendor-dev` mediante `composer install` y lockfile.
- Suite PHPUnit completa.

### Build reproducible

1. `git archive HEAD` crea el árbol fuente inmutable.
2. En ese árbol temporal se ejecuta `composer install --no-dev
   --classmap-authoritative`.
3. Se excluyen documentación, tests, configuración de desarrollo y manifests
   de Composer que no necesita el plugin distribuido.
4. El ZIP contiene exactamente una raíz `replanta-care/`.

### Smoke obligatorio del ZIP

- raíz WordPress correcta;
- ausencia de `tests/`, PHPUnit y dependencias dev;
- `vendor/autoload.php` carga sin fatal;
- fichero principal pasa `php -l`;
- versión interna igual a la versión del asset.

### Distribución

- GitHub Release `vX.Y.Z` con asset `replanta-care-X.Y.Z.zip`;
- subida a License API con TLS verificado;
- registro de metadata en License API;
- notificación al Hub, que refresca su caché y `care-info.json`.

## Reglas de seguridad

- No usar `curl -k`, `--insecure` ni desactivar `sslverify`.
- No publicar desde un árbol con cambios sin commit.
- No incluir credenciales, `config.php`, tests o vendor de desarrollo.
- No declarar una versión `tested_wp` que no esté cubierta por el laboratorio.
- No actualizar clientes hasta que el workflow y el smoke estén verdes.

## Fallos conocidos

| Síntoma | Causa probable | Acción |
|---|---|---|
| Fatal en `vendor/X/file.php` | Autoload contaminado o dependencia ausente | Corregir lock/vendor y publicar una versión superior |
| ZIP instala una carpeta anidada | Raíz incorrecta | El smoke debe bloquear la release |
| PC descarga 404 | Release/asset o Hub desincronizados | Revisar jobs GitHub Release, License API y Hub |
| `already_latest` inesperado | Hub o site anuncian versiones distintas | Comparar Care vivo, License API y `PC_Versions::latest()` |
| Staging sin heartbeat | Poller perdido o Care antiguo | Usar “Actualizar y reparar ambos Care” y verificar schedule |

## Instalación en frío

Un Care no instalado no puede recibir órdenes autenticadas. La instalación
inicial se realiza manualmente con el último ZIP verificado. Tras activarlo y
emparejarlo, Plugin Center retoma las actualizaciones. Cualquier ZIP temporal
subido al servidor debe eliminarse inmediatamente después de instalarlo.
