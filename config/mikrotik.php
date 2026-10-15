<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MikroTik RouterOS API Settings
    |--------------------------------------------------------------------------
    */
    'default_api_port' => env('MIKROTIK_API_PORT', 8728),
    'default_ssl_port' => env('MIKROTIK_SSL_PORT', 8729),
    'connection_timeout' => env('MIKROTIK_TIMEOUT', 4),

    /*
    |--------------------------------------------------------------------------
    | Firewall Address Lists
    |--------------------------------------------------------------------------
    | Address list name applied to isolate or quarantine expired subscribers.
    */
    'quarantine_address_list' => env('MIKROTIK_QUARANTINE_LIST', 'EXPIRED_USERS'),

    /*
    |--------------------------------------------------------------------------
    | Default Bandwidth Profiles
    |--------------------------------------------------------------------------
    */
    'default_hotspot_profile' => 'default',
    'default_pppoe_profile' => 'default',
];

