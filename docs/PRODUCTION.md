# ProjectFlow — produkcijski rad

Document root mora biti `public`. Postaviti `APP_ENV=production`, `APP_DEBUG=false`, HTTPS i sigurne session cookies. PHP smije pisati samo u `storage` i `bootstrap/cache`.

## Queue worker

Obavijesti koriste queue. Worker mora stalno raditi pod process supervisorom:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=90 --max-time=3600
```

Nakon objave pokrenuti `php artisan queue:restart` i nadzirati `failed_jobs`.

## Scheduler

```cron
* * * * * cd /putanja/projectflow && php artisan schedule:run >> /dev/null 2>&1
```

Scheduler šalje podsjetnike za zadatke u 07:00 i predmete u 07:05 u vremenskoj zoni aplikacije.

## Backup

Svakodnevno sačuvati PostgreSQL bazu (`pg_dump --format=custom`) i `storage/app/private`. Backup mora biti šifriran, imati definiran period čuvanja i redovno testiran restore. Produkcijski `.env` čuvati u odvojenom secret spremištu. Dokumente vanjskog protokola ne duplicirati.

## Nadzor

Nadzirati `/up`, queue workere, scheduler, prostor na disku, neuspjele poslove i Laravel logove. Pristup logovima i backupima ograničiti administratorima sistema.
