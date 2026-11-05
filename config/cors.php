<?php

return [
    /*
    | The captive-portal pages served by the MikroTik hotspot (RB951Ui and
    | friends) call the billing API from the router's own origin, which varies
    | per deployment — keep /api/* open to any origin. The public endpoints
    | are throttled and only expose what a signup form needs.
    */
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => ['*'],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => false,
];
