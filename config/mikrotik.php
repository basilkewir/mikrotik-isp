<?php

return [
    /*
    |--------------------------------------------------------------------------
    | MikroTik RouterOS API Settings
    |--------------------------------------------------------------------------
    */
    'default_api_port' => env('MIKROTIK_API_PORT', 8728),
    'default_ssl_port' => env('MIKROTIK_SSL_PORT', 8729),
    'timeout' => (int) env('MIKROTIK_TIMEOUT', 15),
    'connection_timeout' => (int) env('MIKROTIK_TIMEOUT', 15),

    // Upper bound the per-router timeout may grow to when that router has
    // recently been slow to answer (see MikrotikService::effectiveTimeout).
    'max_timeout' => (int) env('MIKROTIK_MAX_TIMEOUT', 60),

    // Lifetime of the hotspot MAC cookie that lets a bound device reconnect
    // silently. Must be at least as long as the longest plan validity.
    'mac_cookie_timeout' => env('MIKROTIK_MAC_COOKIE_TIMEOUT', '365d'),

    // Lifetime of the browser's http-chap/cookie login cookie (set on the
    // hotspot *server* profile, /ip/hotspot/profile). Remembered-login
    // convenience only — never a substitute for subscription expiry.
    'http_cookie_lifetime' => env('MIKROTIK_HTTP_COOKIE_LIFETIME', '30d'),

    // Disconnect a hotspot session after this much router-observed
    // inactivity. Session lifetime only: it never expires a subscription.
    'idle_timeout' => env('MIKROTIK_IDLE_TIMEOUT', '5m'),

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

