# Build status

What this package does today, and what it deliberately does not.

## Built and tested

- Product events: contract, HTTP implementation (queued job, tries 5, backoff
  60/300/900/3600 s, `afterCommit`, 429 `Retry-After` honoured), null implementation.
- Messages API: send and status, request/response mapping, the three typed exceptions.
- The `engage` mail driver: one Engage message per recipient, steering headers read and
  stripped, attachments base64-encoded, Engage message id on the `SentMessage`, circuit
  breaker (closed / open / half-open with a single probe) in the cache, fallback through
  the configured mailer's own transport with a `message.sent` product event and one log
  line, `MailRefused` for suppressed addresses, loud `EngageRejected` on 4xx.
- `FakeEngage` + `InteractsWithEngage`; the package's own suite runs on them.

## Not built

- Engage templates through the mail driver: the driver always sends inline content (the
  rendered Mailable). Use `Messages::send()` with a `template` for template sends.
- Cc and Bcc as such: Engage messages have one recipient, so every recipient of a mail
  (To, Cc, Bcc) gets its own copy. Only the first recipient carries the external id.
- A command or endpoint to inspect or reset the breaker; `CircuitBreaker::reset()`
  exists for the app to wire.
- Event batching: one event is one job and one request. Engage takes up to 500 per
  request, but the apps that use this emit a handful of events per request, not hundreds.
