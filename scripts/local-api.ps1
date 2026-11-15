param([switch]$SetupOnly)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$backendPath = Join-Path $projectRoot 'backend'
$dataPath = Join-Path $backendPath '.local-postgres'
$envPath = Join-Path $backendPath '.env'
$postgresBin = Split-Path (Get-Command psql -ErrorAction Stop).Source
$pgCtlPath = Join-Path $postgresBin 'pg_ctl.exe'

if (!(Test-Path (Join-Path $backendPath 'vendor/autoload.php'))) {
    & composer install --working-dir $backendPath --no-interaction
    if ($LASTEXITCODE -ne 0) { throw 'No se pudieron instalar las dependencias de Laravel.' }
}

if (!(Test-Path $envPath)) {
    $randomBytes = New-Object byte[] 24
    $generator = [Security.Cryptography.RandomNumberGenerator]::Create()
    $generator.GetBytes($randomBytes)
    $generator.Dispose()
    $password = [BitConverter]::ToString($randomBytes).Replace('-', '')
    $template = [IO.File]::ReadAllText((Join-Path $backendPath '.env.example'))
    $template = $template.Replace('DB_PASSWORD=', "DB_PASSWORD=$password")
    [IO.File]::WriteAllText($envPath, $template)
}
$envText = [IO.File]::ReadAllText($envPath)
$password = [regex]::Match($envText, '(?m)^DB_PASSWORD=(.*)$').Groups[1].Value.Trim().Trim('"')
if (!$password) { throw 'Configura DB_PASSWORD en backend/.env antes de continuar.' }

if (!(Test-Path (Join-Path $dataPath 'PG_VERSION'))) {
    $passwordFile = Join-Path $backendPath 'storage/app/.postgres-password.tmp'
    try {
        [IO.File]::WriteAllText($passwordFile, $password)
        & (Join-Path $postgresBin 'initdb.exe') -D $dataPath -U estacion_local -A scram-sha-256 --pwfile=$passwordFile --encoding=UTF8 --locale=C
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo crear la instancia local de PostgreSQL.' }
    } finally {
        if (Test-Path $passwordFile) { Remove-Item -LiteralPath $passwordFile }
    }
}

& $pgCtlPath -D $dataPath status *> $null
if ($LASTEXITCODE -ne 0) {
    $logPath = Join-Path $backendPath 'storage/logs/postgres.log'
    $arguments = "-D `"$dataPath`" -l `"$logPath`" -o `"-h 127.0.0.1 -p 55436`" -w start"
    $process = Start-Process -FilePath $pgCtlPath -ArgumentList $arguments -WindowStyle Hidden -PassThru
    if (!$process.WaitForExit(60000)) { throw 'PostgreSQL tardó demasiado en iniciar. Revisa storage/logs/postgres.log.' }
    if ($process.ExitCode -ne 0) { throw 'No se pudo iniciar PostgreSQL. Revisa storage/logs/postgres.log.' }
}

$previousPgPassword = $env:PGPASSWORD
try {
    $env:PGPASSWORD = $password
    foreach ($database in @('estacion_operaciones', 'estacion_operaciones_test')) {
        $exists = & (Join-Path $postgresBin 'psql.exe') -h 127.0.0.1 -p 55436 -U estacion_local -d postgres -tAc "select 1 from pg_database where datname='$database'"
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo acceder a PostgreSQL local.' }
        if ($exists -ne '1') {
            & (Join-Path $postgresBin 'createdb.exe') -h 127.0.0.1 -p 55436 -U estacion_local $database
            if ($LASTEXITCODE -ne 0) { throw "No se pudo crear la base $database." }
        }
    }
} finally { $env:PGPASSWORD = $previousPgPassword }

Push-Location $projectRoot
try {
    & node scripts/export-local-catalog.cjs
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar el catálogo local.' }
    Push-Location $backendPath
    try {
        & php -d xdebug.mode=off artisan package:discover --ansi
        if (!$envText.Contains('APP_KEY=base64:')) { & php -d xdebug.mode=off artisan key:generate --ansi }
        & php -d xdebug.mode=off artisan migrate --force
        if ($LASTEXITCODE -ne 0) { throw 'No se pudieron aplicar las migraciones.' }
        & php -d xdebug.mode=off artisan db:seed --class=LocalCatalogSeeder --force
        if ($LASTEXITCODE -ne 0) { throw 'No se pudo cargar el catálogo local.' }
        Write-Host 'PostgreSQL local listo.'
        if (!$SetupOnly) {
            & php -d xdebug.mode=off artisan serve --host=127.0.0.1 --port=8001 --no-reload
            if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        }
    } finally { Pop-Location }
} finally { Pop-Location }
