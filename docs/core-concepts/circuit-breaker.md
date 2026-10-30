---
title: Circuit breaker
description: After five unavailable answers in a minute the driver stops calling Engage for two minutes, then probes once; the state lives in the cache.
weight: 24
---

# Circuit breaker

The mail driver calls Engage inside the request that produced the mail, with a 2 s
timeout. When Engage is down, every password reset would wait those 2 s before the
fallback sends it. The breaker stops that: once Engage has failed enough, the driver
goes straight to the fallback and only looks back now and then.

```php
'mail' => [
    'breaker' => [
        'failures' => 5,     // unavailable answers inside the window …
        'window' => 60,      // … seconds, that open the breaker
        'open_for' => 120,   // seconds without calling Engage
        'store' => null,     // cache store; null is the default store
    ],
],
```

## The three states

| State | On a send |
|-------|-----------|
| **Closed** | Engage is called. An `EngageUnavailable` counts one failure in the window; at `failures` the breaker opens and this send goes through the fallback. A successful call clears the count. |
| **Open** | Engage is not called. The mail goes through the fallback with reason `breaker_open`. |
| **Half-open** (after `open_for`) | One send is let through as a probe; every other send during the probe goes through the fallback. The probe's success closes the breaker; its failure opens it for another `open_for`. |

Only `EngageUnavailable` counts: timeouts, connection failures, 5xx and 429. A 4xx is a
misconfiguration, not an outage, and is thrown without touching the breaker. A
`gated_out` receipt is Engage working as intended and clears the count like any
successful call.

## Where the state lives

In Laravel's cache, keys prefixed `engage:breaker:` (`failures`, `open_until`, `probe`),
on `Cache::store(config('engage.mail.breaker.store'))`. A shared store (Valkey, Redis)
means every web worker and queue worker of the app sees the same breaker: five failures
across the fleet open it for the fleet, and one probe is one probe. With the `array` or
`file` store each process has its own breaker, which still works, just per process.

`Cbox\Engage\Mail\CircuitBreaker` is resolvable from the container:

```php
app(CircuitBreaker::class)->state();   // BreakerState::Closed | Open | HalfOpen
app(CircuitBreaker::class)->reset();   // closed, as if nothing had happened
```

The package ships no command or endpoint for it; wire `reset()` where your app keeps
such things.
