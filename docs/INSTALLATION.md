# Media Sky Project — instalacija

## Zahtjevi

- **PHP 8.1+** (grana `laravel10-php81`; staging ima PHP 8.1) i Composer 2
- PHP ekstenzije:
  - `pdo_mysql` — baza je MySQL 8 (`pdo_sqlite` treba samo za prenos starih podataka iz SQLite fajla)
  - `gd` — logo u PDF izvještaju (bez nje se umjesto slike ispisuje tekstualni naziv)
  - `zip`, `intl`, `mbstring`, `fileinfo`, `xml`, `curl`, `openssl`
- Node.js 22+ i npm (za build frontenda)
- Baza: **MySQL 8**, utf8mb4 (lokalno, staging i produkcija). Za testove treba i baza `media_sky_project_testing` (isti korisnik).

Provjera ekstenzija: `php -m`.

## Prva instalacija

1. Kopirati `.env.example` u `.env` i postaviti `APP_NAME="Media Sky Project"`, `APP_URL`, `DB_*`, `MAIL_*` i `QUEUE_CONNECTION=database`.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan key:generate`
4. Kreirati bazu (i testnu bazu) ako ne postoje:

   ```sql
   CREATE DATABASE media_sky_project CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE DATABASE media_sky_project_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
5. `php artisan migrate --force`
6. `npm ci` i `npm run build`
7. Kreirati prvog administratora (development seeder ne koristiti u produkciji):

   ```bash
   php artisan tinker --execute="App\Models\User::create(['name' => 'Ime Prezime', 'username' => 'admin', 'password' => 'JakaLozinka!', 'role' => 'administrator']);"
   ```

8. Pokrenuti queue worker i scheduler (obavijesti i dnevni podsjetnici ne rade bez njih) — vidi [PRODUCTION.md](PRODUCTION.md).

Za lokalni razvoj dovoljno je `composer setup`, pa `composer run dev`.

## Ažuriranje

Prije ažuriranja napraviti backup baze (`php artisan app:backup` ili `mysqldump`) i dokumenata (`storage/app/private`), a zatim:

```bash
php artisan down
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize
php artisan queue:restart
php artisan up
```
