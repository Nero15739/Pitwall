<#
  Builds the Pit Wall Logger into dist\PitWallLogger\.
    .\app\build.ps1
  Uses the .NET SDK on PATH, or the per-user one in %LOCALAPPDATA%\Microsoft\dotnet.
  Draws the app icon (app\PitWallLogger\pitwall.ico) first if it isn't there.
#>
$ErrorActionPreference = 'Stop'
$App  = Join-Path $PSScriptRoot 'PitWallLogger'
$Out  = Join-Path (Split-Path -Parent $PSScriptRoot) 'dist\PitWallLogger'
$Ico  = Join-Path $App 'pitwall.ico'

$dotnet = (Get-Command dotnet -ErrorAction SilentlyContinue).Source
$local = Join-Path $env:LOCALAPPDATA 'Microsoft\dotnet\dotnet.exe'
if (Test-Path $local) { $dotnet = $local }
if (-not $dotnet) { throw 'No .NET SDK found.' }

if (-not (Test-Path $Ico)) {
    # a 256 px icon: a black square with a green dot (PNG inside an .ico, which Windows Vista+ reads)
    Add-Type -AssemblyName System.Drawing
    $bmp = New-Object Drawing.Bitmap 256, 256
    $g = [Drawing.Graphics]::FromImage($bmp)
    $g.SmoothingMode = 'AntiAlias'
    $g.Clear([Drawing.Color]::Transparent)
    $g.FillRectangle((New-Object Drawing.SolidBrush ([Drawing.Color]::FromArgb(20, 20, 20))), 16, 16, 224, 224)
    $g.FillEllipse((New-Object Drawing.SolidBrush ([Drawing.Color]::FromArgb(22, 163, 74))), 68, 68, 120, 120)
    $ms = New-Object IO.MemoryStream
    $bmp.Save($ms, [Drawing.Imaging.ImageFormat]::Png)
    $png = $ms.ToArray()
    $w = New-Object IO.BinaryWriter ([IO.File]::Create($Ico))
    $w.Write([uint16]0); $w.Write([uint16]1); $w.Write([uint16]1)                 # header: icon, 1 image
    $w.Write([byte]0); $w.Write([byte]0); $w.Write([byte]0); $w.Write([byte]0)   # 256x256, no palette
    $w.Write([uint16]1); $w.Write([uint16]32); $w.Write([uint32]$png.Length); $w.Write([uint32]22)
    $w.Write($png); $w.Close(); $g.Dispose(); $bmp.Dispose()
}

& $dotnet publish $App -c Release -o $Out --nologo -v quiet
if ($LASTEXITCODE) { throw "Build failed ($LASTEXITCODE)" }
Write-Host "Built $Out\PitWallLogger.exe"
