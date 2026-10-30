---
title: Extension points
description: Every capability is a contract bound in the service provider; rebind, decorate or fake it.
weight: 40
---

# Extension points

The package is contracts-first. Each capability is an interface bound in
`Cbox\Engage\EngageServiceProvider`; depend on the interface and the host can replace the
implementation.

| Contract / class | Default binding | Replace it to |
|------------------|-----------------|---------------|
| `Cbox\Engage\Contracts\ProductEvents` | `HttpProductEvents` when configured, else `NullProductEvents` | Batch events, write them somewhere else first, or drop them in an environment. |
| `Cbox\Engage\Contracts\Messages` | `HttpMessages` when configured, else `NullMessages` | Decorate with your own logging or metrics, or route to another messages backend. |
| `Cbox\Engage\Mail\CircuitBreaker` (singleton) | From `engage.mail.breaker`, on the configured cache store | A breaker with a different policy; the transport only calls `allows()`, `success()`, `failure()`. |
| `Cbox\Engage\Engage` (singleton) | Reads `config('engage.*')` | Resolve the URL and key from somewhere other than config (a secrets manager, per tenant). |

```php
// AppServiceProvider::register()
$this->app->extend(Messages::class, fn (Messages $messages) => new MeteredMessages($messages, $this->app->make(Meter::class)));
```

Bindings for the two contracts are not singletons, so a test can `instance()` a fake
after boot; `InteractsWithEngage::fakeEngage()` does exactly that.

## The mail transport

`EngageTransport` is built by the `engage` creator registered on `MailManager`. To build
it differently (another logger, another fallback resolution), register your own creator
after the package's: `Mail::extend('engage', fn (array $config) => …)` in a provider
that boots after `EngageServiceProvider`. The transport's constructor takes the two
contracts, the breaker, a `MailOptions`, a closure that returns the fallback
`TransportInterface`, the fallback's name and a PSR logger.

## Value objects

`ProductEvent`, `OutgoingMessage`, `MessageReceipt`, `MessageStatus` and the rest are
`readonly` and not `final`. `MessageReceipt` and `MessageStatus`, the ones a contract
returns, have a meaningful zero-argument form (`new MessageReceipt` is a queued receipt
with an empty id), so a test double can build one without reflection.

## What is not an extension point

- The envelope and the request bodies: they are Engage's contract. Change the server,
  then the client.
- The steering header names (`Cbox\Engage\Mail\Steering::HEADERS`): a Mailable and the
  driver must agree on them.
