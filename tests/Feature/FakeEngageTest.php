<?php

declare(strict_types=1);

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\BatchRecipient;
use Cbox\Engage\Messages\BatchState;
use Cbox\Engage\Messages\InlineContent;
use Cbox\Engage\Messages\MessageState;
use Cbox\Engage\Messages\OutgoingBatch;
use Cbox\Engage\Messages\OutgoingMessage;
use Cbox\Engage\Messages\Recipient;
use PHPUnit\Framework\AssertionFailedError;

it('binds one fake as both contracts', function (): void {
    $fake = $this->fakeEngage();

    expect(app(ProductEvents::class))->toBe($fake)
        ->and(app(Messages::class))->toBe($fake);
});

it('records events and asserts on them', function (): void {
    $fake = $this->fakeEngage();
    $fake->assertNothingRecorded();

    app(ProductEvents::class)->record(ProductEvent::byContact('user.signed_in', 'app:user.signed_in:usr_1', 'usr_1', props: ['first' => true]));

    $fake->assertRecorded('user.signed_in');
    $fake->assertRecorded('user.signed_in', fn (ProductEvent $e): bool => $e->props === ['first' => true]);
    $fake->assertNotRecorded('user.signed_out');

    expect(fn () => $fake->assertRecorded('user.signed_in', fn (ProductEvent $e): bool => $e->props === []))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingRecorded())->toThrow(AssertionFailedError::class);
});

it('answers sends with a queued receipt, replays a key, and reports status', function (): void {
    $fake = $this->fakeEngage();
    $fake->assertNothingSent();
    $message = new OutgoingMessage('otp:usr_1', new Recipient('usr_1', 'ada@example.com'), 'br_1', content: new InlineContent('Your code', '<p>1</p>'));

    $first = app(Messages::class)->send($message);
    $again = app(Messages::class)->send($message);

    expect($first->queued())->toBeTrue()
        ->and($first->contactId)->toBe('ct_fake_usr_1')
        ->and($again->id)->toBe($first->id)
        ->and($again->created)->toBeFalse()
        ->and(app(Messages::class)->status($first->id)->status)->toBe(MessageState::Queued)
        ->and(app(Messages::class)->status($first->id)->subject)->toBe('Your code')
        ->and(fn () => app(Messages::class)->status('msg_nope'))->toThrow(EngageRejected::class);

    $fake->assertSent();
    $fake->assertSent(fn (OutgoingMessage $m): bool => $m->idempotencyKey === 'otp:usr_1');
    expect(fn () => $fake->assertSent(fn (OutgoingMessage $m): bool => $m->brand === 'br_2'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class);
});

it('can refuse and fail on demand', function (): void {
    $fake = $this->fakeEngage()->willRefuse(['no_consent']);
    $message = new OutgoingMessage('k', new Recipient('usr_1'), 'br_1', content: new InlineContent('s', '<p>h</p>'));

    $receipt = $fake->send($message);
    expect($receipt->gatedOut())->toBeTrue()->and($receipt->reasons)->toBe(['no_consent']);

    $fake->willFail();
    expect(fn () => $fake->send($message))->toThrow(EngageUnavailable::class);

    $fake->willAccept();
    expect($fake->send(new OutgoingMessage('k2', new Recipient('usr_1'), 'br_1', content: new InlineContent('s', '<p>h</p>')))->queued())->toBeTrue();
});

it('answers a batch per recipient from the same keyspace as send, replays its key, and pages its status', function (): void {
    $fake = $this->fakeEngage();
    $single = $fake->send(new OutgoingMessage('incident:1:usr_1', new Recipient('usr_1'), 'br_1', content: new InlineContent('s', '<p>h</p>')));
    $batch = new OutgoingBatch('incident:1', 'br_1', [
        new BatchRecipient('incident:1:usr_1', new Recipient('usr_1')),
        new BatchRecipient('incident:1:sub_2', new Recipient(email: 'grace@example.com'), ['unsubscribe_url' => 'https://status.test/u/2']),
    ], content: new InlineContent('Down', '<p>{{ unsubscribe_url }}</p>'));

    $receipt = app(Messages::class)->sendMany($batch);
    $again = app(Messages::class)->sendMany($batch);

    expect($receipt->status)->toBe(BatchState::Completed)
        ->and($receipt->recipients)->toBe(2)
        ->and($again->id)->toBe($receipt->id)
        ->and($again->created)->toBeFalse();

    $status = app(Messages::class)->batch($receipt->id, limit: 1);
    expect($status->count('queued'))->toBe(2)
        ->and($status->items)->toHaveCount(1)
        ->and($status->items[0]->messageId)->toBe($single->id)   // the key was already sent on its own
        ->and($status->nextCursor)->toBe('1')
        ->and(app(Messages::class)->batch($receipt->id, cursor: '1')->items[0]->contactId)->toBeNull()
        ->and(fn () => app(Messages::class)->batch('mb_nope'))->toThrow(EngageRejected::class);

    $fake->assertBatchSent();
    $fake->assertBatchSent(fn (OutgoingBatch $b): bool => $b->idempotencyKey === 'incident:1');
    $fake->assertBatchRecipient(fn (BatchRecipient $r): bool => $r->to->email === 'grace@example.com' && $r->variables['unsubscribe_url'] === 'https://status.test/u/2');
    expect($fake->batchesSent())->toHaveCount(2)
        ->and(fn () => $fake->assertBatchRecipient(fn (BatchRecipient $r): bool => $r->to->email === 'linus@example.com'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertBatchSent(fn (OutgoingBatch $b): bool => $b->brand === 'br_2'))->toThrow(AssertionFailedError::class);
});

it('gates out every batch recipient when refusing, fails on demand, and counts batches as sent', function (): void {
    $fake = $this->fakeEngage();
    $fake->assertNothingSent();
    $batch = new OutgoingBatch('b', 'br_1', [new BatchRecipient('b:1', new Recipient('usr_1'))], template: 'tp_1');

    $fake->willRefuse(['suppressed']);
    $status = $fake->batch($fake->sendMany($batch)->id);
    expect($status->items[0]->gatedOut())->toBeTrue()->and($status->items[0]->reasons)->toBe(['suppressed']);

    $fake->willFail();
    expect(fn () => $fake->sendMany($batch))->toThrow(EngageUnavailable::class)
        ->and(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class);
});
