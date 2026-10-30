<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Where Cbox Engage is
    |--------------------------------------------------------------------------
    |
    | The workspace's Engage instance (no trailing slash) and an ingest key
    | (`ek_…`). With either empty nothing here talks to Engage: product events
    | go nowhere, the Messages contract refuses to be used, and the `engage`
    | mail driver refuses to be built.
    |
    */

    'url' => env('ENGAGE_INGEST_URL'),
    'key' => env('ENGAGE_INGEST_KEY'),

    /*
    |--------------------------------------------------------------------------
    | How long a message call may take
    |--------------------------------------------------------------------------
    |
    | Seconds, for `POST /v1/messages` and `GET /v1/messages/{id}`. The mail
    | driver sends inside the request that produced the mail, so this is also
    | the longest a password reset waits before the fallback takes over.
    |
    */

    'timeout' => (float) env('ENGAGE_TIMEOUT', 2.0),

    /*
    |--------------------------------------------------------------------------
    | This product
    |--------------------------------------------------------------------------
    |
    | The product key Engage shows on the timeline (`context.product` on every
    | event, `product` on every message): the same key Cbox Billing uses,
    | e.g. `cbox-id`.
    |
    */

    'product' => env('ENGAGE_PRODUCT'),

    /*
    |--------------------------------------------------------------------------
    | Product events
    |--------------------------------------------------------------------------
    |
    | Each event is one queued job after the transaction that produced it,
    | tried `tries` times with backoff while Engage is unavailable.
    |
    */

    'events' => [
        'queue' => env('ENGAGE_EVENTS_QUEUE', 'default'),
        'tries' => 5,
        'timeout' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | The `engage` mail driver
    |--------------------------------------------------------------------------
    |
    | Defaults for every mail sent through the driver, each overridable per
    | message with an `X-Engage-*` header (see Cbox\Engage\Mail\EngageOptions).
    | `brand` is required by Engage. `track_opens` null means the brand's own
    | setting.
    |
    | `fallback_on_gate`: when Engage's send gate refuses a message for a reason
    | other than a suppressed address, send it through the mailer's `fallback`
    | instead. `fallback_on_rejection`: the same for a 4xx (a bad key, a
    | validation error); off by default because a misconfiguration must be loud.
    |
    | `breaker`: after `failures` unavailable answers inside `window` seconds the
    | driver stops calling Engage for `open_for` seconds and sends through the
    | fallback; then one call is let through to see whether Engage is back. The
    | state lives in the cache `store` (null: the default store).
    |
    */

    'mail' => [
        'brand' => env('ENGAGE_MAIL_BRAND'),
        'purpose' => 'transactional',
        'track_clicks' => false,
        'track_opens' => null,
        'fallback_on_gate' => true,
        'fallback_on_rejection' => false,
        'breaker' => [
            'failures' => 5,
            'window' => 60,
            'open_for' => 120,
            'store' => null,
        ],
    ],

];
