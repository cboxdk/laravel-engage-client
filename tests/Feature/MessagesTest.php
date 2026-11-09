<?php

declare(strict_types=1);

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Exceptions\EngageException;
use Cbox\Engage\Exceptions\EngageNotConfigured;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\BatchState;
use Cbox\Engage\Messages\DeliveryStatus;
use Cbox\Engage\Messages\HttpMessages;
use Cbox\Engage\Messages\InlineContent;
use Cbox\Engage\Messages\MessageState;
use Cbox\Engage\Messages\NullMessages;
use Cbox\Engage\Messages\OutgoingMessage;
use Cbox\Engage\Messages\ReceiptStatus;
use Cbox\Engage\Messages\Recipient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function outgoing(): OutgoingMessage
{
    return new OutgoingMessage('otp:usr_1', new Recipient('usr_1', 'ada@example.com'), 'br_1', content: new InlineContent('Your code', '<p>1</p>'));
}

it('posts the message and maps the receipt', function (): void {
    Http::fake(['engage.test/v1/messages' => Http::response(['id' => 'msg_1', 'status' => 'queued', 'reasons' => [], 'contact_id' => 'ct_1', 'created' => true], 202)]);

    $receipt = app(Messages::class)->send(outgoing());

    expect($receipt->id)->toBe('msg_1')
        ->and($receipt->status)->toBe(ReceiptStatus::Queued)
        ->and($receipt->queued())->toBeTrue()
        ->and($receipt->contactId)->toBe('ct_1')
        ->and($receipt->created)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://engage.test/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer ek_test')
        && $request->hasHeader('Content-Type', 'application/json')
        && $request->data() === outgoing()->toArray());
});

it('maps a gated-out receipt and a replayed key', function (): void {
    Http::fake(['engage.test/v1/messages' => Http::response(['id' => 'msg_1', 'status' => 'gated_out', 'reasons' => ['suppressed'], 'gate' => ['stage' => 'enqueue'], 'contact_id' => null, 'created' => false], 200)]);

    $receipt = app(Messages::class)->send(outgoing());

    expect($receipt->gatedOut())->toBeTrue()
        ->and($receipt->suppressed())->toBeTrue()
        ->and($receipt->reasons)->toBe(['suppressed'])
        ->and($receipt->contactId)->toBeNull()
        ->and($receipt->created)->toBeFalse();
});

it('reads a message status with its delivery log', function (): void {
    Http::fake(['engage.test/v1/messages/msg_1' => Http::response([
        'id' => 'msg_1', 'status' => 'sent', 'delivery_status' => 'bounced', 'purpose' => 'transactional', 'brand_id' => 'br_1',
        'contact_id' => 'ct_1', 'to' => 'a••@example.com', 'subject' => 'Your code', 'provider' => 'resend', 'provider_message_id' => 're_1',
        'queued_at' => '2026-10-30T10:00:00Z', 'sent_at' => '2026-10-30T10:00:01Z', 'failure' => null,
        'gate' => ['allowed' => true, 'reasons' => [], 'stage' => 'send'],
        'events' => [
            ['status' => 'sent', 'at' => '2026-10-30T10:00:01Z', 'detail' => []],
            ['status' => 'bounced', 'at' => '2026-10-30T10:00:05Z', 'detail' => ['type' => 'hard']],
        ],
    ])]);

    $status = app(Messages::class)->status('msg_1');

    expect($status->status)->toBe(MessageState::Sent)
        ->and($status->deliveryStatus)->toBe(DeliveryStatus::Bounced)
        ->and($status->provider)->toBe('resend')
        ->and($status->to)->toBe('a••@example.com')
        ->and($status->sentAt?->toIso8601ZuluString())->toBe('2026-10-30T10:00:01Z')
        ->and($status->gate->allowed)->toBeTrue()
        ->and($status->gate->stage)->toBe('send')
        ->and($status->events)->toHaveCount(2)
        ->and($status->latestEvent()?->status)->toBe('bounced')
        ->and($status->latestEvent()?->detail)->toBe(['type' => 'hard']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://engage.test/v1/messages/msg_1');
});

it('throws EngageUnavailable on a timeout', function (): void {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    $timeout = null;

    try {
        app(Messages::class)->send(outgoing());
    } catch (EngageUnavailable $e) {
        $timeout = $e;
    }

    expect($timeout?->reason)->toBe('timeout')
        ->and($timeout?->getPrevious())->toBeInstanceOf(ConnectionException::class);
});

it('throws EngageUnavailable on a 5xx', function (): void {
    Http::fake(['engage.test/*' => Http::response('', 503)]);
    $down = null;

    try {
        app(Messages::class)->send(outgoing());
    } catch (EngageUnavailable $e) {
        $down = $e;
    }

    expect($down?->reason)->toBe('http_5xx');
});

it('throws EngageUnavailable with Retry-After on a 429', function (): void {
    Http::fake(['engage.test/*' => Http::response('', 429, ['Retry-After' => '7'])]);
    $throttled = null;

    try {
        app(Messages::class)->status('msg_1');
    } catch (EngageUnavailable $e) {
        $throttled = $e;
    }

    expect($throttled?->reason)->toBe('http_429')
        ->and($throttled?->retryAfter)->toBe(7)
        ->and($throttled)->toBeInstanceOf(EngageException::class);
});

it('throws EngageRejected with the body\'s error on any other 4xx', function (): void {
    Http::fake(['engage.test/*' => Http::response(['error' => 'validation', 'errors' => ['brand' => ['The brand is unknown.']]], 422)]);
    $rejected = null;

    try {
        app(Messages::class)->send(outgoing());
    } catch (EngageRejected $e) {
        $rejected = $e;
    }

    expect($rejected?->status)->toBe(422)
        ->and($rejected?->error)->toBe('validation')
        ->and($rejected?->errors)->toBe(['brand' => ['The brand is unknown.']])
        ->and($rejected?->getMessage())->toContain('brand The brand is unknown.');
});

it('throws EngageRejected as not_found for an unknown id', function (): void {
    Http::fake(['engage.test/*' => Http::response('', 404)]);
    $missing = null;

    try {
        app(Messages::class)->status('msg_x');
    } catch (EngageRejected $e) {
        $missing = $e;
    }

    expect($missing?->error)->toBe('not_found');
});

it('refuses to be used when Engage is not configured', function (): void {
    expect(app(Messages::class))->toBeInstanceOf(HttpMessages::class);

    config(['engage.url' => '']);

    $messages = app(Messages::class);

    expect($messages)->toBeInstanceOf(NullMessages::class)
        ->and(fn () => $messages->send(outgoing()))->toThrow(EngageNotConfigured::class)
        ->and(fn () => $messages->status('msg_1'))->toThrow(EngageNotConfigured::class);
});

it('posts a batch and maps the receipt', function (): void {
    Http::fake(['engage.test/v1/message-batches' => Http::response(['id' => 'mb_1', 'status' => 'accepted', 'recipients' => 1, 'created' => true], 202)]);
    $batch = incidentBatch();

    $receipt = app(Messages::class)->sendMany($batch);

    expect($receipt->id)->toBe('mb_1')
        ->and($receipt->status)->toBe(BatchState::Accepted)
        ->and($receipt->recipients)->toBe(1)
        ->and($receipt->created)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://engage.test/v1/message-batches'
        && $request->hasHeader('Authorization', 'Bearer ek_test')
        && $request->data() === $batch->toArray());
});

it('maps a replayed batch key', function (): void {
    Http::fake(['engage.test/v1/message-batches' => Http::response(['id' => 'mb_1', 'status' => 'completed', 'recipients' => 3, 'created' => false], 200)]);

    $receipt = app(Messages::class)->sendMany(incidentBatch());

    expect($receipt->created)->toBeFalse()->and($receipt->status)->toBe(BatchState::Completed)->and($receipt->recipients)->toBe(3);
});

it('reads a batch with its counts and a page of recipients', function (): void {
    Http::fake(['engage.test/v1/message-batches/mb_1*' => Http::response([
        'id' => 'mb_1', 'idempotency_key' => 'incident:inc_42:update:3', 'status' => 'completed', 'purpose' => 'transactional',
        'brand_id' => 'br_1', 'template_id' => null, 'recipients' => 3,
        'counts' => ['pending' => 0, 'rejected' => 1, 'queued' => 0, 'gated_out' => 1, 'sent' => 1, 'failed' => 0, 'expired' => 0],
        'created_at' => '2026-11-09T10:00:00Z', 'completed_at' => '2026-11-09T10:00:02Z',
        'items' => [
            ['idempotency_key' => 'k1', 'status' => 'sent', 'message_id' => 'msg_1', 'contact_id' => 'ct_1', 'delivery_status' => 'delivered', 'reasons' => [], 'error' => null],
            ['idempotency_key' => 'k2', 'status' => 'gated_out', 'message_id' => 'msg_2', 'contact_id' => null, 'delivery_status' => null, 'reasons' => ['suppressed'], 'error' => null],
            ['idempotency_key' => 'k3', 'status' => 'rejected', 'message_id' => null, 'contact_id' => null, 'delivery_status' => null, 'reasons' => [], 'error' => ['field' => 'company_external_id', 'message' => 'No current company membership.']],
        ],
        'next_cursor' => '42',
    ])]);

    $status = app(Messages::class)->batch('mb_1', cursor: '17', limit: 3);

    expect($status->completed())->toBeTrue()
        ->and($status->idempotencyKey)->toBe('incident:inc_42:update:3')
        ->and($status->recipients)->toBe(3)
        ->and($status->count('sent'))->toBe(1)
        ->and($status->count('gated_out'))->toBe(1)
        ->and($status->count('unknown'))->toBe(0)
        ->and($status->completedAt?->toIso8601ZuluString())->toBe('2026-11-09T10:00:02Z')
        ->and($status->nextCursor)->toBe('42')
        ->and($status->items)->toHaveCount(3)
        ->and($status->items[0]->sent())->toBeTrue()
        ->and($status->items[0]->deliveryStatus)->toBe(DeliveryStatus::Delivered)
        ->and($status->items[1]->gatedOut())->toBeTrue()
        ->and($status->items[1]->reasons)->toBe(['suppressed'])
        ->and($status->items[2]->rejected())->toBeTrue()
        ->and($status->items[2]->errorField)->toBe('company_external_id')
        ->and($status->items[2]->messageId)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://engage.test/v1/message-batches/mb_1?limit=3&cursor=17');
});

it('throws EngageUnavailable with Retry-After when the batch rate limit is hit', function (): void {
    Http::fake(['engage.test/*' => Http::response(['error' => 'rate_limited'], 429, ['Retry-After' => '30'])]);
    $throttled = null;

    try {
        app(Messages::class)->sendMany(incidentBatch());
    } catch (EngageUnavailable $e) {
        $throttled = $e;
    }

    expect($throttled?->reason)->toBe('http_429')->and($throttled?->retryAfter)->toBe(30);
});

it('refuses batches when Engage is not configured', function (): void {
    config(['engage.url' => '']);
    $messages = app(Messages::class);

    expect(fn () => $messages->sendMany(incidentBatch()))->toThrow(EngageNotConfigured::class)
        ->and(fn () => $messages->batch('mb_1'))->toThrow(EngageNotConfigured::class);
});
