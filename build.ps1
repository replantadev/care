<#
.SYNOPSIS
  Build y despliegue de Replanta Care.
.DESCRIPTION
  Sin flags  : lint PHP + BOM + ZIP local
  -Deploy    : todo lo anterior + bump/commit/push (dispara GitHub Actions:
               Release en replantadev/care + Hub notify replanta.net) +
               actualiza catalogo sap-woo-suite-info + flush cache
  -Version   : fuerza un numero de version concreto (bump version)
.PARAMETER Deploy
  Activa el modo deploy completo.
.PARAMETER Version
  Numero de version destino (p.ej. 1.15.0). Por defecto lee la version actual.
.PARAMETER Token
  GitHub PAT. Prioridad: RPCARE_GH_TOKEN → SAPWOO_GH_TOKEN → GITHUB_TOKEN
.EXAMPLE
  .\build.ps1
  .\build.ps1 -Deploy
  .\build.ps1 -Deploy -Version 1.15.0
#>

param(
    [switch]$Deploy,
    [string]$Version = '',
    [string]$Token   = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# -- Configuracion -------------------------------------------------------------

$PluginFile  = 'replanta-care.php'
$PluginSlug  = 'replanta-care'
$GhOwner     = 'replantadev'
$PluginRepo  = 'care'
$CatalogRepo = 'sap-woo-suite-info'
$FlushUrl    = $env:REP_FLUSH_URL
$FlushSecret = $env:REP_FLUSH_SECRET

$ZipExcludes = @(
    '.git', '.github', '.gitignore',
    'node_modules',
    'build.ps1', 'docs',
    'phpcs.xml', 'phpstan.neon',
    'composer.json', 'composer.lock',
    'config.php', 'config-sample.php',
    'update-info.json'
)

# -- Helpers -------------------------------------------------------------------

function Write-Step([string]$msg) { Write-Host "`n==> $msg" -ForegroundColor Cyan }
function Write-Ok([string]$msg)   { Write-Host "    [OK] $msg" -ForegroundColor Green }
function Write-Skip([string]$msg) { Write-Host "    [--] $msg" -ForegroundColor Yellow }
function Write-Fail([string]$msg) { Write-Host "    [!!] $msg" -ForegroundColor Red; exit 1 }

# -- 1. Resolver token ---------------------------------------------------------

if (-not $Token) {
    if ($env:RPCARE_GH_TOKEN)      { $Token = $env:RPCARE_GH_TOKEN }
    elseif ($env:SAPWOO_GH_TOKEN)  { $Token = $env:SAPWOO_GH_TOKEN }
    elseif ($env:GITHUB_TOKEN)     { $Token = $env:GITHUB_TOKEN }
}

# -- 2. Leer / validar version -------------------------------------------------

Write-Step "Leyendo version de $PluginFile"
if (-not (Test-Path $PluginFile)) { Write-Fail "No se encuentra $PluginFile" }
$pluginContent = Get-Content $PluginFile -Raw
if ($pluginContent -notmatch 'Version:\s*(\d+\.\d+\.\d+)') {
    Write-Fail "No se puede extraer Version: del header de $PluginFile"
}
$CurrentVersion = $Matches[1]
if (-not $Version) { $Version = $CurrentVersion }
Write-Ok "Version actual: $CurrentVersion  →  destino: $Version"

# -- 3. Bump version (si cambia) -----------------------------------------------

if ($Version -ne $CurrentVersion) {
    Write-Step "Bumping $CurrentVersion → $Version"
    $pluginContent = $pluginContent -replace `
        "(?m)(\s*\*\s*Version:\s*)$([regex]::Escape($CurrentVersion))", "`${1}$Version"
    $pluginContent = $pluginContent -replace `
        "define\s*\(\s*'RPCARE_VERSION'\s*,\s*'$([regex]::Escape($CurrentVersion))'\s*\)", `
        "define('RPCARE_VERSION', '$Version')"
    [System.IO.File]::WriteAllText(
        (Resolve-Path $PluginFile).Path,
        $pluginContent,
        [System.Text.UTF8Encoding]::new($false)
    )
    Write-Ok "$PluginFile actualizado a $Version"
}

$ZipName = "$PluginSlug-$Version.zip"

# -- 4. PHP Lint ---------------------------------------------------------------

Write-Step 'PHP Lint'
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
if ($phpCmd) {
    $phpFiles = Get-ChildItem -Path . -Include '*.php' -Recurse |
        Where-Object { $_.FullName -notmatch '\\(vendor|node_modules|action-scheduler)\\' }
    $lintErrors = 0
    foreach ($f in $phpFiles) {
        $out = & php -l $f.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "    FALLO: $($f.FullName)" -ForegroundColor Red
            Write-Host "    $out" -ForegroundColor Red
            $lintErrors++
        }
    }
    if ($lintErrors -gt 0) { Write-Fail "$lintErrors archivo(s) con errores de sintaxis PHP" }
    Write-Ok "$($phpFiles.Count) archivos PHP sin errores"
} else {
    Write-Skip 'php no encontrado, lint omitido'
}

# -- 5. BOM check --------------------------------------------------------------

Write-Step 'Comprobacion BOM'
$bomFiles = @()
Get-ChildItem -Path . -Include '*.php' -Recurse |
    Where-Object { $_.FullName -notmatch '\\(vendor|node_modules|action-scheduler)\\' } |
    ForEach-Object {
        $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
        if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            $bomFiles += $_.FullName
        }
    }
if ($bomFiles.Count -gt 0) {
    foreach ($f in $bomFiles) { Write-Host "    BOM: $f" -ForegroundColor Red }
    Write-Fail "$($bomFiles.Count) archivo(s) con BOM"
}
Write-Ok 'Sin BOM'

# -- 6. Construir ZIP ----------------------------------------------------------

Write-Step "Construyendo $ZipName"
if (Test-Path $ZipName) { Remove-Item $ZipName -Force }

$tempDir   = Join-Path ([System.IO.Path]::GetTempPath()) "$PluginSlug-build-$(Get-Random)"
$pluginDir = Join-Path $tempDir $PluginSlug
New-Item -ItemType Directory -Force $pluginDir | Out-Null

$sourceItems = Get-ChildItem -Path . -Force |
    Where-Object { $ZipExcludes -notcontains $_.Name -and $_.Name -notlike '*.zip' }

foreach ($item in $sourceItems) {
    if ($item.PSIsContainer) {
        Copy-Item $item.FullName (Join-Path $pluginDir $item.Name) -Recurse -Force
    } else {
        Copy-Item $item.FullName (Join-Path $pluginDir $item.Name) -Force
    }
}

$zipPath = Join-Path (Get-Location) $ZipName
Compress-Archive -Path $pluginDir -DestinationPath $zipPath -CompressionLevel Optimal
Remove-Item $tempDir -Recurse -Force

$zipSize = [math]::Round((Get-Item $ZipName).Length / 1KB, 1)
Write-Ok "$ZipName ($zipSize KB)"

# -- 7. Deploy -----------------------------------------------------------------

if (-not $Deploy) {
    Write-Host "`nBuild completado. Para desplegar: .\build.ps1 -Deploy`n" -ForegroundColor Yellow
    exit 0
}

if (-not $Token) { Write-Fail 'Token GitHub requerido. Define RPCARE_GH_TOKEN, SAPWOO_GH_TOKEN o GITHUB_TOKEN' }

# -- 7a. Actualizar catalogo (sap-woo-suite-info) ------------------------------

Write-Step "Actualizando catalogo ($CatalogRepo)"
$tempCat = Join-Path ([System.IO.Path]::GetTempPath()) "rpcare-cat-$(Get-Random)"
$catUrl  = "https://$Token@github.com/$GhOwner/$CatalogRepo.git"
cmd /c "git clone --depth 1 `"$catUrl`" `"$tempCat`" 2>&1" | Out-Null

if ($LASTEXITCODE -eq 0) {
    $catFile = Join-Path $tempCat 'plugins-landing.html'
    if (Test-Path $catFile) {
        $cat = Get-Content $catFile -Raw -Encoding UTF8
        $cat = $cat -replace '(id="rep-care-ver">v)[\d.]+',    "`${1}$Version"
        $cat = $cat -replace '(id="rep-care-term-ver">)[\d.]+', "`${1}$Version"
        [System.IO.File]::WriteAllText($catFile, $cat, [System.Text.Encoding]::UTF8)

        Push-Location $tempCat
        git config user.email 'build@replanta.dev'
        git config user.name  'Replanta Build'
        git add -A
        if (git status --short) {
            git commit -m "chore: update Care to v$Version in catalog"
            cmd /c "git push origin HEAD 2>&1" | Out-Null
            if ($LASTEXITCODE -eq 0) { Write-Ok 'Catalogo actualizado' }
            else { Write-Skip 'Push catalogo fallo (no critico)' }
        } else {
            Write-Skip 'Sin cambios en el catalogo'
        }
        Pop-Location
    } else {
        Write-Skip 'plugins-landing.html no encontrado en catalogo'
    }
    Remove-Item $tempCat -Recurse -Force
} else {
    Write-Skip "No se pudo clonar $CatalogRepo — omitido"
}

# -- 7b. Git commit + push plugin (dispara GitHub Actions: Release + Hub) ------

Write-Step 'Commit + push del plugin repo'
$gitStatus = git status --short 2>&1
if ($gitStatus) {
    git add -A
    git commit -m "chore: release v$Version"
    Write-Ok "Commit: release v$Version"
} else {
    Write-Skip 'Sin cambios locales pendientes'
}
$pushOut = cmd /c "git push origin HEAD 2>&1"
if ($LASTEXITCODE -ne 0) { Write-Fail "git push fallo: $pushOut" }
Write-Ok 'Push completado — GitHub Actions crea Release y notifica Hub (replanta.net)'

# -- 7c. Flush de cache --------------------------------------------------------

if ($FlushUrl -and $FlushSecret) {
    Write-Step 'Flush de cache replanta.net'
    try {
        $body = @{ secret = $FlushSecret } | ConvertTo-Json
        Invoke-RestMethod -Uri $FlushUrl -Method Post -Body $body `
            -ContentType 'application/json' -TimeoutSec 10 | Out-Null
        Write-Ok 'Cache purgada'
    } catch {
        Write-Skip "Flush omitido: $_"
    }
} else {
    Write-Skip 'Flush omitido (REP_FLUSH_URL / REP_FLUSH_SECRET no definidos)'
}

# -- Resumen -------------------------------------------------------------------

Write-Host ''
Write-Host '============================================================' -ForegroundColor Green
Write-Host "  Replanta Care v$Version desplegado" -ForegroundColor Green
Write-Host '============================================================' -ForegroundColor Green
Write-Host "  ZIP local  : $ZipName"
Write-Host "  GitHub CI  : https://github.com/$GhOwner/$PluginRepo/actions"
Write-Host "  Catalogo   : https://$GhOwner.github.io/$CatalogRepo/plugins-landing.html"
Write-Host ''
