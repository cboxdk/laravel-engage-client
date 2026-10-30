---
title: Introduction
description: Product events in, transactional messages out, and an engage mail driver that never loses a password reset.
weight: 1
---

# Cbox Engage client

Cbox Engage is Cbox's communication platform: contacts, companies and product events in,
segments and automations in the middle, permitted messages out. This package is how a
Laravel app talks to it. It is an ordinary customer of Engage: an ingest key, the public
endpoint, nothing else.

## The three things

- **[Product events](core-concepts/events.md)** — `ProductEvents::record()` queues one
  event per call to `POST /v1/events`. Deterministic ids, retried while Engage is
  unavailable, dropped after one warning when Engage refuses, never thrown to the caller.
- **[Messages](core-concepts/messages.md)** — `Messages::send()` and `Messages::status()`
  over the transactional messages API, with typed value objects and typed exceptions.
- **[The `engage` mail driver](core-concepts/mail-driver.md)** — `MAIL_MAILER=engage`.
  Every recipient of a Mailable becomes one Engage message through the workspace's send
  gate. A [circuit breaker](core-concepts/circuit-breaker.md) and a fallback mailer take
  over when Engage is down, so transactional mail always leaves.

## The mental model

Engage is the truth about who may be mailed: consent, suppressions, frequency caps, the
sending domain, the plan. The mail driver sends through that gate. When the gate says no
for a reason that is about the *message* (no consent for marketing, a frequency cap), the
driver can send through the fallback. When it says no because the *address* is dead
(suppressed after a hard bounce), it throws `MailRefused`: no mailer would get that mail
through, and the app should know.

When Engage cannot be reached at all, the breaker opens and mail goes through the
fallback until a probe finds Engage back. Every fallback send is reported to Engage as a
`message.sent` product event, so the timeline stays complete.

## Documentation

- **Getting started** — [Requirements](requirements.md), [Installation](getting-started/installation.md), [Quickstart](quickstart.md), [Testing](getting-started/testing.md)
- **Core concepts** — [Events](core-concepts/events.md), [Messages](core-concepts/messages.md), [Mail driver](core-concepts/mail-driver.md), [Circuit breaker](core-concepts/circuit-breaker.md)
- **Cookbook** — [Password reset through Engage](cookbook/password-reset-through-engage.md), [Invoice with an attachment](cookbook/invoice-with-attachment.md)
- **Extension points** — [Overview](extension-points/_index.md)
- **Security** — [Threat model](security/threat-model.md)
