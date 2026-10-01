<?php

return [

    /*
    | Loopback, always. video-service has no public server block: it holds the estate's
    | one Daily.co key.
    */
    'base_url' => env('VIDEO_SERVICE_URL', 'http://127.0.0.1:8008'),

    /*
    | Issued BY the service (`php artisan video:issue-key <product>`): the callee owns the
    | key. This product never mints its own.
    */
    'trust_key' => env('VIDEO_SERVICE_TRUST_KEY', ''),

    /* A backstop against a hung socket. Daily calls behind the service take ~1s. */
    'timeout' => (int) env('VIDEO_SERVICE_TIMEOUT', 15),

    /*
    | The secret video-service signs this product's event callbacks with
    | (`video:set-callback` prints it once). Only needed by the callback receiver.
    */
    'callback_secret' => env('VIDEO_SERVICE_CALLBACK_SECRET', ''),

    /*
    |--------------------------------------------------------------------------
    | Deliberately absent
    |--------------------------------------------------------------------------
    |
    | ⚠ No Daily domain. No Daily key. No room-name prefix. The moment a product's config
    | names one, the single source of truth has leaked back out of the service.
    |
    */

];
