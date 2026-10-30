<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;

/**
 * Stops the mail driver calling an Engage that keeps failing. `$failures` unavailable
 * answers inside `$window` seconds open the breaker for `$openFor` seconds; then one
 * call is let through (half-open): success closes it, failure opens it again. The state
 * is in the cache, so every worker of the app sees the same breaker.
 */
class CircuitBreaker
{
    public const string PREFIX = 'engage:breaker:';

    public function __construct(
        private readonly Repository $cache,
        private readonly int $failures = 5,
        private readonly int $window = 60,
        private readonly int $openFor = 120,
    ) {}

    /**
     * Whether this call may go to Engage. Closed: yes. Open: no. Half-open: yes for the
     * first caller to ask, no for everyone else until that probe has reported back.
     */
    public function allows(): bool
    {
        return match ($this->state()) {
            BreakerState::Closed => true,
            BreakerState::Open => false,
            BreakerState::HalfOpen => $this->cache->add(self::PREFIX.'probe', 1, $this->openFor),
        };
    }

    public function state(): BreakerState
    {
        $until = $this->cache->get(self::PREFIX.'open_until');

        if (! is_int($until)) {
            return BreakerState::Closed;
        }

        return CarbonImmutable::now()->getTimestamp() < $until ? BreakerState::Open : BreakerState::HalfOpen;
    }

    /** Engage answered: whatever came before is forgotten. */
    public function success(): void
    {
        $this->cache->forget(self::PREFIX.'failures');
        $this->cache->forget(self::PREFIX.'open_until');
        $this->cache->forget(self::PREFIX.'probe');
    }

    /** Engage did not answer. Returns whether the breaker is open after this failure. */
    public function failure(): bool
    {
        // The probe failed: Engage is still down, so open for another period.
        if ($this->state() !== BreakerState::Closed) {
            $this->open();

            return true;
        }

        $count = $this->cache->add(self::PREFIX.'failures', 1, $this->window) ? 1 : $this->cache->increment(self::PREFIX.'failures');

        if (is_numeric($count) && (int) $count >= $this->failures) {
            $this->open();

            return true;
        }

        return false;
    }

    /** Back to closed, as if nothing had happened: for tests and operators. */
    public function reset(): void
    {
        $this->success();
    }

    private function open(): void
    {
        // Kept well past open_for: the half-open state must survive until a probe has
        // reported back. With no mail at all for an hour after that, closing is right.
        $this->cache->put(self::PREFIX.'open_until', CarbonImmutable::now()->getTimestamp() + $this->openFor, $this->openFor + 3600);
        $this->cache->forget(self::PREFIX.'failures');
        $this->cache->forget(self::PREFIX.'probe');
    }
}
