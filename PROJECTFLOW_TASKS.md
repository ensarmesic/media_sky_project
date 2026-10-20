# ProjectFlow – plan nadogradnje

Ovaj fajl stavi u root projekta. U Claude Code napiši: "Pročitaj PROJECTFLOW_TASKS.md i radi task po task. Počni od Taska 0."

## Pravila rada (vrijede za sve taskove)

- Prvo istraži postojeći kod. Ne pretpostavljaj stack ni nazive. Prilagodi se postojećim modelima, rutama, stilu i komponentama.
- Jedan task po jedan. Kad završiš task, napravi kratak sažetak (šta je promijenjeno, koji fajlovi) i commit s porukom `TASK N: <naslov>`. Onda stani i čekaj moju potvrdu prije sljedećeg taska.
- Ne briši postojeće podatke. Migracije moraju biti aditivne. Ako nešto mora da se preimenuje ili obriše, prvo me pitaj.
- UI je na bosanskom jeziku. Kod (varijable, klase, kolone) ostaje u stilu koji projekat već koristi.
- Zadrži postojeći izgled (tamna tema, plavi akcent, sidebar). Nove stvari trebaju izgledati kao da su oduvijek bile tu.
- Autorizacija svuda. Korisnik vidi i mijenja samo ono čemu ima pristup (projekti kojih je član ili admin).
- Nakon svakog taska pokreni postojeće testove i lint. Ako nešto pukne, popravi prije commita.
- Ako nešto u ovom planu nije jasno ili se kosi s postojećim kodom, pitaj me umjesto da nagađaš.

## TASK 0 – Analiza i plan

- Utvrdi stack (framework, frontend, baza, auth, kako se renderuje /projekti).
- Mapiraj postojeće modele: Projekat, Predmet, Zadatak, Korisnik, Obavijest i njihove relacije i statuse.
- Pronađi zašto se zaglavlje tabele na /projekti prikazuje vertikalno (PROJEKAT, STATUS... jedno ispod drugog).
- Izlaz: kratak izvještaj + lista šta od taskova ispod već postoji, šta treba dodati i koje migracije planiraš. Ne mijenjaj kod u ovom tasku.

## TASK 1 – Model podataka

Proširi (ili dodaj ako ne postoji):

**Zadatak**
- status: novo, u_toku, na_cekanju, na_pregledu, zavrseno, otkazano
- prioritet: nizak, srednji, visok, hitno
- rok (datum, nullable)
- dodijeljen_korisnik_id (nullable)
- predmet_id
- pozicija (int, za redoslijed na ploči)
- zavrseno_at (timestamp, nullable; postavlja se automatski kad status pređe u zavrseno)

**Komentar** – polimorfan (može na zadatak ili predmet): korisnik_id, tekst, edited_at, soft delete.

**Prilog** – polimorfan (zadatak, predmet ili komentar): korisnik_id, originalni_naziv, putanja, mime, velicina.

**Link** – polimorfan (zadatak ili predmet): korisnik_id, naslov, url.

**Aktivnost** – log: korisnik_id, subjekt (polimorfno), akcija, podaci (JSON: staro/novo).

- Izračunato "kasni": rok < danas i status nije zavrseno ili otkazano.
- Centralizuj statuse i prioritete na jednom mjestu (enum/konstante) s labelama na bosanskom i bojama.
- Dodaj seeder s realnim demo podacima (2–3 projekta, nekoliko predmeta, zadaci u svim fazama, par komentara i linkova).

Gotovo kad: migracije prolaze na čistoj i postojećoj bazi, a postojeći podaci ostaju netaknuti.

## TASK 2 – Nova stranica /projekti (glavni prikaz)

Zamijeni trenutnu tabelu jednostavnim prikazom:

- Grupisano po projektu – naziv projekta je mali naslov (lokacija ispod, sitno), klik vodi na detalje projekta.
- Ispod su kartice predmeta u gridu (responsive: 1 kolona na mobitelu, 2–3 na desktopu).
- Svaka kartica: naziv predmeta, broj aktivnih zadataka, tanak progress bar (završeno/ukupno), i lista aktivnih zadataka (svi osim zavrseno i otkazano).
- Red zadatka: naziv, chip statusa (u boji), avatar dodijeljenog, rok (crveno ako kasni), ikonica ako ima priloge ili komentare (s brojem).
- Predmeti bez aktivnih zadataka se ne prikazuju (prekidač "Prikaži i završene predmete").
- Klik na zadatak otvara detalj zadatka (Task 3).
- Gore: pretraga (naziv projekta, predmeta ili zadatka), filter chipovi po fazi (Sve / U toku / Na čekanju / Na pregledu / Kasni), filter "Samo moji", filter po projektu.
- Filteri i pretraga idu u URL query parametre (da se može podijeliti link i da back radi).
- Dugme "+ Dodaj zadatak" na svakoj kartici predmeta (brzi unos: samo naziv + Enter, status novo).
- Prazno stanje: "Nema aktivnih zadataka" + dugme za dodavanje.
- Bez N+1 upita (eager loading, count upiti).

Gotovo kad: stranica se učitava brzo i s 50+ zadataka, filteri rade, a stari bug sa zaglavljem je nestao.

## TASK 3 – Detalj zadatka

Otvara se kao bočni panel (drawer) sa stranice projekata, a ima i svoju URL adresu (/zadaci/{id}) za direktan link.

- Naslov (edit inline), opis (višelinijski, linkovi u tekstu klikabilni).
- Polja uz bok: status, prioritet, dodijeljeni korisnik, rok, predmet/projekat (breadcrumb).
- Promjena polja se snima odmah (bez dugmeta "Spremi"), uz kratku potvrdu.
- Checklist (podzadaci): dodaj, štikliraj, obriši, promijeni redoslijed; prikaz "3/5".
- Sekcije ispod: Prilozi (Task 5), Linkovi (Task 6), Komentari i aktivnost (Task 4 i 7).
- Dugmad: "Označi završenim", "Dupliciraj", "Obriši" (uz potvrdu).

## TASK 4 – Komentari

- Komentari na zadatku i na predmetu.
- Tekst s automatskim prepoznavanjem URL-ova (klikabilni, otvaraju se u novom tabu, rel="noopener").
- Osnovno formatiranje: novi red, bold, kod, liste (markdown ili jednostavan editor; sanitizuj HTML protiv XSS).
- Prilog u komentaru: dugme za fajl i drag & drop direktno u polje komentara.
- @spominjanje korisnika (autocomplete) → spomenuti dobija obavijest.
- Autor može urediti (prikaži "uređeno") i obrisati svoj komentar; admin može obrisati bilo koji.
- Ctrl/Cmd + Enter šalje komentar.

## TASK 5 – Prilozi (upload fajlova)

- Upload na zadatak, predmet i komentar.
- Drag & drop zona + klasično dugme, više fajlova odjednom, progress bar po fajlu.
- Dozvoljeni tipovi: slike, PDF, Office (doc/docx/xls/xlsx/ppt/pptx), txt, csv, zip, dwg/dxf. Limit veličine konfigurabilan (default 25 MB), jasna poruka greške.
- Fajlovi se čuvaju u privatnom storage-u. Preuzimanje ide kroz rutu koja provjerava pristup, nikad direktan javni link.
- Prikaz: ikonica po tipu, naziv, veličina, ko je i kad uploadovao.
- Pregled: slike kao thumbnail i lightbox, PDF u pregledniku; ostalo download.
- Brisanje: autor ili admin, uz potvrdu (briše i fajl s diska).
- Na predmetu: sekcija "Svi prilozi" koja skuplja priloge s predmeta i svih njegovih zadataka.

## TASK 6 – Linkovi

- Sekcija "Linkovi" na zadatku i predmetu: dodaj URL + opcionalni naslov (ako naslov nije unesen, prikaži domenu).
- Validacija URL-a (samo http/https).
- Ikonica/favicon po domeni ako je jednostavno izvodljivo; ako nije, generička ikonica.
- Uređivanje i brisanje.

## TASK 7 – Historija aktivnosti

- Loguj: kreiranje, promjene statusa, prioriteta, roka i dodjele, dodavanje i brisanje priloga i linkova, komentare.
- U detalju zadatka: tab ili prekidač "Komentari | Aktivnost | Sve", hronološki.
- Format: "Ensar je promijenio status iz U toku u Na pregledu · prije 5 min".

## TASK 8 – Ploča (Kanban) po projektu

- Na stranici projekta prekidač Lista / Ploča.
- Ploča: kolone po statusu, kartice zadataka (naziv, predmet, avatar, rok, prioritet).
- Drag & drop između kolona mijenja status, a unutar kolone redoslijed (pozicija). Snima se odmah i loguje u aktivnost.
- Filter po predmetu i po dodijeljenom korisniku.

## TASK 9 – Moji zadaci

- Svi zadaci dodijeljeni meni, grupisani: Kasni, Danas, Ova sedmica, Kasnije, Bez roka.
- Brza promjena statusa iz liste (dropdown na chipu), klik otvara drawer.

## TASK 10 – Obavijesti

Obavijest se šalje kad:

- mi je dodijeljen zadatak,
- me neko spomene u komentaru,
- neko komentariše na zadatku koji je moj,
- se promijeni status mog zadatka,
- se rok približava (1 dan prije) ili je istekao (scheduled job).

Badge s brojem nepročitanih u sidebaru, lista s linkom direktno na zadatak/komentar, "Označi sve kao pročitano". Ne šalji obavijest korisniku za njegovu vlastitu akciju.

## TASK 11 – Dorada i kvalitet

- Responsive provjera (mobitel, tablet, desktop), keyboard navigacija, focus stanja.
- Loading i prazna stanja svuda, poruke grešaka na bosanskom.
- Konzistentne boje statusa svuda (lista, ploča, drawer, moji zadaci).
- Indeksi u bazi za česte upite (status, rok, dodijeljen, predmet).
- Testovi za: autorizaciju priloga (tuđi fajl se ne može preuzeti), upload validaciju, promjenu statusa + aktivnost, filtere na /projekti, obavijesti za @spominjanje.
- Na kraju: kratak CHANGELOG šta je urađeno i šta bi bio sljedeći korak.
