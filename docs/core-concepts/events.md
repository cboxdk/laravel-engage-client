---
title: Events
description: ProductEvents::record() queues one envelope per event to POST /v1/events, with deterministic ids and no way to fail the caller.
weight: 21
---

# Events

A product event is one thing that happened: a sign-in, a project created, a key revoked,
a plan changed. The app records it; the package queues it; Engage puts it on the
contact's timeline and feeds segments and automations with it.

## Recording

```php
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;

$events->record(ProductEvent::byContact(
    name: 'member.invited',
    eventId: "cbox-id:member.invited:{$invitation->id}",
    userExternalId: $inviter->cbox_id,
    companyExternalId: $organization->cbox_id,
    props: ['role' => 'developer'],
));

$events->record(ProductEvent::byCompany(
    name: 'plan.changed',
    eventId: "cbox-id:plan.changed:{$organization->cbox_id}:{$plan}:{$today}",
    companyExternalId: $organization->cbox_id,
    props: ['plan' => $plan],
));
```

`ProductEvent` is a readonly value object. Its constructor validates the name and the id
and throws `InvalidArgumentException` for a malformed one, so a bad event is a bug you
see in development, not a `rejected` entry in a log line.

## The rules

- **`eventId` is deterministic per occurrence**: `{app}:{name}:{natural key}`. The
  invitation id, the key id, `{user}:{date-hour}` for a sign-in. Engage deduplicates on
  it, so a retry or a double call is one event. Never a random id when a natural key
  exists. 1–128 characters.
- **`name`** is lowercase dotted words: `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$`, at most
  64 characters.
- **The subject** is the Cbox ID *user* id when a person did it (`byContact`), the Cbox ID
  *organization* id when no person is at hand (`byCompany`: a scheduled job, a plan
  synced from billing). An event needs a subject; skip the call when there is none (a
  local-only organization, the dev login).
- **`companyExternalId`** is the organization id whenever it is known.
- **`props`** are flat: ids, enums, booleans, numbers. No emails, no names, no free text.
- **`category`** is `behavior` for everything an app records itself. `communication` is
  reserved for the mail driver's `message.sent`.

## The envelope

```json
{
  "schema_version": 1,
  "event_id": "cbox-id:member.invited:inv_01J…",
  "name": "member.invited",
  "category": "behavior",
  "occurred_at": "2026-10-29T12:00:00.000Z",
  "subject": { "type": "contact", "external_id": "usr_…" },
  "context": { "company_external_id": "org_…", "product": "cbox-id", "environment": "production" },
  "props": { "role": "developer" }
}
```

`context.product` is `config('engage.product')`; `context.environment` is
`app()->environment()`.

## Delivery

`HttpProductEvents` dispatches one `SendProductEvent` job per event, on
`config('engage.events.queue')`, `afterCommit`, so an event about a row never arrives
before the row exists. The job posts a one-element batch to `POST /v1/events` with the
bearer key, a 10 s timeout.

| Engage answers | The job |
|----------------|---------|
| 2xx | Done. A non-empty `rejected` list is logged once as a warning (a bug in what the app builds). |
| 429, 5xx, unreachable | Logs a warning and releases itself with backoff 60 / 300 / 900 / 3600 s, or `Retry-After` when longer, up to `events.tries` attempts. |
| Any other 4xx | Logs a warning once and stops. Sending again would not help. |

`record()` itself never throws. If the job cannot be queued (the queue connection is
down), that is one warning in the log and the event is lost, which is the trade the
emitter contract makes: analytics must not fail the request that produced them.

## When unconfigured

With `ENGAGE_INGEST_URL` or `ENGAGE_INGEST_KEY` empty, `ProductEvents` is
`NullProductEvents`: `record()` does nothing.
