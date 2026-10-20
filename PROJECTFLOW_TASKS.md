# ProjectFlow – plan nadogradnje

Sačuvaj referentni dizajn kao `docs/dizajn-projekat.png` u projektu.
Ovaj fajl stavi u root projekta.
U Claude Code napiši: "Pročitaj PROJECTFLOW_TASKS.md i pogledaj docs/dizajn-projekat.png. Radi task po task, počni od Taska 0."

## Pravila rada (vrijede za sve taskove)

- Referentni dizajn je `docs/dizajn-projekat.png`. Raspored, hijerarhija, boje statusa i gustina informacija trebaju odgovarati slici. Kad nisi siguran kako nešto treba izgledati, pogledaj sliku.
- Prvo istraži postojeći kod. Ne pretpostavljaj stack ni nazive. Prilagodi se postojećim modelima, rutama i komponentama.
- Terminologija: postojeći Predmet = **Postupak** u UI-ju, postojeći Zadatak = **Stavka** u UI-ju. Ako u kodu već postoje drugačiji odnosi, javi mi u Tasku 0 prije nego išta mijenjaš.
- Jedan task po jedan. Nakon taska napiši kratak sažetak (šta je promijenjeno, koji fajlovi), napravi commit `TASK N: <naslov>`, pa stani i čekaj moju potvrdu.
- Ne briši postojeće podatke. Migracije su aditivne. Za preimenovanje ili brisanje kolona prvo me pitaj.
- UI je na bosanskom jeziku, datumi u formatu dd.mm.yyyy.
- Autorizacija svuda. Korisnik vidi samo projekte kojih je član ili ako je admin.
- Nakon svakog taska pokreni testove i lint i popravi sve što pukne prije commita.
- Ako je nešto nejasno ili se kosi s postojećim kodom, pitaj me umjesto da nagađaš.

## TASK 0 – Analiza i plan (bez izmjena koda)

- Utvrdi stack, auth, način renderovanja i postojeće modele i relacije (Projekat, Predmet, Zadatak, Korisnik, Obavijest).
- Uporedi postojeće stanje s referentnim dizajnom i ovim planom.
- Pronađi zašto se zaglavlje tabele na /projekti prikazuje vertikalno.
- Izlaz: izvještaj s listom šta postoji, šta fali, planirane migracije i mapiranje Predmet→Postupak, Zadatak→Stavka.

## TASK 1 – Model podataka

**Projekat** (dodati šta fali)
- naziv, opis (npr. "Podzemna optička mreža – GPON"), lokacija, kategorija (npr. Telekomunikacije), datum_pocetka, datum_zavrsetka
- status: nije_pokrenuto, u_toku, na_cekanju, zavrseno, arhivirano
- rucna_procjena (0–100, ručno se postavlja)
- trenutno_stanje (kratak tekst), sljedeci_korak (kratak tekst)
- Članovi projekta (pivot): korisnik + uloga (voditelj, clan)

**Postupak** (postojeći Predmet)
- naziv, institucija (npr. Općina Banovići, JP EPBiH), ikona/tip (opcionalno), pozicija
- Status postupka se izračunava iz stavki: sve završene → Završeno; ima "Čeka odgovor" → Čeka odgovor; ima u toku/u pripremi/predato → U toku; ništa nije pokrenuto → Nije pokrenuto.

**Stavka** (postojeći Zadatak)
- redni_broj, naziv, institucija_primalac
- status: nije_pokrenuto, u_pripremi, predato, ceka_odgovor, potrebna_dopuna, zavrseno, otkazano
- rok, datum_predaje, datum_odgovora (nullable)
- odgovorna_osoba_id, opis, pozicija
- Automatski: prelaz u predato postavlja datum_predaje ako je prazan; prelaz u zavrseno ili unos odgovora postavlja datum_odgovora.

**Dokument** (polimorfan: projekat, postupak, stavka, komentar)
- naziv, smjer (izlazni, ulazni, interni), broj_protokola (npr. 03-23-456/26), datum_dokumenta, verzija, originalni_naziv, putanja, mime, velicina, korisnik_id
- Nova verzija istog dokumenta čuva i stare verzije.

**Komentar** (polimorfan: stavka, postupak) i **Link** (polimorfan: stavka, postupak, projekat).

**Aktivnost** – log: korisnik, subjekt (polimorfno), akcija, podaci (JSON staro/novo).

Izračunato:
- Administrativni napredak projekta = završene stavke / sve stavke (bez otkazanih), u %.
- Kasni = rok prošao, a stavka nije završena ili otkazana.
- Dana čeka odgovor = danas − datum_predaje za stavke u ceka_odgovor.

Statuse s labelama i bojama drži na jednom mjestu (enum/konstante). Boje prema slici: Završeno zeleno, Čeka odgovor narandžasto, Predato i U toku plavo, U pripremi svijetloplavo, Nije pokrenuto sivo, Potrebna dopuna crveno.

Seeder: projekat "FTTH Banovići" s postupcima i stavkama kao na slici, plus još 2 projekta.

## TASK 2 – Layout aplikacije

Prema slici:
- Lijevi sidebar: logo, Projekti, Postavke; sekcija PROJEKTI s filterima i brojačima: Svi projekti, U toku, Na čekanju, Završeni, Arhivirani; Pomoć na dnu.
- Gornja traka: globalna pretraga (Ctrl+K pretražuje projekte, postupke, stavke i dokumente), zvono s brojem nepročitanih obavijesti, avatar s imenom i menijem.
- Svijetla tema kao na slici. Ako aplikacija već ima tamnu temu, zadrži prekidač teme i napravi da obje izgledaju dobro.
- Responsive: na mobitelu sidebar postaje meni, a desna kolona projekta ide ispod glavnog sadržaja.

## TASK 3 – Lista projekata /projekti

Jednostavna i pregledna lista, bez stare tabele:
- Kartica ili red po projektu: naziv, opis, lokacija, status (chip), administrativni napredak (progress bar + %), period, avatari članova.
- Ispod naziva prikaži samo ono što traži pažnju (max 3 stavke: kasni, čeka odgovor dugo, rok uskoro). Ako nema ništa, ne prikazuj.
- Pretraga, filter po statusu (veže se na sidebar), sortiranje (posljednja aktivnost, rok, naziv).
- Filteri u URL query parametrima.
- Dugme "+ Dodaj projekat".

## TASK 4 – Detalj projekta (glavni ekran, prema slici)

**Zaglavlje**
- Breadcrumb (Projekti > naziv), naziv s ikonom olovke za uređivanje, opis.
- Chipovi: lokacija, kategorija, period.
- Desno: dropdown "Status projekta", Administrativni napredak (auto progress bar + %), Ručna procjena stanja (progress bar, klik otvara slider za promjenu).

**Glavni dio – "Postupci u projektu"**
- Filter tabovi s brojačima: Svi, U toku, Čeka odgovor, Završeno, Nije pokrenuto.
- Dugme "+ Dodaj postupak".
- Svaki postupak je harmonika (accordion): ikona, naziv, institucija, "6 / 10 stavki" + progress bar + %, status chip, "Posljednja aktivnost" (datum + opis), strelica za otvaranje.
- Otvoren postupak prikazuje tabelu stavki: #, Stavka, Status, Institucija/primalac, Rok, Predaja, Odgovor, Dokumenti (ikona + broj, klik otvara dokumente stavke), Odgovorna osoba (avatar + ime), Akcije (⋯ meni: uredi, promijeni status, dodaj dokument, dupliciraj, obriši).
- Status u tabeli se mijenja klikom na chip (dropdown), bez otvaranja stavke.
- Rok crvenom ako kasni, narandžastom ako je za ≤3 dana.
- Red "+ Dodaj stavku" na dnu tabele (brzi unos naziva).
- Drag & drop za redoslijed stavki i postupaka.
- Zapamti koji su postupci otvoreni (localStorage).
- Bez N+1 upita.

**Desna kolona**
- Trenutno stanje i Sljedeći korak – dvije kartice, uređuju se inline.
- Zahtijeva pažnju (crveni brojač) – automatski: stavke koje kasne, čekaju odgovor duže od 10 dana (konfigurabilno), ili imaju rok u narednih 5 dana. Prikaz: naziv, razlog ("Čeka se odgovor (12 dana)", "U pripremi, rok za 3 dana"), datum. Klik otvara stavku.
- Posljednje aktivnosti – zadnjih 5 s ikonom po tipu, datumom i korisnikom; "Sve" otvara punu historiju.
- Dokumenti projekta – zadnja 4 dokumenta: PDF ikona, naziv, datum, smjer, broj protokola ili verzija, ⋯ meni; "Sve" otvara sve dokumente projekta.
- Članovi projekta – avatari s ulogom, "Uredi" i "+ Dodaj".

## TASK 5 – Detalj stavke (bočni panel)

Otvara se klikom na stavku, ima i svoju URL adresu za direktan link.
- Naziv, opis (linkovi klikabilni), breadcrumb Projekat > Postupak.
- Polja: status, institucija/primalac, rok, datum predaje, datum odgovora, odgovorna osoba. Promjena se snima odmah.
- Dugmad za tok rada: "Označi kao predato", "Evidentiraj odgovor" (datum + broj protokola + upload dopisa u jednom koraku), "Potrebna dopuna", "Završi".
- Checklist (podzadaci) s brojem "3/5".
- Sekcije: Dokumenti, Linkovi, Komentari i aktivnost.

## TASK 6 – Dokumenti i upload

- Upload na projekat, postupak, stavku i komentar. Drag & drop, više fajlova odjednom, progress po fajlu.
- Pri uploadu (opcionalno) se unosi: smjer (izlazni/ulazni/interni), broj protokola, datum dokumenta.
- Verzije: "Nova verzija" čuva staru; prikaz "Verzija 2" i historija verzija.
- Tipovi: PDF, slike, Office, txt, csv, zip, dwg/dxf. Limit konfigurabilan (default 25 MB) s jasnom porukom.
- Privatni storage, preuzimanje samo kroz rutu s provjerom pristupa.
- Pregled: PDF i slike u pregledniku (lightbox), ostalo download.
- Stranica "Dokumenti projekta": svi dokumenti iz projekta, filteri po postupku, smjeru i tipu, pretraga po nazivu i broju protokola.
- Brisanje: autor ili voditelj projekta/admin, uz potvrdu.

## TASK 7 – Komentari i linkovi

**Komentari** (na stavci i postupku)
- URL-ovi automatski klikabilni (novi tab, rel="noopener"), osnovno formatiranje (bold, liste, kod), sanitizacija protiv XSS.
- Prilog direktno u komentaru (dugme ili drag & drop).
- @spominjanje članova projekta s autocomplete-om, spomenuti dobija obavijest.
- Uređivanje (oznaka "uređeno") i brisanje vlastitih; admin briše sve. Ctrl/Cmd+Enter šalje.

**Linkovi** – sekcija na stavci, postupku i projektu: URL (samo http/https) + opcionalni naslov (inače prikaži domenu), uređivanje i brisanje.

## TASK 8 – Aktivnost i historija

- Loguj: kreiranje, promjene statusa, rokova, odgovorne osobe, predaju, odgovor, dokumente, linkove, komentare i promjene projekta (trenutno stanje, sljedeći korak, ručna procjena).
- U stavci: prekidač "Komentari | Aktivnost | Sve".
- Puna historija projekta s filterom po tipu i korisniku.
- Format: "Ensar M. je promijenio status iz U pripremi u Predato · 18.04.2026."

## TASK 9 – Moje stavke

- Stavka u sidebaru (ili kartica na vrhu liste projekata): sve stavke gdje sam odgovorna osoba, iz svih projekata.
- Grupisano: Kasni, Čeka odgovor, Rok ove sedmice, Kasnije, Bez roka.
- Brza promjena statusa iz liste, klik otvara bočni panel.

## TASK 10 – Obavijesti

Obavijest kad:
- postanem odgovorna osoba za stavku ili član projekta,
- me neko @spomene,
- neko komentariše ili doda dokument na mojoj stavci,
- se promijeni status moje stavke,
- je rok sutra ili je istekao, ili stavka čeka odgovor duže od 10 dana (scheduled job, jednom dnevno).

Zvono s brojem nepročitanih, lista s linkom direktno na stavku ili komentar, "Označi sve kao pročitano". Ne šalji obavijest za vlastitu akciju.

## TASK 11 – Dorada i kvalitet

- Uporedi gotov ekran sa `docs/dizajn-projekat.png` i ispravi razlike u razmacima, veličinama, bojama i poravnanju.
- Responsive (mobitel, tablet, desktop), keyboard navigacija, focus stanja.
- Loading, prazna stanja i poruke grešaka na bosanskom svuda.
- Indeksi u bazi (status, rok, odgovorna osoba, postupak, projekat).
- Testovi: tuđi dokument se ne može preuzeti, upload validacija, izračun statusa postupka i napretka projekta, logika "Zahtijeva pažnju", obavijest za @spominjanje.
- Na kraju kratak CHANGELOG i prijedlog sljedećih koraka.
