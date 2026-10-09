<#
  Pit Wall v2 - setup for this PC (no admin rights needed)

  .\deploy\setup.ps1                         install / update, compile the data, start everything
  .\deploy\setup.ps1 -Tunnel                 ...and share it through a Cloudflare quick tunnel (random URL)
  .\deploy\setup.ps1 -TunnelToken <token>    ...or through your own named tunnel / domain (token from Cloudflare)
  .\deploy\setup.ps1 -NoTunnel               stop sharing; local only again
  .\deploy\setup.ps1 -Build                  rebuild the web app (after changing anything in src/)
  .\deploy\setup.ps1 -Reload                 reload nginx after a config change
  .\deploy\setup.ps1 -Stop                   stop nginx, the watcher and the tunnel
  .\deploy\setup.ps1 -Uninstall              stop, remove the login entry, nginx and cloudflared

  nginx serves public\app (the web app) and public\data (compiled stats) on http://localhost:8080,
  bound to this PC only. The tunnel, when enabled, is the only way in from outside.
#>
param(
    [switch]$Stop,
    [switch]$Reload,
    [switch]$Uninstall,
    [switch]$Build,
    [switch]$Tunnel,
    [string]$TunnelToken,
    [switch]$NoTunnel,
    [int]$Port = 8080
)

$ErrorActionPreference = 'Stop'
$NginxVersion = '1.30.5'
$Root        = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$AppDir      = Join-Path $Root 'public\app'
$DataDir     = Join-Path $Root 'public\data'
$CacheDir    = Join-Path $Root '.cache'
$Home_       = Join-Path $env:LOCALAPPDATA 'pitwall-nginx'
$NginxExe    = Join-Path $Home_ 'nginx.exe'
$Cloudflared = Join-Path $Home_ 'cloudflared.exe'
$TunnelMode  = Join-Path $Home_ 'tunnel-mode.txt'     # "quick" or "token"
$TokenFile   = Join-Path $Home_ 'tunnel-token.txt'    # only for a named tunnel; stays in your profile
$TunnelLog   = Join-Path $CacheDir 'tunnel.log'
$Startup     = Join-Path ([Environment]::GetFolderPath('Startup')) 'PitWall.vbs'

function Say($msg, $color = 'Gray') { Write-Host $msg -ForegroundColor $color }
function Write-NoBom($path, $text) { [IO.File]::WriteAllText($path, $text, (New-Object Text.UTF8Encoding $false)) }

function Invoke-Nginx([string[]]$NginxArgs) {
    # nginx writes normal output (-v, -t) to stderr; don't let Windows PowerShell treat that as a failure
    $eap = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    Push-Location $Home_
    try { & $NginxExe @NginxArgs 2>&1 | ForEach-Object { "$_" } }
    finally { Pop-Location; $ErrorActionPreference = $eap }
}
function Test-NginxRunning { @(Get-Process nginx -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $NginxExe }).Count -gt 0 }

function Stop-ByCommandLine($namePattern, $cmdPattern) {
    Get-CimInstance Win32_Process -Filter "Name like '$namePattern'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like $cmdPattern } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
function Stop-Watchers {
    Stop-ByCommandLine 'python%' '*build_site.py*--watch*'   # v1 watcher
    Stop-ByCommandLine 'node%' '*tools*watch.ts*'            # v2 watcher
}
function Stop-Tunnel { Get-Process cloudflared -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $Cloudflared } | Stop-Process -Force }
function Stop-Nginx {
    if (Test-Path $NginxExe) {
        if (Test-NginxRunning) { Invoke-Nginx @('-s', 'quit') | Out-Null; Start-Sleep -Seconds 1 }
        Get-Process nginx -ErrorAction SilentlyContinue | Where-Object { $_.Path -eq $NginxExe } | Stop-Process -Force
    }
}

function Get-TunnelArgs {
    if (-not (Test-Path $TunnelMode)) { return $null }
    $mode = (Get-Content $TunnelMode -Raw).Trim()
    if ($mode -eq 'token' -and (Test-Path $TokenFile)) {
        return @('tunnel', '--no-autoupdate', 'run', '--token', (Get-Content $TokenFile -Raw).Trim())
    }
    return @('tunnel', '--no-autoupdate', '--url', "http://127.0.0.1:$Port")
}

# ---------------------------------------------------------------- stop / uninstall / reload
if ($Stop -or $Uninstall) {
    Stop-Watchers; Stop-Tunnel; Stop-Nginx
    Say 'Stopped nginx, the watcher and the tunnel.' Green
    if ($Uninstall) {
        if (Test-Path $Startup) { Remove-Item $Startup -Force }
        if (Test-Path $Home_) { Remove-Item $Home_ -Recurse -Force }
        Say 'Removed the login entry, nginx and cloudflared. Your data folder is untouched.' Green
    }
    return
}
if ($Reload) {
    $test = Invoke-Nginx @('-t')
    if ($LASTEXITCODE -ne 0) { Say ($test -join "`n") Red; throw 'nginx config test failed; not reloading.' }
    Invoke-Nginx @('-s', 'reload') | Out-Null
    Say 'nginx reloaded.' Green
    return
}

# ---------------------------------------------------------------- 1. Node + the web app
Say "`n[1/5] Checking Node and the web app" Cyan
$node = (Get-Command node -ErrorAction SilentlyContinue).Source
if (-not $node) { throw 'Node.js 24+ was not found. Install the LTS from https://nodejs.org, then run this again.' }
$major = [int]((& $node --version) -replace '^v(\d+).*', '$1')
if ($major -lt 24) { throw "Node $major found; Pit Wall needs Node 24 or newer (it runs the TypeScript tools directly)." }
Say "  Using $node"
Push-Location $Root
try {
    if (-not (Test-Path (Join-Path $Root 'node_modules'))) { Say '  Installing packages (npm ci)...'; npm ci --no-audit --no-fund; if ($LASTEXITCODE) { throw 'npm ci failed.' } }
    $index = Join-Path $AppDir 'index.html'
    $srcNewest = Get-ChildItem (Join-Path $Root 'src') -Recurse -File | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if ($Build -or -not (Test-Path $index) -or $srcNewest.LastWriteTime -gt (Get-Item $index).LastWriteTime) {
        Say '  Building the web app...'
        npm run build --silent
        if ($LASTEXITCODE) { throw 'The web app build failed - see above.' }
    } else { Say '  Web app is up to date.' }
} finally { Pop-Location }

# ---------------------------------------------------------------- 2. nginx
Say "`n[2/5] Installing nginx $NginxVersion" Cyan
$installed = $null
if (Test-Path $NginxExe) { $installed = ((Invoke-Nginx @('-v')) -join ' ') -replace '.*nginx/', '' }
if ($installed -eq $NginxVersion) {
    Say "  Already installed at $Home_"
} else {
    Stop-Nginx
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $zip = Join-Path $env:TEMP "nginx-$NginxVersion.zip"; $tmp = Join-Path $env:TEMP "nginx-$NginxVersion-extract"
    Say "  Downloading https://nginx.org/download/nginx-$NginxVersion.zip"
    Invoke-WebRequest "https://nginx.org/download/nginx-$NginxVersion.zip" -OutFile $zip -UseBasicParsing
    if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force }
    Expand-Archive $zip -DestinationPath $tmp -Force
    $keep = @($Cloudflared, $TunnelMode, $TokenFile) | Where-Object { Test-Path $_ } | ForEach-Object { Copy-Item $_ $env:TEMP -PassThru }
    if (Test-Path $Home_) { Remove-Item $Home_ -Recurse -Force }
    Move-Item (Join-Path $tmp "nginx-$NginxVersion") $Home_
    $keep | ForEach-Object { Move-Item $_.FullName $Home_ -Force }
    Remove-Item $zip, $tmp -Recurse -Force -ErrorAction SilentlyContinue
    Say "  Installed to $Home_"
}

# ---------------------------------------------------------------- 3. config
Say "`n[3/5] Writing nginx config" Cyan
$busy = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue |
        Where-Object { (Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).Path -ne $NginxExe }
if ($busy) {
    $owner = (Get-Process -Id $busy[0].OwningProcess -ErrorAction SilentlyContinue).ProcessName
    throw "Port $Port is already used by '$owner'. Run again with a different port, e.g.  .\deploy\setup.ps1 -Port 8090"
}
New-Item -ItemType Directory -Force $DataDir, $CacheDir | Out-Null
$template = Get-Content (Join-Path $Root 'deploy\nginx.conf.tmpl') -Raw
$siteConf = $template.Replace('{{PORT}}', "$Port").Replace('{{APP}}', ($AppDir -replace '\\', '/')).Replace('{{DATA}}', ($DataDir -replace '\\', '/'))
$mainConf = @"
worker_processes  1;
error_log  logs/error.log warn;
pid        logs/nginx.pid;
events { worker_connections 256; }
http {
    include       mime.types;
    default_type  application/octet-stream;
    server_tokens off;
    access_log    logs/access.log;
    sendfile      off;   # data files are swapped in place on every compile; never serve stale bytes
    keepalive_timeout 30;
    include pitwall.conf;
}
"@
Write-NoBom (Join-Path $Home_ 'conf\nginx.conf') $mainConf
Write-NoBom (Join-Path $Home_ 'conf\pitwall.conf') $siteConf
$test = Invoke-Nginx @('-t')
if ($LASTEXITCODE -ne 0) { Say ($test -join "`n") Red; throw 'nginx config test failed.' }
Say "  Config OK: app from $AppDir, data from $DataDir"

# ---------------------------------------------------------------- 4. compile + start
Say "`n[4/5] Compiling the data and starting services" Cyan
Push-Location $Root
try { & $node tools/compile.ts } finally { Pop-Location }
if ($LASTEXITCODE -ne 0) { Say '  The compile reported errors (see above); continuing with what compiled.' Yellow }

if (Test-NginxRunning) { Invoke-Nginx @('-s', 'reload') | Out-Null; Say '  nginx was running - reloaded.' }
else { Start-Process -FilePath $NginxExe -WorkingDirectory $Home_ -WindowStyle Hidden; Say '  nginx started.' }

Stop-Watchers
Start-Process -FilePath $node -ArgumentList @('tools/watch.ts', '--log') -WorkingDirectory $Root -WindowStyle Hidden
Say '  Watcher started (log: .cache\watcher.log).'

# tunnel: remember the choice so it also starts at login
if ($NoTunnel) { Remove-Item $TunnelMode, $TokenFile -Force -ErrorAction SilentlyContinue }
if ($TunnelToken) { Write-NoBom $TokenFile $TunnelToken; Write-NoBom $TunnelMode 'token' }
elseif ($Tunnel) { Write-NoBom $TunnelMode 'quick' }
Stop-Tunnel
$tunnelArgs = Get-TunnelArgs
if ($tunnelArgs) {
    if (-not (Test-Path $Cloudflared)) {
        Say '  Downloading cloudflared (Cloudflare Tunnel client)...'
        [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
        Invoke-WebRequest 'https://github.com/cloudflare/cloudflared/releases/latest/download/cloudflared-windows-amd64.exe' -OutFile $Cloudflared -UseBasicParsing
    }
    if (Test-Path $TunnelLog) { Remove-Item $TunnelLog -Force }
    Start-Process -FilePath $Cloudflared -ArgumentList ($tunnelArgs + @('--logfile', $TunnelLog)) -WindowStyle Hidden
    Say '  Tunnel started (log: .cache\tunnel.log).'
}

# ---------------------------------------------------------------- 5. start at login
Say "`n[5/5] Starting automatically at login" Cyan
$tunnelLine = ''
if ($tunnelArgs) {
    if ((Get-Content $TunnelMode -Raw).Trim() -eq 'token') {
        # read the token from your profile at login rather than writing it into the startup script
        $tunnelLine = @"
token = CreateObject("Scripting.FileSystemObject").OpenTextFile("$TokenFile").ReadAll()
sh.Run """$Cloudflared"" tunnel --no-autoupdate run --token " & Trim(token) & " --logfile ""$TunnelLog""", 0, False
"@
    } else {
        $tunnelLine = "sh.Run """"""$Cloudflared"""" tunnel --no-autoupdate --url http://127.0.0.1:$Port --logfile """"$TunnelLog"""""", 0, False"
    }
}
$vbs = @"
' Pit Wall: start nginx, the data watcher and (if enabled) the tunnel, all hidden. Created by deploy\setup.ps1
Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = "$Home_"
sh.Run """$NginxExe""", 0, False
sh.CurrentDirectory = "$Root"
sh.Run """$node"" tools\watch.ts --log", 0, False
$tunnelLine
"@
Set-Content -Path $Startup -Value $vbs -Encoding ASCII
Say "  Updated $Startup"

# ---------------------------------------------------------------- check
Start-Sleep -Seconds 1
$url = "http://localhost:$Port/"
try {
    $r = Invoke-WebRequest "$($url)data/seasons.json" -UseBasicParsing -TimeoutSec 5
    $n = (($r.Content | ConvertFrom-Json).seasons).Count
    Say "`nPit Wall is live at $url  ($n season(s))" Green
} catch {
    Say "`nnginx started but $url didn't answer: $($_.Exception.Message). Check $Home_\logs\error.log" Yellow
}
if ($tunnelArgs -and (Get-Content $TunnelMode -Raw).Trim() -eq 'quick') {
    $public = $null
    foreach ($i in 1..30) {
        Start-Sleep -Seconds 1
        if (Test-Path $TunnelLog) { $public = (Select-String -Path $TunnelLog -Pattern 'https://[a-z0-9-]+\.trycloudflare\.com' | Select-Object -Last 1).Matches.Value }
        if ($public) { break }
    }
    if ($public) { Say "Shared at $public  (a quick-tunnel URL changes whenever the tunnel restarts)" Green }
    else { Say "The tunnel is starting; its URL will appear in $TunnelLog" Yellow }
} elseif ($tunnelArgs) {
    Say 'Shared through your named tunnel (the hostname you set up in Cloudflare).' Green
}
