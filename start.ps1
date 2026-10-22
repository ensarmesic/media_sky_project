# Media Sky Project — lokalno pokretanje (Windows).
# Pokretanje: desni klik → "Run with PowerShell", ili u terminalu: .\start.ps1
# Sve radi u jednom prozoru: web server (http://localhost:8000, s OPcache-om) je u prvom planu,
# a queue worker i scheduler skriveno u pozadini (logovi: storage\logs\queue.log i scheduler.log).
# Zaustavljanje: Ctrl+C ili zatvaranje prozora — pozadinski procesi se gase zajedno sa serverom.

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

# Job Object: svi procesi dodijeljeni ovom "poslu" (i njihovi podprocesi) gase se automatski
# kad se ovaj prozor ugasi — bez obzira na to da li je Ctrl+C ili klik na X.
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
public static class MediaSkyJob {
    [StructLayout(LayoutKind.Sequential)]
    struct BASIC { public long a, b; public uint LimitFlags; public UIntPtr c, d; public uint e; public UIntPtr f; public uint g, h; }
    [StructLayout(LayoutKind.Sequential)]
    struct IO { public ulong a, b, c, d, e, f; }
    [StructLayout(LayoutKind.Sequential)]
    struct EXTENDED { public BASIC Basic; public IO Io; public UIntPtr i, j, k, l; }
    [DllImport("kernel32.dll", CharSet = CharSet.Unicode)] static extern IntPtr CreateJobObject(IntPtr a, string n);
    [DllImport("kernel32.dll")] static extern bool SetInformationJobObject(IntPtr j, int c, ref EXTENDED i, uint l);
    [DllImport("kernel32.dll")] static extern bool AssignProcessToJobObject(IntPtr j, IntPtr p);
    static IntPtr job;
    public static void Add(IntPtr process) {
        if (job == IntPtr.Zero) {
            job = CreateJobObject(IntPtr.Zero, null);
            var info = new EXTENDED();
            info.Basic.LimitFlags = 0x2000; // JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE
            SetInformationJobObject(job, 9, ref info, (uint)Marshal.SizeOf(typeof(EXTENDED)));
        }
        AssignProcessToJobObject(job, process);
    }
}
'@

$logs = "$root\storage\logs"
$background = @(
    @{ Name = 'queue'; Args = @('artisan', 'queue:work', '--tries=3', '--max-time=3600') },
    @{ Name = 'scheduler'; Args = @('artisan', 'schedule:work') }
) | ForEach-Object {
    $p = Start-Process php -ArgumentList $_.Args -WorkingDirectory $root -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput "$logs\$($_.Name).log" -RedirectStandardError "$logs\$($_.Name).error.log"
    [MediaSkyJob]::Add($p.Handle)
    $p
}

# OPcache se uključuje samo za web server (bez izmjene php.ini) — oko 4x brže učitavanje stranica.
$opcache = @('-d', 'zend_extension=opcache', '-d', 'opcache.enable_cli=1', '-d', 'opcache.memory_consumption=256',
    '-d', 'opcache.max_accelerated_files=20000', '-d', 'opcache.validate_timestamps=1', '-d', 'opcache.revalidate_freq=0')

$host.UI.RawUI.WindowTitle = 'Media Sky - localhost:8000'
Write-Host ''
Write-Host 'Pokrenuto: http://localhost:8000  (admin / password)' -ForegroundColor Green
Write-Host 'Queue i scheduler rade u pozadini (logovi u storage\logs\queue.log i scheduler.log).'
Write-Host 'Zaustavljanje: Ctrl+C ili zatvori ovaj prozor.'
Write-Host ''
Start-Process 'http://localhost:8000'

try {
    Set-Location "$root\public"
    php @opcache -S 127.0.0.1:8000 "$root\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php"
} finally {
    $background | Where-Object { -not $_.HasExited } | ForEach-Object { Stop-Process -Id $_.Id -Force -ErrorAction SilentlyContinue }
    Set-Location $root
}
