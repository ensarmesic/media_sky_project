<?php

// ProjectFlow — konfiguracija dokumenata, podsjetnika i integracije s protokolom.

return [
    'documents' => [
        // Najveća dozvoljena veličina uploada u kilobajtima (20 MB).
        'max_size' => (int) env('DOCUMENT_MAX_SIZE', 20480),
        // Dozvoljene vrste poslovnih datoteka.
        'allowed_mimes' => 'pdf,doc,docx,xls,xlsx,png,jpg,jpeg,webp,txt,csv',
        'allowed_labels' => 'PDF, Word, Excel, slike, tekst, CSV',
    ],

    'reminders' => [
        // Koliko dana unaprijed se rok smatra "uskoro ističućim".
        'due_soon_days' => (int) env('REMINDER_DUE_SOON_DAYS', 7),
        // Nakon koliko dana čekanja odgovora stavka je "predugo čeka".
        'waiting_too_long_days' => (int) env('REMINDER_WAITING_TOO_LONG_DAYS', 14),
        // Podrazumijevani periodični podsjetnik za čekanje odgovora (u danima).
        'waiting_default_interval' => (int) env('REMINDER_WAITING_INTERVAL', 14),
    ],

    'protocol' => [
        // Kontrolisani backend linkovi prema internoj protokolarnoj aplikaciji.
        'base_url' => env('PROTOCOL_BASE_URL'),
        'api_token' => env('PROTOCOL_API_TOKEN'),
        'timeout' => (int) env('PROTOCOL_TIMEOUT', 10),
    ],
];
