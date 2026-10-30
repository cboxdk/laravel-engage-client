<?php

declare(strict_types=1);

use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\HttpProductEvents;
use Cbox\Engage\Events\NullProductEvents;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Engage\Events\SendProductEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

it('queues one job per event, after commit, on the configured queue with the configured tries', function (): void {
    Bus::fake();
    config(['engage.events.queue' => 'engage', 'engage.events.tries' => 3]);

    app(ProductEvents::class)->record(ProductEvent::byContact('user.signed_in', 'app:user.signed_in:usr_1', 'usr_1', 'org_1'));

    Bus::assertDispatched(SendProductEvent::class, fn (SendProductEvent $job): bool => $job->envelope['event_id'] === 'app:user.signed_in:usr_1'
        && $job->envelope['context'] === ['company_external_id' => 'org_1', 'product' => 'cbox-test', 'environment' => 'testing']
        && $job->queue === 'engage'
        && $job->tries === 3
        && $job->afterCommit === true);
});

it('never lets a queue failure reach the caller', function (): void {
    Log::spy();
    $bus = Mockery::mock(Dispatcher::class);
    $bus->shouldReceive('dispatch')->andThrow(new RuntimeException('redis is down'));
    app()->instance(Dispatcher::class, $bus);

    app(ProductEvents::class)->record(ProductEvent::byContact('user.signed_in', 'app:user.signed_in:usr_1', 'usr_1'));

    Log::shouldHaveReceived('warning')->once()->with('A product event could not be queued for Cbox Engage.', Mockery::on(fn (array $context): bool => $context['error'] === 'redis is down' && $context['event'] === 'user.signed_in'));
});

it('binds the HTTP implementation when configured and the no-op one otherwise', function (): void {
    expect(app(ProductEvents::class))->toBeInstanceOf(HttpProductEvents::class);

    config(['engage.key' => '']);

    expect(app(ProductEvents::class))->toBeInstanceOf(NullProductEvents::class);

    config(['engage.key' => 'ek_test', 'engage.url' => null]);

    expect(app(ProductEvents::class))->toBeInstanceOf(NullProductEvents::class);
});
