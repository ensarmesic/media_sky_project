# Media Sky Project

Interna aplikacija Media Sky Telekomunikacije za praćenje projekata i administrativnih postupaka: postupci i stavke s rokovima, predajom i odgovorima institucija, dokumenti s verzijama, komentari, historija promjena, obavijesti i PDF izvještaji. UI je na bosanskom jeziku.

## Dokumentacija

- [Instalacija i ažuriranje](docs/INSTALLATION.md)
- [Produkcijski rad, queue, scheduler i backup](docs/PRODUCTION.md)
- [Priprema integracije s protokolom](docs/PROTOCOL_INTEGRATION.md)
- [Changelog](CHANGELOG.md)

## Tehnologije

Laravel 13, Livewire 4, Flux UI, Tailwind CSS 4 i Vite, na PHP 8.4. Baza je trenutno SQLite; za produkciju je planiran prelazak na MySQL. Aplikacija nema odvojeni SPA frontend.

## Brzi start (lokalno)

```bash
composer setup     # instalacija, .env, ključ, migracije, npm build
composer run dev   # server, queue, logovi i Vite zajedno
```

Provjere prije commita (iste kao u CI-ju): `composer ci:check` — Pint, PHPStan (nivo 7) i testovi.

## Licenca

Projekat je nastao na osnovu [Laravel Livewire starter kita](https://github.com/laravel/livewire-starter-kit) (MIT licenca).
