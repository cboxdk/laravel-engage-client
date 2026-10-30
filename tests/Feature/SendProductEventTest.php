<?php

declare(strict_types=1);

use Cbox\Engage\Events\SendProductEvent;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

/**
 * @return array<string, mixed>
 */
function envelope(): array
{
    return [
        'schema_version' => 1,
        'event_id' => 'app:user.signed_in:usr_1',
        'name' => 'user.signed_in',
        'category' => 'behavior',
        'occurred_at' => '2026-10-29T12:00:00.000Z',
        'subject' => ['type' => 'contact', 'external_id' => 'usr_1'],
        'context' => ['product' => 'cbox-test', 'environment' => 'testing'],
        'props' => (object) [],
    ];
}

function jobWithAttempt(SendProductEvent $job, int $attempt): MockInterface
{
    $queued = Mockery::mock(Job::class);
    $queued->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($queued);

    return $queued;
}

it('posts one envelope as a batch with the bearer key to /v1/events', function (): void {
    Http::fake(['engage.test/*' => Http::response(['accepted' => 1, 'rejected' => []], 202)]);

    app()->call([new SendProductEvent(envelope()), 'handle']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://engage.test/v1/events'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer ek_test')
        && $request->hasHeader('Accept', 'application/json')
        && $request->body() === json_encode([envelope()]));
});

it('releases the job with backoff on a 5xx, so the queue retries it', function (): void {
    Http::fake(['engage.test/*' => Http::response(['error' => 'down'], 503)]);
    Log::spy();
    $job = new SendProductEvent(envelope());
    jobWithAttempt($job, 2)->shouldReceive('release')->once()->with(300);

    app()->call([$job, 'handle']);

    Log::shouldHaveReceived('warning')->once();
});

it('honours Retry-After on a 429 when it is longer than the backoff', function (): void {
    Http::fake(['engage.test/*' => Http::response(['error' => 'quota'], 429, ['Retry-After' => '900'])]);
    $job = new SendProductEvent(envelope());
    jobWithAttempt($job, 1)->shouldReceive('release')->once()->with(900);

    app()->call([$job, 'handle']);
});

it('retries when Engage cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 7: connection refused'));
    $job = new SendProductEvent(envelope());
    jobWithAttempt($job, 1)->shouldReceive('release')->once()->with(60);

    app()->call([$job, 'handle']);
});

it('drops the event after one warning on any other 4xx', function (): void {
    Http::fake(['engage.test/*' => Http::response(['error' => 'invalid_key'], 401)]);
    Log::spy();
    $job = new SendProductEvent(envelope());
    jobWithAttempt($job, 1)->shouldNotReceive('release');

    app()->call([$job, 'handle']);

    Log::shouldHaveReceived('warning')->once()->with('Cbox Engage refused a product event; it is not sent again.', Mockery::on(fn (array $context): bool => str_contains($context['error'], '401 invalid_key') && $context['event_id'] === 'app:user.signed_in:usr_1'));
});

it('stops releasing on the last attempt', function (): void {
    Http::fake(['engage.test/*' => Http::response([], 500)]);
    $job = new SendProductEvent(envelope(), tries: 5);
    jobWithAttempt($job, 5)->shouldNotReceive('release');

    app()->call([$job, 'handle']);
});

it('warns about envelopes Engage rejected inside an accepted batch', function (): void {
    Http::fake(['engage.test/*' => Http::response(['accepted' => 0, 'rejected' => [['event_id' => 'app:user.signed_in:usr_1', 'error' => 'name']]], 202)]);
    Log::spy();

    app()->call([new SendProductEvent(envelope()), 'handle']);

    Log::shouldHaveReceived('warning')->once()->with('Cbox Engage rejected product events from a batch.', Mockery::type('array'));
});
