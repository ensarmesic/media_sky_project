# ProjectFlow — instalacija

## Zahtjevi

- PHP 8.3+, Composer 2 i potrebne PHP ekstenzije
- Node.js 20+ i npm
- PostgreSQL 15+

## Prva instalacija

1. Kopirati `.env.example` u `.env` i postaviti `APP_URL`, `DB_*`, `MAIL_*` i `QUEUE_CONNECTION=database`.
2. Pokrenuti `composer install --no-dev --optimize-autoloader`.
3. Pokrenuti `php artisan key:generate`.
4. Pokrenuti `php artisan migrate --force`.
5. Pokrenuti `npm ci` i `npm run build`.
6. Kreirati prvog administratora sigurnim internim postupkom. Development seeder ne koristiti u produkciji.

Za lokalni razvoj može se koristiti `composer run dev`.

## Ažuriranje

Prije ažuriranja napraviti backup baze i `storage/app/private`, a zatim:

```bash
php artisan down
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci
npm run build
php artisan optimize
php artisan up
```
