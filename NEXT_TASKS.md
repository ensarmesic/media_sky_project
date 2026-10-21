Media Sky Project – sljedeći koraci

Stavi ovaj fajl u root projekta, a logo-dark.png u public/img/. U Claude Code napiši: "Pročitaj NEXT_TASKS.md i radi task po task, počni od Taska 12."

Pravila rada

Vrijede ista pravila kao u PROJECTFLOW_TASKS.md:

jedan task po jedan, sažetak i commit TASK N: <naslov>, pa stani i čekaj potvrdu;
migracije su aditivne, bez brisanja podataka bez pitanja;
UI je na bosanskom, datumi u formatu dd.mm.yyyy.;
autorizacija svuda;
nakon svakog taska pokreni composer ci:check (testovi, Pint, PHPStan) i popravi sve što pukne.

Ako nešto u ovom planu nije u skladu s postojećim kodom, pitaj me prije nego nastaviš.

TASK 12 – Čišćenje
Obriši rename_livewire_files.php iz roota (ostatak starter kita). Prije toga provjeri da ga ništa ne poziva (composer.json skripte, CI).
Provjeri da postoji public/img/logo-dark.png. Ako postoji, brand-logo treba koristiti njega, a klasa .logo-dark-fallback se ne primjenjuje. Provjeri sidebar, prijavu i favicon u obje teme. CSS za fallback ostavi kao rezervu.
U CHANGELOG.md ispravi tačku o PHPStanu (sada je 0 grešaka) i tačku o tamnom logu.
Pregledaj README.md i docs/INSTALLATION.md: zahtjevi (PHP 8.4, ekstenzije gd, zip, intl, sqlite/pdo) i koraci moraju odgovarati stvarnom stanju.
TASK 13 – Baza za produkciju
Utvrdi koju bazu aplikacija koristi u produkciji (docs/PRODUCTION.md, .env.example). Javi mi šta si našao prije izmjena.
Ako ostaje SQLite:
uključi WAL mod, busy_timeout (npr. 5000 ms) i synchronous=NORMAL kroz konfiguraciju konekcije u config/database.php (Laravel podržava journal_mode, busy_timeout, synchronous);
provjeri da queue (database driver) i aplikacija ne dobijaju "database is locked" pod opterećenjem; napiši kratak test ili skriptu koja to simulira.
Pripremi i MySQL/MariaDB varijantu:
.env.example sa zakomentarisanim MySQL postavkama;
provjeri da sve migracije prolaze na MySQL-u (pazi na json, enum, indekse na tekstualnim kolonama i dužine stringova);
dodaj u CI dodatni job koji pokreće testove na MySQL-u (GitHub Actions services: mysql).
U docs/PRODUCTION.md dopiši kratko kada je dovoljan SQLite, a kada preći na MySQL, i kako prebaciti postojeće podatke.
TASK 14 – Queue i scheduler na serveru

Cilj je da obavijesti i dnevni podsjetnici sigurno rade i da se primijeti kad prestanu.

U docs/PRODUCTION.md dodaj gotove konfiguracije:
supervisor za php artisan queue:work --tries=3 --max-time=3600 (autostart, autorestart, log fajl);
cron linija za php artisan schedule:run svake minute;
za Windows server alternativu (Task Scheduler / NSSM), ako se pokaže da produkcija radi na Windowsu.
Pri deployu treba ići php artisan queue:restart (dopiši u deploy korake).
Health check:
scheduler svake minute upisuje vrijeme zadnjeg pokretanja (cache ili tabela);
queue: kad se job obradi, upisuje se vrijeme zadnje obrade;
u Postavke (samo admin) dodaj karticu "Stanje sistema": zadnje pokretanje schedulera, zadnji obrađeni job, broj jobova na čekanju, broj neuspjelih jobova (failed_jobs) s dugmetom "Pokušaj ponovo" i "Obriši";
ako scheduler nije radio duže od 10 minuta ili ima više od 20 jobova na čekanju, adminu se u zaglavlju prikazuje upozorenje.
Dodaj komandu php artisan app:health koja ispisuje isto stanje (korisno za monitoring i SSH provjeru).
Testovi za logiku upozorenja.
TASK 15 – Backup
Uvedi spatie/laravel-backup.
Backup obuhvata bazu i folder s dokumentima (storage/app/private ili gdje god ProjectDocument::DISK stvarno sprema; provjeri). Isključi vendor, node_modules, cache i logove.
Odredište se konfiguriše kroz .env: podrazumijevano lokalni disk backups, uz pripremljenu opciju za udaljeni disk (S3-kompatibilan ili SFTP). Backup na istom serveru nije dovoljan, pa to jasno napiši u dokumentaciji.
Raspored u scheduleru: backup svaki dan u 02:00, čišćenje starih u 03:00, monitoring u 04:00.
Čuvanje: svi backupi zadnjih 7 dana, dnevni 30 dana, sedmični 8 sedmica, mjesečni 6 mjeseci.
Ako backup ne uspije ili je najnoviji stariji od 26 sati: obavijest adminu (postojeći sistem obavijesti, plus e-mail ako je podešen).
Na karticu "Stanje sistema" (Task 14) dodaj: zadnji uspješan backup, veličinu, broj čuvanih backupa i dugme "Napravi backup sada" (samo admin, ide kroz queue).
U docs/PRODUCTION.md dodaj postupak vraćanja iz backupa korak po korak i preporuku da se vraćanje isproba bar jednom.
Testovi: komanda se registruje u scheduleru, obavijest se šalje kad je backup star.
TASK 16 – Izvoz u Excel
Uvedi biblioteku za XLSX (npr. maatwebsite/excel ili openspout/openspout; izaberi onu koja radi s Laravel 13 i PHP 8.4 i objasni izbor).
Izvoz projekta (dugme pored postojećeg PDF izvještaja na detalju projekta):
list "Postupci": postupak, institucija, broj stavki, završeno, %, status, posljednja aktivnost;
list "Stavke": projekat, postupak, #, stavka, status, institucija/primalac, rok, predaja, odgovor, dana čeka odgovor, odgovorna osoba, broj dokumenata, kasni (da/ne);
list "Dokumenti": naziv, smjer, broj protokola, datum, verzija, postupak/stavka, uploadovao.
Izvoz iz pregleda po instituciji (Task 17) s istim kolonama za stavke.
Formatiranje: zaglavlje podebljano i zamrznuto, autofilter, širine kolona, datumi kao pravi Excel datumi u formatu dd.mm.yyyy., status u boji kao u aplikaciji, crvena za stavke koje kasne.
Naziv fajla: <kod-projekta>_<dd-mm-yyyy>.xlsx.
Izvoz poštuje autorizaciju i filtere koji su trenutno aktivni.
Testovi: fajl se generiše, sadrži očekivane listove i redove, korisnik bez pristupa dobija 403.
TASK 17 – Pregled po instituciji

Nova stranica "Institucije" u sidebaru (ispod "Moje stavke").

Lista institucija: naziv, broj otvorenih stavki, koliko čeka odgovor, koliko kasni, najduže čekanje (u danima). Sortiranje po tome šta traži pažnju; pretraga po nazivu.
Detalj institucije /institucije/{institucija}: sve otvorene stavke te institucije kroz sve projekte koje korisnik smije vidjeti, grupisano:
Čeka odgovor (sortirano po broju dana čekanja),
Kasni,
Za predaju (u pripremi / nije pokrenuto, s rokom),
Potrebna dopuna.
Kolone: projekat, postupak, stavka, status (chip s brzom promjenom), rok, predato, dana čeka, odgovorna osoba, dokumenti. Klik otvara postojeći bočni panel stavke.
Na vrhu detalja: kontakt podaci institucije ako postoje u modelu Institution (adresa, telefon, e-mail, napomena). Ako polja ne postoje, dodaj ih aditivnom migracijom i omogući uređivanje (voditelj projekta ili admin).
Dugme "Izvoz u Excel" (Task 16) i "Kopiraj listu": tekstualni spisak stavki koje čekaju (projekat, stavka, datum predaje, broj protokola), spreman za e-mail ili poziv.
Na detalju projekta naziv institucije u tabeli stavki postaje link na ovu stranicu.
Ako se institucija u starim podacima vodi kao slobodan tekst na nekim mjestima, javi mi prije nego bilo šta spajaš. Napravi prijedlog mapiranja, ne spajaj automatski.
Bez N+1 upita; testovi za autorizaciju (vidi se samo ono iz mojih projekata) i grupisanje.
Na kraju

Ažuriraj CHANGELOG.md sa svim promjenama iz ovih taskova i provjeri da je docs/PRODUCTION.md potpun: baza, queue, scheduler, backup, vraćanje iz backupa i deploy koraci.
