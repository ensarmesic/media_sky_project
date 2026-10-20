<?php

// ProjectFlow — konfiguracija dokumenata, podsjetnika, "Zahtijeva pažnju" i integracije s protokolom.

return [
    'documents' => [
        // Najveća dozvoljena veličina uploada u kilobajtima (25 MB).
        'max_size' => (int) env('DOCUMENT_MAX_SIZE', 25600),
        // Dozvoljene vrste datoteka (provjera po ekstenziji): PDF, slike, Office, tekst, CSV, ZIP, CAD.
        'allowed_extensions' => 'pdf,png,jpg,jpeg,gif,webp,bmp,doc,docx,xls,xlsx,ppt,pptx,odt,ods,txt,csv,zip,dwg,dxf',
        'allowed_labels' => 'PDF, slike, Office, TXT, CSV, ZIP, DWG/DXF',
        // Zadržano zbog starih poziva (validacija po MIME tipu).
        'allowed_mimes' => 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,txt,csv',
    ],

    'attention' => [
        // Stavka "čeka odgovor predugo" nakon ovoliko dana od predaje.
        'waiting_days' => (int) env('ATTENTION_WAITING_DAYS', 10),
        // Stavke s rokom u narednih N dana traže pažnju.
        'due_within_days' => (int) env('ATTENTION_DUE_WITHIN_DAYS', 5),
        // Rok se prikazuje narandžastom bojom kad je za ovoliko dana ili manje.
        'warning_days' => (int) env('ATTENTION_WARNING_DAYS', 3),
    ],

    'reminders' => [
        // Koliko dana unaprijed se rok smatra "uskoro ističućim".
        'due_soon_days' => (int) env('REMINDER_DUE_SOON_DAYS', 3),
        // Podsjetnik o roku šalje se ovoliko dana prije roka (plan: rok je sutra).
        'deadline_days_before' => (int) env('REMINDER_DEADLINE_DAYS_BEFORE', 1),
        // Nakon koliko dana čekanja odgovora stavka "predugo čeka".
        'waiting_too_long_days' => (int) env('REMINDER_WAITING_TOO_LONG_DAYS', 10),
        // Podrazumijevani periodični podsjetnik za čekanje odgovora (u danima).
        'waiting_default_interval' => (int) env('REMINDER_WAITING_INTERVAL', 10),
    ],

    'protocol' => [
        // Kontrolisani backend linkovi prema internoj protokolarnoj aplikaciji.
        'base_url' => env('PROTOCOL_BASE_URL'),
        'api_token' => env('PROTOCOL_API_TOKEN'),
        'timeout' => (int) env('PROTOCOL_TIMEOUT', 10),
    ],
];
