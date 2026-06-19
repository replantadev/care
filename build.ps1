# =============================================================================
# build.ps1 — Replanta Care deploy pipeline
# =============================================================================
# Uso:
#   .\build.ps1                    # lint + build ZIP (no deploy)
#   .\build.ps1 -Deploy            # lint + ZIP + git + release + docs + landing
#   .\build.ps1 -Deploy -Version 1.10.0   # fuerza version concreta
#
# Requiere:
#   $env:SAPWOO_GH_TOKEN  — Personal Access Token de GitHub (repo + releases)
#   php.exe en PATH       — para lint
# =============================================================================

param(
    [switch]$Deploy,
    [string]$Version = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# ─────────────────────────────────────────────────────────────────────────────
#  Paths
# ─────────────────────────────────────────────────────────────────────────────
$PluginDir   = $PSScriptRoot
$PluginFile  = Join-Path $PluginDir "replanta-care.php"
$UpdateJson  = Join-Path $PluginDir "update-info.json"
$DocsPlugin  = Join-Path $PluginDir "docs"
$DocsLocal   = "c:\Users\programacion2\Local Sites\repos\care-docs"
$LandingFile = "c:\Users\programacion2\Local Sites\sapwoo\app\public\plugins-landing.html"
$BuildTmp    = Join-Path $env:TEMP "replanta-care-build"
$ZipOut      = Join-Path $env:TEMP "replanta-care-RELEASE.zip"

$GhRepo      = "replantadev/care"
$GhDocsRepo  = "replantadev/care-docs"
$GhToken     = $env:SAPWOO_GH_TOKEN

# ─────────────────────────────────────────────────────────────────────────────
#  Helpers
# ─────────────────────────────────────────────────────────────────────────────
function Step { param([string]$msg) Write-Host "`n==> $msg" -ForegroundColor Cyan }
function OK   { param([string]$msg) Write-Host "    OK  $msg" -ForegroundColor Green }
function Warn { param([string]$msg) Write-Host "    WARN $msg" -ForegroundColor Yellow }
function Fail { param([string]$msg) Write-Host "`n    ERR $msg" -ForegroundColor Red; exit 1 }

function GhApi {
    param([string]$Method, [string]$Path, $Body = $null)
    $uri = "https://api.github.com$Path"
    $headers = @{
        Authorization = "token $GhToken"
        Accept        = "application/vnd.github.v3+json"
        "User-Agent"  = "replantadev-build/care"
    }
    $params = @{ Method = $Method; Uri = $uri; Headers = $headers; ContentType = "application/json" }
    if ($null -ne $Body) { $params.Body = ($Body | ConvertTo-Json -Depth 10) }
    try { return Invoke-RestMethod @params }
    catch [System.Net.WebException] {
        $resp = $_.Exception.Response
        $reader = New-Object System.IO.StreamReader($resp.GetResponseStream())
        Fail "GH API $Method $Path => $($resp.StatusCode) $($reader.ReadToEnd())"
    }
}

function GitExec {
    param([string]$WorkDir, [string[]]$GitArgs)
    $result = & git -C $WorkDir @GitArgs
    if ($LASTEXITCODE -ne 0) { Fail "git $GitArgs => exit $LASTEXITCODE" }
    return $result
}

# ─────────────────────────────────────────────────────────────────────────────
#  1. Leer version actual
# ─────────────────────────────────────────────────────────────────────────────
Step "Leyendo version actual"
$phpRaw = Get-Content $PluginFile -Raw
if ($phpRaw -match "define\s*\(\s*'RPCARE_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)") {
    $CurrentVersion = $Matches[1]
} else {
    Fail "No se pudo leer RPCARE_VERSION en $PluginFile"
}
if (-not $Version) { $Version = $CurrentVersion }
OK "Version: $CurrentVersion  →  destino: $Version"

# ─────────────────────────────────────────────────────────────────────────────
#  2. PHP lint
# ─────────────────────────────────────────────────────────────────────────────
Step "PHP lint"
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
$phpBin = if ($phpCmd) { $phpCmd.Source } else { $null }
if (-not $phpBin) { Warn "php no encontrado en PATH - saltando lint" }
else {
    $lintErrors = 0
    Get-ChildItem -Path $PluginDir -Filter "*.php" -Recurse |
        Where-Object { $_.FullName -notmatch '\\vendor\\' } |
        ForEach-Object {
            $out = & php -l $_.FullName 2>&1
            if ($LASTEXITCODE -ne 0) {
                Write-Host "    LINT: $($_.Name)" -ForegroundColor Red
                Write-Host "    $out" -ForegroundColor Red
                $lintErrors++
            }
        }
    if ($lintErrors -gt 0) { Fail "$lintErrors error(es) de sintaxis" }
    OK "Todos los archivos PHP OK"
}

# ─────────────────────────────────────────────────────────────────────────────
#  3. Bump version en plugin file (si cambia)
# ─────────────────────────────────────────────────────────────────────────────
if ($Version -ne $CurrentVersion) {
    Step "Bumping version $CurrentVersion → $Version"
    $phpRaw = $phpRaw -replace "(?m)(^\s*\*\s*Version:\s*)$([regex]::Escape($CurrentVersion))", "`${1}$Version"
    $phpRaw = $phpRaw -replace "define\s*\(\s*'RPCARE_VERSION'\s*,\s*'$([regex]::Escape($CurrentVersion))'\s*\)", "define('RPCARE_VERSION', '$Version')"
    [System.IO.File]::WriteAllText($PluginFile, $phpRaw, [System.Text.UTF8Encoding]::new($false))
    OK "replanta-care.php actualizado"
}

# ─────────────────────────────────────────────────────────────────────────────
#  4. Actualizar update-info.json
# ─────────────────────────────────────────────────────────────────────────────
Step "Actualizando update-info.json"
$updateRaw = Get-Content $UpdateJson -Raw -Encoding UTF8 | ConvertFrom-Json
$updateRaw.version      = $Version
$updateRaw.download_url = "https://sitios.replanta.dev/wp-content/uploads/replanta-updates/replanta-care-$Version.zip"
$jsonOut = $updateRaw | ConvertTo-Json -Depth 5
[System.IO.File]::WriteAllText($UpdateJson, $jsonOut + "`n", [System.Text.UTF8Encoding]::new($false))
OK "update-info.json => $Version"

# ─────────────────────────────────────────────────────────────────────────────
#  5. Actualizar plugins-landing.html (version Care)
# ─────────────────────────────────────────────────────────────────────────────
Step "Actualizando plugins-landing.html"
if (Test-Path $LandingFile) {
    $landing = Get-Content $LandingFile -Raw
    # Subtitle: "Mantenimiento automático WordPress · v1.9.0 · Estable"
    $landing = $landing -replace "Mantenimiento autom&aacute;tico WordPress &middot; v[0-9]+\.[0-9]+\.[0-9]+ &middot; Estable", `
        "Mantenimiento autom&aacute;tico WordPress &middot; v$Version &middot; Estable"
    # Ficha técnica row versión Care (busca el patrón del bloque Care)
    # La fila es: <span class="sv">X.X.X · estable</span> justo después de Replanta Care
    # Hacemos replace del valor en el primer bloque que contiene "Replanta Care" en el featuredName
    # Usamos regex con lookbehind del bloque (más seguro: reemplazamos en contexto Care)
    $careBlock = [regex]::Match($landing, '(?s)<!-- MANTENIMIENTO WORDPRESS.*?<!-- FREE')
    if ($careBlock.Success) {
        $newBlock = $careBlock.Value -replace '<span class="sv">[0-9]+\.[0-9]+\.[0-9]+ &middot; estable</span>', `
            "<span class=""sv"">$Version &middot; estable</span>"
        $landing = $landing.Replace($careBlock.Value, $newBlock)
    }
    [System.IO.File]::WriteAllText($LandingFile, $landing, [System.Text.UTF8Encoding]::new($false))
    OK "plugins-landing.html actualizado a v$Version"
} else {
    Warn "plugins-landing.html no encontrado en $LandingFile"
}

# ─────────────────────────────────────────────────────────────────────────────
#  6. Actualizar version en docs/index.md
# ─────────────────────────────────────────────────────────────────────────────
Step "Actualizando docs/index.md"
$docsIndex = Join-Path $DocsPlugin "index.md"
if (Test-Path $docsIndex) {
    $idx = Get-Content $docsIndex -Raw
    $idx = $idx -replace '\*\*Version:\*\* [0-9]+\.[0-9]+\.[0-9]+', "**Version:** $Version"
    [System.IO.File]::WriteAllText($docsIndex, $idx, [System.Text.UTF8Encoding]::new($false))
    # Mismo update en care-docs local
    $docsLocalIndex = Join-Path $DocsLocal "index.md"
    if (Test-Path $docsLocalIndex) {
        [System.IO.File]::WriteAllText($docsLocalIndex, $idx, [System.Text.UTF8Encoding]::new($false))
    }
    OK "docs/index.md actualizado"
}

# ─────────────────────────────────────────────────────────────────────────────
#  7. Build ZIP
# ─────────────────────────────────────────────────────────────────────────────
Step "Construyendo ZIP"
if (Test-Path $BuildTmp) { Remove-Item $BuildTmp -Recurse -Force }
if (Test-Path $ZipOut)   { Remove-Item $ZipOut -Force }

$exclude = @('build.ps1', '.git', '.gitignore', 'node_modules', '.DS_Store', 'Thumbs.db', '*.zip', 'docs', 'CHANGELOG.md')
$destPlugin = Join-Path $BuildTmp "replanta-care"
New-Item -ItemType Directory -Path $destPlugin -Force | Out-Null

Get-ChildItem -Path $PluginDir -Force |
    Where-Object {
        $name = $_.Name
        -not ($exclude | Where-Object { $name -like $_ })
    } |
    ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $destPlugin -Recurse -Force
    }

Add-Type -AssemblyName System.IO.Compression.FileSystem
[System.IO.Compression.ZipFile]::CreateFromDirectory($BuildTmp, $ZipOut)
$zipSize = [math]::Round((Get-Item $ZipOut).Length / 1KB, 1)
OK "ZIP creado: $ZipOut ($zipSize KB)"

if (-not $Deploy) {
    Write-Host "`n  Build completado (sin deploy). Usa -Deploy para publicar.`n" -ForegroundColor Yellow
    exit 0
}

# ─────────────────────────────────────────────────────────────────────────────
#  8. Git commit + push en plugin repo
# ─────────────────────────────────────────────────────────────────────────────
Step "Git commit en plugin repo"
GitExec $PluginDir @("add", "replanta-care.php", "update-info.json", "CHANGELOG.md", "docs", "build.ps1", "inc", "assets")
$status = & git -C $PluginDir status --porcelain
if ($status) {
    GitExec $PluginDir @("commit", "-m", "v$Version")
    OK "Commit creado"
} else {
    OK "Nada que commitear en plugin repo"
}
GitExec $PluginDir @("push")
OK "Push completado"

# ─────────────────────────────────────────────────────────────────────────────
#  9. Tag + GitHub Release en plugin repo
# ─────────────────────────────────────────────────────────────────────────────
Step "Tag v$Version"
$existingTag = & git -C $PluginDir tag -l "v$Version" 2>&1
if ($existingTag -eq "v$Version") {
    Warn "Tag v$Version ya existe — saltando tag/release"
} else {
    GitExec $PluginDir @("tag", "v$Version")
    GitExec $PluginDir @("push", "origin", "v$Version")
    OK "Tag v$Version publicado"

    Step "GitHub Release v$Version"
    $release = GhApi "POST" "/repos/$GhRepo/releases" @{
        tag_name   = "v$Version"
        name       = "v$Version"
        body       = "Replanta Care v$Version`n`nVer CHANGELOG.md para detalles."
        draft      = $false
        prerelease = $false
    }
    OK "Release creada: $($release.html_url)"

    # Upload ZIP asset
    Step "Subiendo ZIP al release"
    $uploadUrl = $release.upload_url -replace '\{.*\}', ''
    $uploadUrl += "?name=replanta-care-$Version.zip"
    $zipBytes = [System.IO.File]::ReadAllBytes($ZipOut)
    $uploadHeaders = @{
        Authorization  = "token $GhToken"
        Accept         = "application/vnd.github.v3+json"
        "Content-Type" = "application/zip"
        "User-Agent"   = "replantadev-build/care"
    }
    Invoke-RestMethod -Method Post -Uri $uploadUrl -Headers $uploadHeaders -Body $zipBytes | Out-Null
    OK "ZIP subido al release"
}

# ─────────────────────────────────────────────────────────────────────────────
#  10. Sync docs → care-docs repo
# ─────────────────────────────────────────────────────────────────────────────
Step "Sincronizando docs con care-docs"
if (-not (Test-Path $DocsLocal)) {
    Warn "care-docs local no encontrado en $DocsLocal"
} else {
    # Copiar todos los .md del plugin/docs → care-docs repo
    Get-ChildItem -Path $DocsPlugin -Filter "*.md" | ForEach-Object {
        Copy-Item -Path $_.FullName -Destination $DocsLocal -Force
    }

    $docsStatus = & git -C $DocsLocal status --porcelain 2>&1
    if ($docsStatus) {
        GitExec $DocsLocal @("add", "-A")
        GitExec $DocsLocal @("commit", "-m", "docs: sync v$Version")
        GitExec $DocsLocal @("push")
        OK "care-docs actualizado y publicado"
    } else {
        OK "care-docs sin cambios"
    }
}

# ─────────────────────────────────────────────────────────────────────────────
#  11. Commit plugins-landing.html (si hay un repo git para la landing)
# ─────────────────────────────────────────────────────────────────────────────
Step "Comprobando repo landing"
$LandingDir = Split-Path (Split-Path $LandingFile -Parent) -Parent | Split-Path -Parent
& git -C $LandingDir rev-parse --is-inside-work-tree
$isLandingGit = $LASTEXITCODE
if ($isLandingGit -eq 0) {
    $landingStatus = & git -C $LandingDir status --porcelain
    if ($landingStatus | Where-Object { $_ -match 'plugins-landing' }) {
        GitExec $LandingDir @("add", "app/public/plugins-landing.html")
        GitExec $LandingDir @("commit", "-m", "landing: Care v$Version")
        GitExec $LandingDir @("push")
        OK "Landing commiteada y publicada"
    } else {
        OK "Landing sin cambios que commitear"
    }
} else {
    OK "Landing actualizada localmente (no es repo git)"
}

# ─────────────────────────────────────────────────────────────────────────────
Write-Host "`n  Deploy completado: Replanta Care v$Version" -ForegroundColor Green
Write-Host "  Release: https://github.com/$GhRepo/releases/tag/v$Version`n" -ForegroundColor Green
Write-Host "  Docs:    https://replantadev.github.io/care-docs/`n" -ForegroundColor Cyan
exit 0
