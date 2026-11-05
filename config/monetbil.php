<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Monetbil payment widget
    |--------------------------------------------------------------------------
    |
    | DB settings (Settings page) take precedence; these env values are the
    | fallback for a fresh install. Never commit real credentials.
    |
    */

    'service_key' => env('MONETBIL_SERVICE_KEY'),
    'service_secret' => env('MONETBIL_SERVICE_SECRET'),
    'logo_url' => env('MONETBIL_LOGO_URL'),
];
