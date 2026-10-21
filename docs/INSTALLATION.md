# Media Sky Project — instalacija

## Zahtjevi

- **PHP 8.4+** (zaključani paketi traže najmanje 8.4.1) i Composer 2
- PHP ekstenzije:
  - `pdo_sqlite` — trenutna baza (SQLite); za planirani MySQL i `pdo_mysql`
  - `gd` — logo u PDF izvještaju (bez nje se umjesto slike ispisuje tekstualni naziv)
  - `zip`, `intl`, `mbstring`, `fileinfo`, `xml`, `curl`, `openssl`, `bcmath`
- Node.js 22+ i npm (za build frontenda)
- Baza: **SQLite** (podrazumijevano u `.env.example`). Za produkciju je planiran prelazak na MySQL; dok se to ne podesi, koristi se SQLite.

Provjera ekstenzija: `php -m`.

## Prva instalacija

1. Kopirati `.env.example` u `.env` i postaviti `APP_NAME="Media Sky Project"`, `APP_URL`, `MAIL_*` i `QUEUE_CONNECTION=database`.
2. `composer install --no-dev --optimize-autoloader`
3. `php artisan key:generate`
4. Kreirati fajl baze ako ne postoji: `touch database/database.sqlite` (na Windowsu: `type nul > database\database.sqlite`).
5. `php artisan migrate --force`
6. `npm ci` i `npm run build`
7. Kreirati prvog administratora (development seeder ne koristiti u produkciji):

   ```bash
   php artisan tinker --execute="App\Models\User::create(['name' => 'Ime Prezime', 'username' => 'admin', 'password' => 'JakaLozinka!', 'role' => 'administrator']);"
   ```

8. Pokrenuti queue worker i scheduler (obavijesti i dnevni podsjetnici ne rade bez njih) — vidi [PRODUCTION.md](PRODUCTION.md).

Za lokalni razvoj dovoljno je `composer setup`, pa `composer run dev`.

## Ažuriranje

Prije ažuriranja napraviti backup baze (`database/database.sqlite`) i dokumenata (`storage/app/private`), a zatim:

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
