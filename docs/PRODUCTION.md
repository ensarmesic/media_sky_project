# Media Sky Project — produkcijski rad

Document root mora biti `public`. Postaviti `APP_ENV=production`, `APP_DEBUG=false`, HTTPS i sigurne session cookies. PHP smije pisati samo u `storage` i `bootstrap/cache`.

U primjerima ispod putanja aplikacije je `/var/www/media-sky-project` (Linux) odnosno `C:\www\media-sky-project` (Windows); prilagoditi stvarnoj putanji na serveru.

## Queue worker

Obavijesti (zvono i e-mail) idu kroz queue (`QUEUE_CONNECTION=database`). Bez workera se ne šalju. Worker mora stalno raditi pod nadzorom procesa koji ga ponovo pokreće ako stane.

### Linux — supervisor

`/etc/supervisor/conf.d/media-sky-queue.conf`:

```ini
[program:media-sky-queue]
command=php /var/www/media-sky-project/artisan queue:work --sleep=3 --tries=3 --timeout=90 --max-time=3600
directory=/var/www/media-sky-project
user=www-data
autostart=true
autorestart=true
stopwaitsecs=120
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/media-sky-project/storage/logs/queue-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
```

```bash
sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl status media-sky-queue
```

`--max-time=3600` znači da se worker sam ugasi nakon sat vremena, a supervisor ga odmah pokrene ponovo (oslobađa memoriju i učitava novi kod).

### Windows — NSSM (servis)

```powershell
nssm install MediaSkyQueue "C:\php\php.exe" "C:\www\media-sky-project\artisan queue:work --sleep=3 --tries=3 --timeout=90 --max-time=3600"
nssm set MediaSkyQueue AppDirectory "C:\www\media-sky-project"
nssm set MediaSkyQueue AppStdout "C:\www\media-sky-project\storage\logs\queue-worker.log"
nssm set MediaSkyQueue AppStderr "C:\www\media-sky-project\storage\logs\queue-worker.log"
nssm set MediaSkyQueue AppExit Default Restart
nssm set MediaSkyQueue Start SERVICE_AUTO_START
nssm start MediaSkyQueue
```

## Scheduler

Scheduler pokreće dnevne podsjetnike (rokovi u 07:00 i 07:05, čekanje odgovora u 07:10), dnevni sažetak (svaki sat, prema satu koji korisnik odabere) i svake minute bilježi otkucaj za "Stanje sistema".

### Linux — cron

`crontab -u www-data -e`:

```cron
* * * * * cd /var/www/media-sky-project && php artisan schedule:run >> /dev/null 2>&1
```

### Windows — Task Scheduler

```powershell
schtasks /Create /TN "MediaSkyScheduler" /SC MINUTE /MO 1 /RU SYSTEM /TR "C:\php\php.exe C:\www\media-sky-project\artisan schedule:run"
```

(Alternativa: servis preko NSSM-a s komandom `artisan schedule:work`.)

## Deploy (ažuriranje na serveru)

```bash
php artisan down
git pull                      # ili kopirati novi folder preko starog (bez .env, storage/ i database/database.sqlite)
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
php artisan optimize
php artisan queue:restart     # worker preuzima novi kod nakon trenutnog joba
php artisan up
php artisan app:health        # provjera da scheduler i queue rade
```

## Nadzor

- **Postavke → Stanje sistema** (samo admin): zadnje pokretanje schedulera, zadnji obrađeni job, jobovi na čekanju i neuspjeli jobovi (s dugmadima "Pokušaj ponovo" i "Obriši").
- Ako scheduler nije radio duže od 10 minuta ili je na čekanju više od 20 jobova, adminu se na vrhu svake stranice prikazuje upozorenje. Granice: `HEALTH_SCHEDULER_STALE_MINUTES` i `HEALTH_MAX_PENDING_JOBS` u `.env`.
- `php artisan app:health` ispisuje isto stanje; izlazni kod je `1` kad postoji upozorenje, pa se može koristiti u monitoringu (npr. cron koji šalje e-mail kad komanda ne uspije).
- Nadzirati i `/up`, prostor na disku i `storage/logs`. Pristup logovima i backupima ograničiti administratorima sistema.

## Backup

Svakodnevno sačuvati bazu i `storage/app/private` (dokumenti). Backup mora imati definiran period čuvanja, biti na drugom serveru/disku i redovno testiran restore. Produkcijski `.env` čuvati u odvojenom sigurnom spremištu. Dokumente vanjskog protokola ne duplicirati.
