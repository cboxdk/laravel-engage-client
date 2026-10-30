---
title: Core concepts
description: The events envelope, the messages API, the mail driver and its circuit breaker.
weight: 20
---

# Core concepts

- [Events](events.md) — the envelope, deterministic ids, who the subject is, what props may hold, how delivery is retried
- [Messages](messages.md) — `OutgoingMessage` in, `MessageReceipt` and `MessageStatus` out, and the two exceptions
- [Mail driver](mail-driver.md) — how a Mailable becomes Engage messages, the steering headers, the fallback
- [Circuit breaker](circuit-breaker.md) — closed, open, half-open, and where the state lives
