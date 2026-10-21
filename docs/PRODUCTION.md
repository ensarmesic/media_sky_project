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

Backup radi `spatie/laravel-backup` kroz komandu `php artisan app:backup`:

- **Sadržaj:** baza + dokumenti (`storage/app/private`, gdje ih sprema `ProjectDocument::DISK = local`). Bez `vendor`, `node_modules`, keša i logova.
  - MySQL: dump preko `mysqldump` (mora biti dostupan na serveru — dolazi uz MySQL; ako nije u PATH-u, u `mysql` konekciji u `config/database.php` dodati `'dump' => ['dump_binary_path' => '/putanja/do/mysql/bin']`).
  - SQLite: konzistentan snimak baze (`VACUUM INTO`), bez potrebe za `sqlite3` alatom.
- **Raspored:** backup svaki dan u 02:00, čišćenje starih u 03:00, provjera starosti u 04:00 (scheduler mora raditi).
- **Čuvanje:** svi backupi 7 dana, dnevni 30 dana, sedmični 8 sedmica, mjesečni 6 mjeseci.
- **Upozorenje:** ako backup ne uspije ili je najnoviji stariji od 26 sati, svi admini dobiju obavijest (zvono + e-mail ako ga imaju i nisu ga isključili).
- **Postavke → Stanje sistema:** zadnji uspješan backup po odredištu, veličina, broj čuvanih i dugme "Napravi backup sada" (ide kroz queue).

### Odredište

Odredišta se biraju u `.env` (zarezom odvojeno):

```dotenv
BACKUP_DISKS=backups               # lokalno: storage/app/backups (ili BACKUP_LOCAL_PATH)
# BACKUP_DISKS=backups,sftp_backups  # preporučeno: lokalno + udaljeno
```

**Backup samo na istom serveru nije dovoljan** — kvar diska, ransomware ili brisanje servera odnose i aplikaciju i backup. Uz lokalni obavezno podesiti bar jedno udaljeno odredište:

- **SFTP** (drugi server/NAS): `composer require league/flysystem-sftp-v3`, pa `BACKUP_SFTP_HOST`, `BACKUP_SFTP_USERNAME`, `BACKUP_SFTP_PASSWORD` ili `BACKUP_SFTP_PRIVATE_KEY`, `BACKUP_SFTP_ROOT`.
- **S3-kompatibilan** (AWS S3, Backblaze B2, Wasabi, MinIO): `composer require league/flysystem-aws-s3-v3`, pa `BACKUP_S3_KEY`, `BACKUP_S3_SECRET`, `BACKUP_S3_BUCKET`, `BACKUP_S3_REGION`, po potrebi `BACKUP_S3_ENDPOINT`.

Arhiva se može zaključati lozinkom: `BACKUP_ARCHIVE_PASSWORD=...` (lozinku čuvati odvojeno od servera — bez nje se backup ne može otvoriti).

**`.env` nije u backupu** (sadrži lozinke). Čuvati ga posebno, na sigurnom mjestu: bez istog `APP_KEY` ne mogu se dešifrovati 2FA podaci korisnika.

### Vraćanje iz backupa

1. Staviti aplikaciju u održavanje: `php artisan down`.
2. Preuzeti željeni zip s odredišta (npr. `storage/app/backups/media-sky-project/2026-10-21-02-00-00.zip`) i raspakovati ga u privremeni folder.
3. **Dokumenti:** sadržaj foldera `.../storage/app/private` iz arhive kopirati u `storage/app/private` aplikacije (preko postojećeg).
4. **Baza:**
   - MySQL: `mysql -u <korisnik> -p <baza> < db-dumps/mysql-<baza>.sql`
   - SQLite: fajl `.../storage/app/backup-temp/sqlite/database.sqlite` iz arhive kopirati preko `database/database.sqlite`.
5. `php artisan migrate --force` (ako je backup stariji od zadnjeg deploya), `php artisan optimize:clear`, `php artisan queue:restart`.
6. `php artisan up` i provjeriti nekoliko projekata, stavki i preuzimanje dokumenata.

**Vraćanje treba bar jednom isprobati** (na testnom serveru ili kopiji) prije nego zatreba — backup koji nikad nije vraćen nije provjeren backup.

### Ručne komande

```bash
php artisan app:backup        # backup odmah
php artisan backup:list       # pregled backupa po odredištu
php artisan backup:monitor    # provjera starosti (26 h)
php artisan backup:clean      # brisanje starih prema pravilima čuvanja
```
