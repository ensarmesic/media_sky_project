# Media Sky Project — lokalno pokretanje (Windows).
# Pokretanje: desni klik → "Run with PowerShell", ili u terminalu: .\start.ps1
# Otvara tri prozora: web server (http://localhost:8000, s OPcache-om), queue worker i scheduler.

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
Set-Location $root

# Keširana konfiguracija u lokalnom radu je opasna: testovi bi tada radili nad pravom bazom.
if (Test-Path "$root\bootstrap\cache\config.php") {
    Write-Host 'Uklanjam keširanu konfiguraciju (config:clear)...' -ForegroundColor Yellow
    php artisan config:clear | Out-Null
}

Write-Host 'Primjenjujem nove migracije (ako ih ima)...'
php artisan migrate --force

# Rute i eventi se keširaju svježe pri svakom pokretanju (brže, a nikad zastarjelo). Konfiguracija se NE kešira.
php artisan route:cache | Out-Null
php artisan event:cache | Out-Null

if (-not (Test-Path "$root\public\build\manifest.json")) {
    Write-Host 'Gradim frontend (npm run build)...'
    npm run build
}

# OPcache se uključuje samo za web server (bez izmjene php.ini) — oko 4x brže učitavanje stranica.
$opcache = '-d zend_extension=opcache -d opcache.enable_cli=1 -d opcache.memory_consumption=256 -d opcache.max_accelerated_files=20000 -d opcache.validate_timestamps=1 -d opcache.revalidate_freq=0'
$server = "Set-Location '$root\public'; `$host.UI.RawUI.WindowTitle = 'Media Sky - server :8000'; php $opcache -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
$queue = "Set-Location '$root'; `$host.UI.RawUI.WindowTitle = 'Media Sky - queue'; php artisan queue:work --tries=3 --max-time=3600"
$scheduler = "Set-Location '$root'; `$host.UI.RawUI.WindowTitle = 'Media Sky - scheduler'; php artisan schedule:work"

Start-Process powershell -ArgumentList '-NoExit', '-Command', $server
Start-Process powershell -ArgumentList '-NoExit', '-Command', $queue
Start-Process powershell -ArgumentList '-NoExit', '-Command', $scheduler

Write-Host ''
Write-Host 'Pokrenuto: http://localhost:8000  (admin / password)' -ForegroundColor Green
Write-Host 'Zaustavljanje: zatvori tri otvorena prozora.'
Start-Sleep -Seconds 2
Start-Process 'http://localhost:8000'
