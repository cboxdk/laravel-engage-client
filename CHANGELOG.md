# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- `Messages::sendMany(OutgoingBatch): BatchReceipt` over `POST /v1/message-batches`: one
  message to up to 1000 recipients, each a `BatchRecipient` with its own idempotency key,
  variables and optional company. `Messages::batch($id, $cursor, $limit): BatchStatus`
  over `GET /v1/message-batches/{id}`: counts by status and a page of `BatchItem`s.
- `FakeEngage` answers batches from the same keyspace as `send()`, with
  `assertBatchSent()`, `assertBatchRecipient()` and `batchesSent()`;
  `assertNothingSent()` now also fails on a batch.

### Changed

- `Contracts\Messages` has two new methods. An app that implements the contract itself
  (not `HttpMessages`, `NullMessages` or `FakeEngage`) must add them. Needs a Cbox Engage
  with the message batches API.

## [0.1.1] - 2026-11-06

### Added

- Explicit per-workspace `HttpLeads`/`Contracts\Leads` for verified email leads,
  independent service/feature interests, scoped consent and proven identity links.
- Typed `LeadChange`, `Interest`, `ConsentChoice`, `IdentityProof`, `LeadDestination`
  and `LeadReceipt`; terminal erasure receipts and `FakeLeads` for host tests.
- HTTPS-only destinations, bounded timeouts, no redirects or global-key fallback.
  Durable retries remain the host application's responsibility.

## [0.1.0] - 2026-10-30

### Added

- `Cbox\Engage\Contracts\ProductEvents` with `HttpProductEvents` (one queued
  `SendProductEvent` job per event, retried with backoff on 429/5xx, dropped after one
  warning on any other 4xx) and `NullProductEvents` when Engage is not configured.
- `Cbox\Engage\Contracts\Messages` over `POST /v1/messages` and `GET /v1/messages/{id}`
  with typed value objects (`OutgoingMessage`, `MessageReceipt`, `MessageStatus`) and the
  typed exceptions `EngageUnavailable`, `EngageRejected`, `EngageNotConfigured`.
- The `engage` mail driver: a Symfony transport with a cache-backed circuit breaker and a
  fallback mailer, `X-Engage-*` steering headers, the `EngageOptions` Mailable trait, and
  `MailRefused` for addresses Engage will not take.
- `Cbox\Engage\Testing\FakeEngage` and `InteractsWithEngage`.

[Unreleased]: https://github.com/cboxdk/laravel-engage-client/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/cboxdk/laravel-engage-client/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/cboxdk/laravel-engage-client/releases/tag/v0.1.0
