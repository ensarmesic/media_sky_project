# Changelog

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
- **Brending:** naziv Media Sky Project, logo u sidebaru, na prijavi i u PDF izvještaju, nova ikona (favicon).

### Kvalitet (Task 11)
- Indeksi u bazi za postupke po projektu, članstvo po korisniku, historiju po korisniku i nepročitane obavijesti (aditivna migracija).
- Sve poruke validacije, prijave, lozinki i paginacije na bosanskom (ranije su se prikazivali ključevi poput `validation.required`); prevedeni i ekrani postavki sigurnosti (2FA, passkey).
- Ispravljen raspored reda postupka: naziv se više ne skraćuje na desktopu ni na mobitelu.
- Stranica za prijavu prati odabranu temu umjesto da je uvijek tamna.
- Testovi: 122 testa, uključujući tuđi dokument, validaciju uploada, status postupka, napredak projekta, "Zahtijeva pažnju" s graničnim slučajevima i obavijesti za @spominjanje.

### Napomene za pokretanje
- Nove migracije: `php artisan migrate`.
- Obavijesti idu kroz queue: potreban je `php artisan queue:work` (ili supervisor) i `php artisan schedule:work` / cron za dnevne podsjetnike.
- Logo u PDF izvještaju traži PHP ekstenziju GD; bez nje se ispisuje tekstualni naziv.

## Prijedlog sljedećih koraka
1. **Referentni dizajn:** `docs/dizajn-projekat.png` ne postoji u repozitoriju. Kad se doda, uraditi vizuelno poređenje razmaka i veličina.
2. **PHPStan:** oko 300 upozorenja (uglavnom nedostaju generički tipovi relacija u modelima). Postepeno dodati tipove ili uvesti baseline pa ih smanjivati.
3. **Obavijesti u realnom vremenu:** zvono se sada osvježava svakih 60 s; uz Laravel Reverb moguće je trenutno.
4. **E-mail obavijesti:** kanal postoji, ali većina korisnika nema e-mail adresu. Dogovoriti da li se e-mail koristi.
5. **Integracija s protokolom:** broj protokola se unosi ručno; povezati s internom protokolarnom aplikacijom (`docs/PROTOCOL_INTEGRATION.md`).
6. **Izvještaji:** proširiti PDF izvještaj na postupke i rokove po instituciji te dodati izvoz u Excel.
