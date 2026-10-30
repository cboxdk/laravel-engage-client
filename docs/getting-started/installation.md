---
title: Installation
description: Install the package, set ENGAGE_INGEST_URL and ENGAGE_INGEST_KEY, and publish the config.
weight: 11
---

# Installation

```bash
composer require cboxdk/laravel-engage-client
```

The service provider is discovered automatically.

## The two keys

```dotenv
ENGAGE_INGEST_URL=https://app.cboxengage.com   # the workspace's Engage, no trailing slash
ENGAGE_INGEST_KEY=ek_…                          # an ingest key of that workspace
```

These two names are shared by every Cbox app. With either empty the package is
deliberately inert:

- `ProductEvents` is bound to `NullProductEvents`: events go nowhere, silently.
- `Messages` is bound to `NullMessages`: every call throws `EngageNotConfigured`.
- The `engage` mailer throws `EngageNotConfigured` when it is built. A mailer that
  silently sends nothing would be worse than an error.

## The config file

```bash
php artisan vendor:publish --tag=engage-config
```

| Key | Env | Default | What it is |
|-----|-----|---------|------------|
| `url` | `ENGAGE_INGEST_URL` | — | The Engage instance. |
| `key` | `ENGAGE_INGEST_KEY` | — | The ingest key. Never logged. |
| `timeout` | `ENGAGE_TIMEOUT` | `2.0` | Seconds a message call may take. The mail driver waits this long before the fallback takes over. |
| `product` | `ENGAGE_PRODUCT` | — | This product's key (`cbox-id`): `context.product` on events, `product` on messages. |
| `events.queue` | `ENGAGE_EVENTS_QUEUE` | `default` | The queue the events job runs on. |
| `events.tries` | — | `5` | Attempts while Engage is unavailable, with backoff 60 / 300 / 900 / 3600 s. |
| `events.timeout` | — | `10` | Seconds an events request may take. |
| `mail.brand` | `ENGAGE_MAIL_BRAND` | — | The brand the driver sends as; required unless every mail sets `X-Engage-Brand`. |
| `mail.purpose` | — | `transactional` | The purpose of mail sent through the driver. |
| `mail.track_clicks` | — | `false` | Click tracking; `false` so a magic link never depends on Engage. |
| `mail.track_opens` | — | `null` | Open tracking; `null` is the brand's own setting. |
| `mail.fallback_on_gate` | — | `true` | Send through the fallback when the gate refuses for a reason other than a suppressed address. |
| `mail.fallback_on_rejection` | — | `false` | Send through the fallback on a 4xx too. Off: a misconfiguration must be loud. |
| `mail.breaker.failures` | — | `5` | Unavailable answers inside the window that open the breaker. |
| `mail.breaker.window` | — | `60` | Seconds the failure count lives. |
| `mail.breaker.open_for` | — | `120` | Seconds the breaker stays open before one probe is let through. |
| `mail.breaker.store` | — | `null` | The cache store the breaker lives in; `null` is the default store. |

## The mailer

```php
// config/mail.php
'default' => env('MAIL_MAILER', 'engage'),

'mailers' => [
    'engage' => [
        'transport' => 'engage',
        'fallback' => 'resend',      // any other mailer in this list; omit for no fallback
    ],
    'resend' => ['transport' => 'resend'],
],
```

The `fallback` is the name of another mailer. Its own Symfony transport sends when Engage
cannot, so it needs nothing beyond its usual configuration. Without a `fallback` the
driver rethrows instead of sending: `EngageUnavailable` when Engage is down,
`MailRefused` when the gate said no.
