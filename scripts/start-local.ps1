$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$backendPath = Join-Path $projectRoot 'backend'
$frontendPath = Join-Path $projectRoot 'frontend'
$sessionUrl = 'http://127.0.0.1:8001/local-api/session'

& (Join-Path $PSScriptRoot 'local-api.ps1') -SetupOnly
if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la API local.' }

$portOpen = $false
$socket = New-Object System.Net.Sockets.TcpClient
try {
    $portOpen = $socket.ConnectAsync('127.0.0.1', 8001).Wait(1000) -and $socket.Connected
} finally {
    $socket.Dispose()
}

if (!$portOpen) {
    $stdoutPath = Join-Path $backendPath 'storage/logs/start-local-api.stdout.log'
    $stderrPath = Join-Path $backendPath 'storage/logs/start-local-api.stderr.log'
    Start-Process -FilePath (Get-Command php -ErrorAction Stop).Source `
        -ArgumentList @('-d', 'xdebug.mode=off', 'artisan', 'serve', '--host=127.0.0.1', '--port=8001', '--no-reload') `
        -WorkingDirectory $backendPath -WindowStyle Hidden `
        -RedirectStandardOutput $stdoutPath -RedirectStandardError $stderrPath | Out-Null
}

$ready = $false
for ($attempt = 0; $attempt -lt 30; $attempt++) {
    try {
        $response = Invoke-WebRequest -Uri $sessionUrl -UseBasicParsing -TimeoutSec 2
        if ($response.StatusCode -eq 200) { $ready = $true; break }
    } catch {
        Start-Sleep -Milliseconds 500
    }
}
if (!$ready) {
    throw 'La API local no responde correctamente. Revisa backend/storage/logs/laravel.log.'
}

$frontendSocket = New-Object System.Net.Sockets.TcpClient
try {
    $frontendRunning = $frontendSocket.ConnectAsync('127.0.0.1', 4201).Wait(1000) -and $frontendSocket.Connected
} finally {
    $frontendSocket.Dispose()
}
if ($frontendRunning) {
    Write-Host 'La carta ya está abierta en http://127.0.0.1:4201/. La API y PostgreSQL están listos.'
    exit 0
}

Push-Location $frontendPath
try {
    & (Join-Path $projectRoot 'node_modules/.bin/ng.cmd') serve
    if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} finally {
    Pop-Location
}
