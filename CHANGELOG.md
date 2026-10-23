# Changelog

## Laravel 10 / PHP 8.1 za staging server (23. 10. 2026.)

Staging server Mediasky (10.64.43.250) ima PHP 8.1, a na njemu radi najviše Laravel 10. Aplikacija je zato vraćena s Laravela 13 na **Laravel 10.50**, bez promjene funkcionalnosti. Svih 171 testova prolazi na PHP 8.1 i 8.4, a Pint i PHPStan su čisti.

### Paketi
- `laravel/framework` 10.50, `laravel/fortify` 1.36, `spatie/laravel-backup` 8.8, `openspout/openspout` 4.25, `laravel/tinker` 2, PHPUnit 10, Larastan 2. Livewire 4 i Flux ostaju.
- Dodan `doctrine/dbal` 3 (Laravel 10 ga traži za `->change()` u migracijama).
- Uklonjeni `laravel/chisel`, `laravel/pail` i `laravel/pao` (traže PHP 8.2+).
- `composer.json`: `config.platform` je PHP 8.1.2 bez bcmath i redis (kao na serveru), pa composer ne može izabrati paket koji tamo ne radi.

### Sigurnost (prihvaćeno, Ensar, 23. 10. 2026.)
Laravel 10 više nema sigurnosnih ispravki. Tri poznata propusta nemaju ispravku za 10.x i navedena su u `composer.json` → `config.audit.ignore`:
- **CRLF u `email` validaciji** (GHSA-5vg9-5847-vvmq): ublaženo u aplikaciji. Sva `email` pravila imaju i `not_regex:/[\r\n]/`.
- **Signed URL path confusion** (GHSA-crmm-hgp2-wgrp).
- **XSS na debug stranici** (GHSA-jh5r-qr3c-85q8): bitno samo dok je `APP_DEBUG=true`.

### Izmjene koda
- Kostur aplikacije u Laravel 10 obliku: `app/Http/Kernel.php` i middleware, `app/Console/Kernel.php` (raspored, ranije `Schedule::` u `routes/console.php`), `app/Exceptions/Handler.php`, `EventServiceProvider` (backup listener) i `RouteServiceProvider` (uključujući `/up`). Uz to `bootstrap/app.php`, `artisan`, `public/index.php`, te providers/aliases u `config/app.php`.
- Modeli: `#[Fillable]`/`#[Hidden]` i `casts()` pretvoreni u `$fillable`, `$hidden` i `$casts`.
- `RunBackup` i `NotifyAdminsAboutBackup` prilagođeni spatie/laravel-backup 8. Excel izvoz prilagođen openspout 4.
- Zamjene za Laravel 11+ funkcije: `Str::initials` (u `User::initials()`), `Password::toPasswordRulesString` (helper `password_rules_string()`), `DB::prohibitDestructiveCommands` (listener u `AppServiceProvider`) i `$attributes->all()` za livewire/blaze (makro).
- Detalj projekta: napredak se računa na kopiji projekta. Laravel 10 je kod kružnih relacija (projekat → postupci → projekat) ulazio u beskonačnu rekurziju.
- `config/database.php`: `PDO::MYSQL_ATTR_SSL_CA` umjesto PHP 8.4 `Pdo\Mysql`, a `migrations` je ponovo string. `config/cache.php` prihvata i `CACHE_DRIVER`.
- PHPStan: `phpstan-baseline.neon` sadrži greške koje dolaze od starijih generičkih tipova relacija u Laravelu 10.
- CI (GitHub Actions) radi na PHP 8.1.
- `.env.staging.example`: postavke za staging (MySQL `ensar_app`, mail u log, queue `sync`, upload do 2 MB).
- **Migracije na MySQL-u:** dva foreign key-a i jedan indeks dobili su kraća, eksplicitna imena, jer je automatsko ime bilo duže od 64 znaka (MySQL limit). Na SQLite-u se to nije vidjelo.

### Lokalno na MySQL-u (23. 10. 2026.)
- Lokalna baza je prebačena sa SQLite-a na MySQL (servis MySQL80, baza `media_sky_project`, korisnik `mediasky`). Svi podaci su preneseni (34 tabele, 110 redova). `database/database.sqlite` je ostao netaknut, a kopija je u `storage/app/backups/`.
- `app:copy-database`: prazan tekst u kolonama za datum ili broj prenosi se kao `NULL` (MySQL ga inače odbija).
- `DB_DUMP_BINARY_PATH`: folder s `mysqldump` za `app:backup` kad nije u PATH-u (Windows).
- **Sve je na MySQL-u:** testovi (`phpunit.xml`) rade na `media_sky_project_testing`, podrazumijevana konekcija u configu i `.env.example` je `mysql`, a CI (GitHub Actions) ima MySQL 8.0 servis i pokreće se i za granu `laravel10-php81`. Dokumentacija je ažurirana.
- Stari `database/database.sqlite` je obrisan. Provjerena kopija je u `storage/app/backups/database-before-mysql-20261023-1549.sqlite`. Alati za prenos iz SQLite-a (`app:copy-database`) i backup SQLite fajla ostaju za prenos starih podataka.

### Deploy na staging (23. 10. 2026.)
- http://10.64.43.250:8091/ (`/var/www/html/ensar-app`, baza `ensar_app`). Uvezeni su lokalni podaci: 1 korisnik, 2 projekta, 4 postupka i 15 stavki s historijom.
- Prazan `planned_end_date` (`''` u SQLite-u) je pri prenosu pretvoren u `NULL`.
- **Prazna polja na MySQL-u:** forme su prazne datume i ID-eve slale kao `''`. SQLite to prihvata, a MySQL odbija (npr. greška 500 pri kreiranju projekta bez planiranog kraja). Prije snimanja svakog modela `''` u kolonama za datum, broj ili strani ključ (`*_id`) sada postaje `NULL` (`AppServiceProvider`, uz test).
- **Provjera prije testne faze:**
  - Svih 175 testova prolazi na SQLite-u i na MySQL 8.0.46, istoj verziji kao na serveru. Testovi se na MySQL-u pokreću s `DB_CONNECTION=mysql DB_DATABASE=<ime>_testing`; zaštita u `tests/TestCase.php` dozvoljava samo bazu čije ime završava na `_testing`.
  - Na stagingu se svih 73 stranica (svaki projekat, postupak, stavka, institucija, postavke) otvara bez greške.
- **Postavke za staging** (u `.env.staging.example`):
  - `HEALTH_SCHEDULER_EXPECTED=false`: bez žutog upozorenja o scheduleru na svakoj stranici, jer na stagingu namjerno nema crona. Stanje se i dalje vidi u Stanje sistema.
  - `APP_TOP_BANNER_OFFSET=28`: sadržaj, zaglavlje i mobilni meni pomjeraju se ispod serverske STAGING trake. Visina se mjeri u browseru, pa radi i kad se traka na mobitelu prelomi u dva reda.
- **Automatsko snimanje polja:** "Trenutno stanje" i "Sljedeći korak" na projektu, te naziv, opis i primalac u panelu stavke nisu se snimali. U Livewire 4, `wire:model.blur` samo osvježi vrijednost u browseru i ništa ne šalje serveru (u Livewire 3 je slao), pa je tekst nestajao. Sada je `wire:model.live.blur`, a test sprječava da se stari oblik vrati. Greška je postojala i prije prelaska na Laravel 10.
- **Zakrpa za Livewire JS:** Apache na stagingu ubacuje HTML trake "STAGING ENVIRONMENT" iza svakog `<body>`, pa i usred `livewire.js`. Fajl se zbog toga kvario i Livewire/Alpine nisu radili (strelice, statusi, modali). Livewire JS se sada servira preko `LivewireScriptController` s rute `…/livewire.p1.js`, s tekstom `<body` zapisanim kao JS escape. Zakrpa se može ukloniti kad se filter na serveru ograniči na `text/html`.

## Media Sky Project – nadogradnja (oktobar 2026.)

Aplikacija (ranije ProjectFlow) preuređena prema planu iz `PROJECTFLOW_TASKS.md`. U UI-ju: Predmet → **Postupak**, Zadatak → **Stavka**.

### Novo
- **Model podataka:** statusi stavki s bojama na jednom mjestu, automatski datumi predaje/odgovora, status postupka izračunat iz stavki, administrativni napredak projekta, ručna procjena, članovi projekta s ulogama.
- **Layout:** sidebar s filterima projekata i brojačima, globalna pretraga (Ctrl+K), zvono s obavijestima, svijetla i tamna tema, prilagođeno mobitelu.
- **Lista projekata:** kartice s napretkom, statusom, periodom, članovima i najviše 3 stavke koje traže pažnju; pretraga, filter i sortiranje u URL-u.
- **Detalj projekta:** postupci kao harmonika s tabelom stavki, promjena statusa klikom na chip, brzi unos stavke, drag & drop, desna kolona (trenutno stanje, sljedeći korak, zahtijeva pažnju, aktivnosti, dokumenti, linkovi, članovi).
- **Detalj stavke:** bočni panel s direktnim linkom, dugmad za tok rada (predato, evidentiraj odgovor, dopuna, završi), checklist.
- **Dokumenti:** upload na projekat, postupak, stavku i komentar; verzije; privatni storage i preuzimanje uz provjeru pristupa; pregled PDF-a i slika; stranica "Dokumenti projekta" s filterima.
- **Komentari i linkovi:** automatski linkovi, osnovno formatiranje, zaštita od XSS-a, prilozi, @spominjanje, uređivanje i brisanje; linkovi samo http/https.
- **Historija:** sve važne promjene zapisuju se kao rečenica ("Ensar M. je promijenio status iz U pripremi u Predato"); prekidač Komentari | Aktivnost | Sve; puna historija s filterima. Oblik obraćanja (je promijenio / je promijenila) bira se u profilu.
- **Moje stavke:** grupe Kasni, Čeka odgovor, Rok ove sedmice, Kasnije, Bez roka; brza promjena statusa.
- **Obavijesti:** dodjela stavke, članstvo, @spominjanje, komentar ili dokument na mojoj stavci, promjena statusa, rok sutra / istekao, čekanje odgovora duže od 10 dana; padajuća lista na zvonu, "Označi sve kao pročitano". Bez obavijesti za vlastite akcije.
- **Brending:** naziv Media Sky Project, logo u sidebaru, na prijavi i u PDF izvještaju, nova ikona (favicon). Logo ima dvije verzije: `public/img/logo-light.png` za svijetlu i `public/img/logo-dark.png` za tamnu temu, bez treperenja pri učitavanju. Dok `logo-dark.png` ne postoji, tamna tema koristi svijetli logo s privremenim filterom (`.logo-dark-fallback`); čim se fajl doda, koristi se on, bez izmjene koda.

### Kvalitet (Task 11)
- Indeksi u bazi za postupke po projektu, članstvo po korisniku, historiju po korisniku i nepročitane obavijesti (aditivna migracija).
- Sve poruke validacije, prijave, lozinki i paginacije na bosanskom (ranije su se prikazivali ključevi poput `validation.required`); prevedeni i ekrani postavki sigurnosti (2FA, passkey).
- Ispravljen raspored reda postupka: naziv se više ne skraćuje na desktopu ni na mobitelu.
- Stranica za prijavu prati odabranu temu umjesto da je uvijek tamna.
- Testovi uključuju tuđi dokument, validaciju uploada, status postupka, napredak projekta, "Zahtijeva pažnju" s graničnim slučajevima i obavijesti za @spominjanje.

### Dorada nakon Taska 11
- Meni akcija stavke i izbor statusa prikazuju se iznad tabele (ne odsijeca ih horizontalni skrol); puni nazivi stavki, kolone Stavka i Akcije uvijek vidljive.
- Nova stranica Postavke → Obavijesti: vrste obavijesti, kanali (aplikacija, e-mail) i dnevni sažetak.
- Preostali tekstovi "predmet/zadatak" zamijenjeni sa "postupak/stavka".
- **CI:** GitHub Actions na PHP 8.4 (kao i `composer.json`); provjere (Pint, PHPStan, testovi) prolaze.
- **PHPStan (nivo 7): 0 grešaka** (ranije 296). Modeli imaju tipove kolona i relacija, servisi tipove nizova i povratnih vrijednosti.
- Uklonjen `rename_livewire_files.php` (skripta starter kita koja se pokretala samo pri `composer create-project`).

### Napomene za pokretanje
- Nove migracije: `php artisan migrate`.
- Obavijesti idu kroz queue: potreban je `php artisan queue:work` (ili supervisor) i `php artisan schedule:work` / cron za dnevne podsjetnike.
- Logo u PDF izvještaju traži PHP ekstenziju GD; bez nje se ispisuje tekstualni naziv.

### Produkcija, backup i izvještaji (Taskovi 12–17)
- **Čišćenje (Task 12):** uklonjeni nekorišteni fajlovi starter kita; prijava bez passkey dugmeta.
- **Baza (Task 13, u pripremi):** lokalno ostaje SQLite bez izmjena. Prelazak na MySQL je pripremljen, ali se radi tek na produkcijskom serveru: MySQL blok u `.env.example`, komanda `php artisan app:copy-database sqlite mysql` za prenos podataka, dužine polja usklađene s MySQL kolonama.
- **Queue i scheduler:** dodani su zapisi posljednjeg rada, upozorenje administratoru, stranica **Stanje sistema**, upravljanje neuspjelim poslovima i komanda `php artisan app:health`; produkcijska dokumentacija sadrži Supervisor, cron i Windows upute.
- **Backup:** dnevni backup baze i privatnih dokumenata, raspored čišćenja i nadzora, politika čuvanja, obavijesti administratorima, ručno pokretanje kroz queue i postupak vraćanja iz backupa.
- **Excel izvoz:** projekt se izvozi u formatirani XLSX s listovima Postupci, Stavke i Dokumenti, uz autorizaciju, aktivne filtere, Excel datume i istaknute statuse/kašnjenja.
- **Institucije:** nova lista institucija s pokazateljima pažnje i pretragom te detalj s kontaktnim podacima, otvorenim stavkama grupisanim po stanju, brzom promjenom statusa, kopiranjem liste i XLSX izvozom. Podaci su ograničeni na projekte koje korisnik smije vidjeti i učitavaju se bez N+1 upita.
- Slobodno uneseni primaoci iz starijih stavki nisu automatski povezivani s institucijama; postojeći tekst ostaje sačuvan dok se ne odobri mapiranje.

### Stabilnost i brzina (21.10.2026.)
- **Napredak projekta** je prosjek postupaka: svaki postupak nosi jednak dio, unutar postupka se gleda udio završenih stavki (3 od 4 završena postupka = 75 %). Postupak bez stavki računa se kao neurađen; arhivirani postupci i otkazane stavke se ne računaju.
- **Zaštita baze:** testovi se sami zaustave ako bi radili nad pravom bazom (npr. kad je konfiguracija keširana).
- **`start.ps1`:** lokalno pokretanje servera s OPcache-om (~4x brže stranice), queue workera i schedulera; rute i eventi se keširaju svježe pri svakom pokretanju, konfiguracija nikad.
- CI: uklonjen prazan Unit testsuite koji je rušio GitHub Actions.

## Prijedlog sljedećih koraka
1. **Referentni dizajn:** `docs/dizajn-projekat.png` ne postoji u repozitoriju. Kad se doda, uraditi vizuelno poređenje razmaka i veličina.
2. **Tamni logo:** dodati `public/img/logo-dark.png`; do tada radi privremeni filter.
3. **Obavijesti u realnom vremenu:** zvono se sada osvježava svakih 60 s; uz Laravel Reverb moguće je trenutno.
4. **E-mail obavijesti:** kanal postoji, ali većina korisnika nema e-mail adresu. Dogovoriti da li se e-mail koristi.
5. **Integracija s protokolom:** broj protokola se unosi ručno; povezati s internom protokolarnom aplikacijom (`docs/PROTOCOL_INTEGRATION.md`).
6. **Izvještaji:** proširiti PDF izvještaj na postupke i rokove po instituciji te dodati izvoz u Excel.
