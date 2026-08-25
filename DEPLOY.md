# Deploy pipeline — Replanta Care

Pipeline obligatorio antes de cualquier release. Cada paso es bloqueante.

## Pasos

### 1. Tests

```bash
cd care
./vendor/bin/phpunit tests/
```

Todos los tests deben pasar. No se hace release si hay fallos.

### 2. Verificar vendor limpio

```bash
git status vendor/
# vendor/ NO debe tener archivos "modified" ni "untracked" relevantes.
# Si los hay, restaurar:
git restore vendor/
```

Los archivos `vendor/composer/*.php` commiteados son la única fuente de verdad.
**Nunca** crear el ZIP desde el filesystem local sin antes restaurar vendor.

### 3. Bump de versión

En `replanta-care.php`:
- Header `Version:`
- Constante `RPCARE_VERSION`

Ambos deben coincidir.

```bash
git add replanta-care.php
git commit -m "chore: bump to X.Y.Z"
git push origin main
```

### 4. Generar ZIP desde git archive

**Regla crítica: el ZIP SIEMPRE se genera con `git archive HEAD`, nunca desde el filesystem local.**

Esto garantiza que solo entran archivos commiteados. Los archivos modificados en disco (autoloads de composer, archivos de configuración local) no contaminan el paquete de producción.

```bash
# En el repo local, enviar git archive a Cedro para generar el ZIP en Linux
git archive HEAD --prefix=replanta-care/ | \
  ssh -i ~/.ssh/cedro_deploy replanta@178.105.220.233 \
  "rm -rf /tmp/care-archive-NEW && mkdir /tmp/care-archive-NEW && tar xf - -C /tmp/care-archive-NEW"

# Verificar que no hay dependencias dev en el ZIP
ssh -i ~/.ssh/cedro_deploy replanta@178.105.220.233 \
  "grep -r 'myclabs\|phpunit\|sebastian\|nikic' /tmp/care-archive-NEW/ 2>/dev/null && echo BAD || echo OK"

# Crear ZIP en Linux — IMPORTANTE: hacer cd al directorio padre para que
# replanta-care/ quede en la raíz del ZIP (WordPress lo exige).
# MAL: cd /tmp && zip -r foo.zip care-archive/replanta-care/   ← raíz: care-archive/replanta-care/
# BIEN: cd /tmp/care-archive && zip -r /tmp/foo.zip replanta-care/   ← raíz: replanta-care/
ssh -i ~/.ssh/cedro_deploy replanta@178.105.220.233 \
  "cd /tmp/care-archive-NEW && zip -r /tmp/replanta-care-X.Y.Z.zip replanta-care/"

# Verificar estructura antes de continuar:
ssh -i ~/.ssh/cedro_deploy replanta@178.105.220.233 \
  "unzip -l /tmp/replanta-care-X.Y.Z.zip | head -4"
# Debe mostrar: replanta-care/ como primera entrada (no subcarpeta)
```

### 5. GitHub Release

```bash
# Descargar ZIP de Cedro
scp -i ~/.ssh/cedro_deploy replanta@178.105.220.233:/tmp/replanta-care-X.Y.Z.zip /tmp/

# Crear release (usar keyring token, NO GITHUB_TOKEN de entorno)
cd care
GITHUB_TOKEN="" gh release create vX.Y.Z --title "Replanta Care X.Y.Z" \
  --notes "..." /tmp/replanta-care-X.Y.Z.zip
```

### 6. Actualizar Hub (replanta.net)

```bash
ssh -i ~/.ssh/cedro_deploy replanta@178.105.220.233 "
  sudo -u repla1030 /usr/local/lsws/lsphp83/bin/php /usr/local/bin/wp \
    --path=/home/replanta.net/public_html \
    option update rphub_care_latest_version 'X.Y.Z'
  sudo -u repla1030 /usr/local/lsws/lsphp83/bin/php /usr/local/bin/wp \
    --path=/home/replanta.net/public_html \
    transient delete pc_latest_ver_care 2>/dev/null
"
```

### 7. Verificar desde PC → Sitios

Comprobar en PC admin (replanta.net/wp-admin → Plugin Center → Care → Sites) que:
- La versión "latest" muestra X.Y.Z
- Los sitios registrados pueden descargarse la actualización sin error

---

## Errores conocidos a evitar

| Error | Causa | Prevención |
|-------|-------|-----------|
| Fatal: failed opening vendor/X/file.php | ZIP creado desde filesystem con vendor/ modificado en disco | Siempre `git archive HEAD` (paso 4) |
| 403 al crear release | `GITHUB_TOKEN` env var de Fine-grained PAT sin permiso releases | Usar `GITHUB_TOKEN="" gh release create` |
| "Download failed: Not Found" en PC | GitHub release no existe o no tiene asset ZIP | Verificar `gh release view vX.Y.Z` antes de actualizar Hub |
| Site "already_latest" y no actualiza | Hub tiene versión más baja que la instalada en el site | Hacer release de versión mayor a la instalada |

## Instalación en frío (site sin Care instalado)

El Hub no puede instalar Care en un site que no lo tiene — `remote_action` requiere que Care esté corriendo. Para instalación en frío:

1. Subir ZIP temporalmente a replanta.net: `sudo cp /tmp/replanta-care-X.Y.Z.zip /home/replanta.net/public_html/`
2. En WP Admin del site cliente → Plugins → Añadir nuevo → Subir plugin → URL: `https://replanta.net/replanta-care-X.Y.Z.zip`
3. Instalar + Activar
4. Eliminar ZIP de replanta.net: `sudo rm /home/replanta.net/public_html/replanta-care-X.Y.Z.zip`
5. El Hub retoma el control para actualizaciones futuras
