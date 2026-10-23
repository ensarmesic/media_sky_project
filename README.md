# Media Sky Project

Interna aplikacija Media Sky Telekomunikacije za praćenje projekata i administrativnih postupaka: postupci i stavke s rokovima, predajom i odgovorima institucija, dokumenti s verzijama, komentari, historija promjena, obavijesti i PDF izvještaji. UI je na bosanskom jeziku.

## Dokumentacija

- [Instalacija i ažuriranje](docs/INSTALLATION.md)
- [Produkcijski rad, queue, scheduler i backup](docs/PRODUCTION.md)
- [Priprema integracije s protokolom](docs/PROTOCOL_INTEGRATION.md)
- [Changelog](CHANGELOG.md)

## Tehnologije

Laravel 10, Livewire 4, Flux UI, Tailwind CSS 4 i Vite. Radi na PHP 8.1 (staging server Mediasky), a lokalno i na novijem PHP-u; vidi CHANGELOG. Baza je MySQL 8 (lokalno, staging i produkcija). Aplikacija nema odvojeni SPA frontend.

## Pokretanje na ovom računaru (Windows)

```powershell
.\start.ps1
```

Otvara jedan prozor sa serverom (http://localhost:8000, s OPcache-om ~4x brže); queue worker i scheduler rade skriveno u pozadini (logovi: `storageogsqueue.log`, `scheduler.log`) i gase se zajedno s prozorom (Ctrl+C ili X). Prva prijava: `admin` / `password` (promijeniti u Postavke → Profil / Sigurnost).

**Lokalno nikad ne pokretati `php artisan config:cache` ni `php artisan optimize`** — s keširanom konfiguracijom testovi bi radili nad pravom bazom (testovi se sada sami zaustave ako se to desi).

## Brzi start (lokalno)

```bash
composer setup     # instalacija, .env, ključ, migracije, npm build
composer run dev   # server, queue, logovi i Vite zajedno
```

Provjere prije commita (iste kao u CI-ju): `composer ci:check` — Pint, PHPStan (nivo 7) i testovi.

## Licenca

Projekat je nastao na osnovu [Laravel Livewire starter kita](https://github.com/laravel/livewire-starter-kit) (MIT licenca).
