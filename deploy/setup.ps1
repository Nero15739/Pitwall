<#
  Pit Wall - setup for the iRacing PC (no admin rights needed)

  .\deploy\setup.ps1                                     check Node, install packages, run the watcher at login
  .\deploy\setup.ps1 -Site https://pitwall.example.com   save the hosted site's address (pitwall.config.json)
  .\deploy\setup.ps1 -Key pw_xxxxxxxx                    save the API key from the admin panel (.pitwall-key)
  .\deploy\setup.ps1 -Stop                               stop the watcher
  .\deploy\setup.ps1 -Uninstall                          stop it and remove the login entry
  .\deploy\setup.ps1 -RemoveOldServer                    also delete the v2 local web server (nginx, cloudflared)

  The site itself now lives on Hostinger. This PC only harvests replays and pushes files:
  the watcher sends every new race export (data\<season>\eventresult-*.json) and harvested
  replay (data\incidents\incidents-*.json) to the site as it lands.
#>
param(
    [string]$Site,
    [string]$Key,
    [switch]$Stop,
    [switch]$Uninstall,
    [switch]$RemoveOldServer
)

$ErrorActionPreference = 'Stop'
$Root      = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Startup   = Join-Path ([Environment]::GetFolderPath('Startup')) 'PitWall.vbs'
$OldHome   = Join-Path $env:LOCALAPPDATA 'pitwall-nginx'          # the v2 local web server
$KeyFile   = Join-Path $Root '.pitwall-key'
$Config    = Join-Path $Root 'pitwall.config.json'

function Say($msg, $color = 'Gray') { Write-Host $msg -ForegroundColor $color }
function Write-NoBom($path, $text) { [IO.File]::WriteAllText($path, $text, (New-Object Text.UTF8Encoding $false)) }
function Stop-Watchers {
    Get-CimInstance Win32_Process -Filter "Name like 'node%'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -like '*tools*watch.ts*' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}
function Stop-OldServer {
    if (-not (Test-Path $OldHome)) { return }
    $nginx = Join-Path $OldHome 'nginx.exe'; $cf = Join-Path $OldHome 'cloudflared.exe'
    Get-Process nginx, cloudflared -ErrorAction SilentlyContinue | Where-Object { $_.Path -in @($nginx, $cf) } | Stop-Process -Force
    if ($RemoveOldServer -or $Uninstall) {
        Remove-Item $OldHome -Recurse -Force
        Say "  Removed the v2 local web server ($OldHome)." Green
    } else {
        Say "  Stopped the v2 local web server (nginx/cloudflared). Delete it with -RemoveOldServer." Yellow
    }
}

# ---------------------------------------------------------------- stop / uninstall
if ($Stop -or $Uninstall) {
    Stop-Watchers
    Stop-OldServer
    Say 'Stopped the watcher.' Green
    if ($Uninstall -and (Test-Path $Startup)) { Remove-Item $Startup -Force; Say 'Removed the login entry. Your data folder is untouched.' Green }
    return
}

# ---------------------------------------------------------------- 1. Node + packages
Say "`n[1/4] Checking Node" Cyan
$node = (Get-Command node -ErrorAction SilentlyContinue).Source
if (-not $node) { throw 'Node.js 24+ was not found. Install the LTS from https://nodejs.org, then run this again.' }
$major = [int]((& $node --version) -replace '^v(\d+).*', '$1')
if ($major -lt 24) { throw "Node $major found; Pit Wall's tools need Node 24 or newer (they run TypeScript directly)." }
Say "  Using $node"
Push-Location $Root
try {
    if (-not (Test-Path (Join-Path $Root 'node_modules'))) { Say '  Installing packages (npm ci)...'; npm ci --no-audit --no-fund; if ($LASTEXITCODE) { throw 'npm ci failed.' } }
} finally { Pop-Location }

# ---------------------------------------------------------------- 2. site + key
Say "`n[2/4] Connecting to the site" Cyan
if ($Site) {
    if ($Site -notmatch '^https?://[^\s/]+') { throw "That doesn't look like a web address: $Site" }
    & $node -e "const fs=require('fs');const f=process.argv[1];const c=JSON.parse(fs.readFileSync(f,'utf8'));c.site=process.argv[2].replace(/\/+`$/,'');fs.writeFileSync(f,JSON.stringify(c,null,2)+'\n')" $Config $Site
    Say "  Saved site address in pitwall.config.json"
}
if ($Key) {
    if ($Key -notmatch '^pw_[A-Za-z0-9_-]{20,}$') { throw 'API keys start with pw_. Copy it from the admin panel (API keys).' }
    Write-NoBom $KeyFile "$Key`n"
    Say '  Saved the API key in .pitwall-key (git-ignored)'
}
$siteSet = (Get-Content $Config -Raw | ConvertFrom-Json).site
$keySet = $env:PITWALL_API_KEY -or (Test-Path $KeyFile)
if ($siteSet -and $keySet) {
    Push-Location $Root
    try { & $node tools/push.ts --status } finally { Pop-Location }
    if ($LASTEXITCODE) { Say '  The site did not accept the connection (see above). Files will wait on this PC until it does.' Yellow }
} else {
    if (-not $siteSet) { Say '  No site address yet: run again with -Site https://your-domain' Yellow }
    if (-not $keySet) { Say '  No API key yet: create one in the admin panel (API keys), then run again with -Key pw_...' Yellow }
}

# ---------------------------------------------------------------- 3. watcher
Say "`n[3/4] Starting the watcher" Cyan
Stop-OldServer
Stop-Watchers
Start-Process -FilePath $node -ArgumentList @('tools/watch.ts', '--log') -WorkingDirectory $Root -WindowStyle Hidden
Say '  Watching data\ (log: .cache\watcher.log). New files are pushed to the site as they land.'

# ---------------------------------------------------------------- 4. start at login
Say "`n[4/4] Starting automatically at login" Cyan
$vbs = @"
' Pit Wall: run the data watcher hidden (it pushes new results and replays to the site). Created by deploy\setup.ps1
Set sh = CreateObject("WScript.Shell")
sh.CurrentDirectory = "$Root"
sh.Run """$node"" tools\watch.ts --log", 0, False
"@
Set-Content -Path $Startup -Value $vbs -Encoding ASCII
Say "  Updated $Startup"
Say "`nDone. Harvest a replay with deploy\harvest.bat; it uploads by itself once the site and key are set." Green
