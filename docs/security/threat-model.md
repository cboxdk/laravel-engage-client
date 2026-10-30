---
title: Threat model
description: The fallback path bypasses Engage's consent gate, so the driver is for transactional mail only; the key and addresses never reach a log line.
weight: 51
---

# Threat model

This page covers what this package does itself. Engage's own controls (the send gate,
suppressions, consent, the sending domain, the plan) live in Engage and are documented
there; this client only calls them.

## The fallback path bypasses the consent gate

This is the one thing to understand before enabling the `engage` mailer.

Engage's send gate is where consent, suppressions and frequency caps are enforced. When
the driver sends through the fallback mailer (because Engage is down, the breaker is
open, or the gate refused for a reason such as `no_consent` or `frequency_cap`), **that
mail is sent by the fallback provider without any of those checks**. The driver tells
Engage afterwards with a `message.sent` event, but the mail has already left.

Consequences, and what the package does about them:

- **Use the driver for transactional mail only**: password resets, codes, receipts,
  invoices. Mail a person must get regardless of marketing consent. The default
  `engage.mail.purpose` is `transactional` and the driver sends inline content, never
  a template, which is a hint, not a guard. Marketing and product updates go through
  `Messages::send()` with `purpose: 'product_updates'` or through Engage's own
  campaigns, where a `gated_out` receipt is final.
- **A suppressed address is never sent through the fallback.** A hard bounce means the
  address is dead, and sending it elsewhere would only damage the fallback provider's
  reputation. The driver throws `MailRefused`.
- **`fallback_on_gate` can be turned off** (`engage.mail.fallback_on_gate = false`): then
  every gate refusal throws `MailRefused` and only outages use the fallback.
- **`fallback_on_rejection` is off by default.** A 4xx is a misconfiguration (a revoked
  key, a wrong brand); turning it into a fallback send would hide the misconfiguration
  behind working mail for as long as the fallback provider lasts.

The package cannot know whether a given Mailable is transactional. That judgement is the
app's, made when choosing the mailer.

## The ingest key

The key (`ek_…`) grants `events:write` and `messages:send` on a workspace. The package:

- reads it from config only and sends it only as `Authorization: Bearer` to
  `config('engage.url')`;
- never writes it to a log line, an exception message or a product event;
- does nothing with it when `engage.url` is empty, so a key without an endpoint cannot be
  sent to a wrong host.

The package does not validate `engage.url` beyond trimming a trailing slash. Whoever
controls the app's config controls where the key is sent; that is the usual
configuration trust boundary, and the package does not pretend otherwise.

## What ends up in logs

| Line | Context | Never |
|------|---------|-------|
| `engage mail sent through fallback` (warning) | `reason`, `mailer` | the address, the key, the subject |
| `Cbox Engage did not take a product event yet` / `refused a product event` (warning) | `event`, `event_id`, `error` (Engage's status and error code) | props |
| `A product event could not be queued` (warning) | `event`, `event_id`, `error` | — |
| `engage mail could not be sent and no fallback mailer is configured` (error) | `reason` | the address |

The `message.sent` event carries a `subject_hash` (SHA-256 of the subject line), not the
subject, and the recipient's external id, not the address.

## Steering headers

`X-Engage-*` headers carry the app's internal ids and keys. The driver reads them from a
clone of the message and removes them before the clone is turned into a `SentMessage`,
so they appear in no copy that reaches Engage, the fallback provider, or the
`SentMessage`'s debug string. A mail sent through any *other* mailer keeps them: the
headers are meaningful to this driver only, and an app that adds them to a mail it then
sends through SMTP has sent its own ids to that provider.

## Idempotency

The idempotency key is what stops a retried job from sending a mail twice. With no
`X-Engage-Idempotency-Key`, the `Message-ID` is used, which is new per send attempt. The
package does not make a Mailable idempotent on its own; it gives the app the header to
do so. Keys longer than 64 characters are hashed (SHA-256, truncated), deterministically.

## What this package does not do

- It does not verify that the `fallback` mailer is itself permitted to send to the
  address, or that its sending domain is the same as Engage's. The fallback is the app's
  own mailer with the app's own reputation.
- It does not sign requests; the bearer key is the only credential, as Engage's ingest
  API defines it.
- It does not enforce the 5 MB attachment limit client-side beyond the count; Engage
  answers `422` and the driver throws `EngageRejected`.
