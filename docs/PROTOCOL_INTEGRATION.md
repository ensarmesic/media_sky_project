# Priprema integracije s protokolom

Integracija iz Taska 06 nije aktivirana. ProjectFlow trenutno čuva samo `DocumentReference` metapodatke; vanjski protokol ostaje izvor istine i skenirane datoteke se ne kopiraju.

Rezervirana konfiguracija: `PROTOCOL_BASE_URL`, `PROTOCOL_API_TOKEN` i `PROTOCOL_TIMEOUT`.

Prije implementacije treba analizirati postojeću aplikaciju, autentifikaciju, identifikatore, storage i prava pristupa. Buduća integracija mora koristiti minimalna servisna prava, idempotency ključ, audit log i retry/backoff. Kombinacija `external_system + external_document_id` već sprečava duplikate. Originalni dokument ne smije imati javni URL; kontrolirani backend zahtjev mora ponovo provjeriti korisnikov pristup projektu.
