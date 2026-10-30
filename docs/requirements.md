---
title: Requirements
description: Runtime requirements enforced by the package's composer constraints.
weight: 3
---

# Requirements

The package installs only when your project satisfies the constraints declared in its
`composer.json`. These are the versions the dependency resolver enforces, nothing more.

## PHP

- **PHP `^8.4`** — PHP 8.4 or newer.

## Laravel

- **Laravel 12 or 13** — the package requires the `illuminate/*` components below at
  `^12.0 || ^13.0`.

## Direct dependencies

- **`illuminate/bus`, `illuminate/contracts`, `illuminate/http`, `illuminate/mail`,
  `illuminate/queue`, `illuminate/support`** `^12.0 || ^13.0` — the Laravel components
  the package uses: the HTTP client, the queue and bus for the events job, the mail
  manager for the driver.
- **`symfony/mailer`, `symfony/mime`** `^7.2 || ^8.0` — the transport base class and
  the message model the mail driver is built on. Laravel's mail already requires both.

The package has no other runtime dependencies. No PHP extension beyond what Laravel
itself requires.

## Services

- A Cbox Engage workspace with an ingest key. Product events need the `events:write`
  scope, messages and the mail driver `messages:send`.
- A cache store for the mail driver's circuit breaker (any Laravel cache store; the
  default store unless `engage.mail.breaker.store` says otherwise).
- A queue for product events (the `sync` queue works, but then Engage's latency is in
  the request).
