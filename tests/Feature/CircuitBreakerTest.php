<?php

declare(strict_types=1);

use Cbox\Engage\Mail\BreakerState;
use Cbox\Engage\Mail\CircuitBreaker;
use Illuminate\Support\Facades\Cache;

beforeEach(function (): void {
    $this->breaker = new CircuitBreaker(Cache::store('array'), failures: 3, window: 60, openFor: 120);
});

it('stays closed under the threshold and forgets failures outside the window', function (): void {
    expect($this->breaker->failure())->toBeFalse()
        ->and($this->breaker->failure())->toBeFalse()
        ->and($this->breaker->state())->toBe(BreakerState::Closed);

    $this->travel(61)->seconds();

    expect($this->breaker->failure())->toBeFalse()
        ->and($this->breaker->allows())->toBeTrue();
});

it('opens at the threshold, lets one probe through after open_for, and closes on success', function (): void {
    $this->breaker->failure();
    $this->breaker->failure();

    expect($this->breaker->failure())->toBeTrue()
        ->and($this->breaker->state())->toBe(BreakerState::Open)
        ->and($this->breaker->allows())->toBeFalse()
        ->and(Cache::store('array')->get('engage:breaker:open_until'))->toBeInt();

    $this->travel(121)->seconds();

    expect($this->breaker->state())->toBe(BreakerState::HalfOpen)
        ->and($this->breaker->allows())->toBeTrue()
        ->and($this->breaker->allows())->toBeFalse();

    $this->breaker->success();

    expect($this->breaker->state())->toBe(BreakerState::Closed)
        ->and($this->breaker->allows())->toBeTrue();
});

it('opens again when the probe fails', function (): void {
    $this->breaker->failure();
    $this->breaker->failure();
    $this->breaker->failure();
    $this->travel(121)->seconds();
    $this->breaker->allows();

    expect($this->breaker->failure())->toBeTrue()
        ->and($this->breaker->state())->toBe(BreakerState::Open)
        ->and($this->breaker->allows())->toBeFalse();

    $this->travel(121)->seconds();

    expect($this->breaker->state())->toBe(BreakerState::HalfOpen);
});

it('is built from config, on the configured cache store', function (): void {
    config(['engage.mail.breaker.failures' => 1, 'engage.mail.breaker.store' => 'array']);

    $breaker = app(CircuitBreaker::class);

    expect($breaker->failure())->toBeTrue()
        ->and(Cache::store('array')->has('engage:breaker:open_until'))->toBeTrue();

    $breaker->reset();

    expect($breaker->state())->toBe(BreakerState::Closed);
});
