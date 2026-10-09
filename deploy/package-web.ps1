<#
  Pit Wall - package the website for Hostinger: dist\pitwall-web-<version>.zip

  Upload the zip in hPanel -> Files -> File Manager -> public_html, then right-click it -> Extract.
  storage\ (your database and published data) and config.php are left out, so extracting an
  update over a live site never touches your data or settings.
#>
$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$Web  = Join-Path $Root 'web'
$version = (Select-String -Path (Join-Path $Web 'app\bootstrap.php') -Pattern "PITWALL_VERSION = '([^']+)'").Matches[0].Groups[1].Value
$Dist = Join-Path $Root 'dist'
New-Item -ItemType Directory -Force $Dist | Out-Null
$zip = Join-Path $Dist "pitwall-web-$version.zip"
if (Test-Path $zip) { Remove-Item $zip -Force }

# zip entries need forward slashes, or Linux hosts extract "app\lib\X.php" as one odd file name
Add-Type -AssemblyName System.IO.Compression, System.IO.Compression.FileSystem
$archive = [IO.Compression.ZipFile]::Open($zip, [IO.Compression.ZipArchiveMode]::Create)
$count = 0
try {
    foreach ($f in Get-ChildItem $Web -Recurse -File -Force) {
        $rel = $f.FullName.Substring($Web.Length + 1) -replace '\\', '/'
        if ($rel -like 'storage/*' -or $rel -eq 'config.php') { continue }
        [void][IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $f.FullName, $rel, [IO.Compression.CompressionLevel]::Optimal)
        $count++
    }
} finally { $archive.Dispose() }

$kb = [math]::Round((Get-Item $zip).Length / 1KB)
Write-Host "Packaged $count files ($kb KB): $zip" -ForegroundColor Green
Write-Host 'Upload it to public_html in Hostinger''s File Manager and extract it there.'
