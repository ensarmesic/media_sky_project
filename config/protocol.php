<?php

return [
    'base_url' => env('PROTOCOL_BASE_URL'),
    'api_token' => env('PROTOCOL_API_TOKEN'),
    'timeout' => (int) env('PROTOCOL_TIMEOUT', 10),
];
