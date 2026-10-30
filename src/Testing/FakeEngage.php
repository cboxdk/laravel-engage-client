<?php

declare(strict_types=1);

namespace Cbox\Engage\Testing;

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\MessageReceipt;
use Cbox\Engage\Messages\MessageState;
use Cbox\Engage\Messages\MessageStatus;
use Cbox\Engage\Messages\OutgoingMessage;
use Cbox\Engage\Messages\ReceiptStatus;
use PHPUnit\Framework\Assert;

/**
 * Cbox Engage in memory: product events are kept, messages are kept and answered with a
 * queued receipt (or gated out, or unavailable, as the test says). Nothing is queued,
 * nothing leaves the process.
 */
class FakeEngage implements Messages, ProductEvents
{
    /** @var list<ProductEvent> */
    private array $events = [];

    /** @var list<OutgoingMessage> */
    private array $messages = [];

    /** @var array<string, MessageReceipt> */
    private array $receipts = [];

    /** @var list<string> */
    private ?array $refusing = null;

    private ?string $failing = null;

    public function record(ProductEvent $event): void
    {
        $this->events[] = $event;
    }

    public function send(OutgoingMessage $message): MessageReceipt
    {
        if ($this->failing !== null) {
            throw new EngageUnavailable('Cbox Engage is unavailable (faked).', $this->failing);
        }

        $this->messages[] = $message;

        if (isset($this->receipts[$message->idempotencyKey])) {
            $receipt = $this->receipts[$message->idempotencyKey];

            return new MessageReceipt($receipt->id, $receipt->status, $receipt->reasons, $receipt->contactId, false);
        }

        $receipt = new MessageReceipt(
            'msg_fake_'.count($this->receipts) + 1,
            $this->refusing === null ? ReceiptStatus::Queued : ReceiptStatus::GatedOut,
            $this->refusing ?? [],
            $message->to->externalId === null ? null : 'ct_fake_'.$message->to->externalId,
        );

        return $this->receipts[$message->idempotencyKey] = $receipt;
    }

    public function status(string $id): MessageStatus
    {
        if ($this->failing !== null) {
            throw new EngageUnavailable('Cbox Engage is unavailable (faked).', $this->failing);
        }

        foreach ($this->receipts as $key => $receipt) {
            if ($receipt->id !== $id) {
                continue;
            }

            $message = $this->sent(static fn (OutgoingMessage $m): bool => $m->idempotencyKey === $key)[0] ?? null;

            return new MessageStatus(
                id: $id,
                status: $receipt->gatedOut() ? MessageState::GatedOut : MessageState::Queued,
                purpose: $message === null ? 'transactional' : $message->purpose,
                brandId: $message?->brand,
                contactId: $receipt->contactId,
                subject: $message?->content?->subject,
            );
        }

        throw new EngageRejected(404, 'not_found');
    }

    /**
     * Every send from now on is gated out with these reasons (`['suppressed']`, `['no_consent']`, …).
     *
     * @param  list<string>  $reasons
     */
    public function willRefuse(array $reasons): static
    {
        $this->refusing = array_values(array_filter($reasons, 'is_string'));
        $this->failing = null;

        return $this;
    }

    /** Every call from now on throws EngageUnavailable, as a timeout unless another reason is given. */
    public function willFail(string $reason = EngageUnavailable::TIMEOUT): static
    {
        $this->failing = $reason;

        return $this;
    }

    /** Back to answering every send with a queued receipt. */
    public function willAccept(): static
    {
        $this->refusing = null;
        $this->failing = null;

        return $this;
    }

    /**
     * @return list<ProductEvent>
     */
    public function recorded(?string $name = null): array
    {
        return array_values(array_filter($this->events, static fn (ProductEvent $event): bool => $name === null || $event->name === $name));
    }

    /**
     * @param  (callable(OutgoingMessage): bool)|null  $where
     * @return list<OutgoingMessage>
     */
    public function sent(?callable $where = null): array
    {
        return array_values(array_filter($this->messages, static fn (OutgoingMessage $message): bool => $where === null || $where($message)));
    }

    /**
     * @param  (callable(ProductEvent): bool)|null  $where
     */
    public function assertRecorded(string $name, ?callable $where = null): void
    {
        $matching = array_filter($this->recorded($name), static fn (ProductEvent $event): bool => $where === null || $where($event));

        Assert::assertNotEmpty($matching, $where === null
            ? "No '{$name}' product event was recorded."
            : "No '{$name}' product event matching the condition was recorded.");
    }

    public function assertNotRecorded(string $name): void
    {
        Assert::assertSame([], $this->recorded($name), "A '{$name}' product event was recorded.");
    }

    public function assertNothingRecorded(): void
    {
        Assert::assertSame([], $this->events, 'Product events were recorded: '.implode(', ', array_map(static fn (ProductEvent $event): string => $event->name, $this->events)));
    }

    /**
     * @param  (callable(OutgoingMessage): bool)|null  $where
     */
    public function assertSent(?callable $where = null): void
    {
        Assert::assertNotEmpty($this->sent($where), $where === null
            ? 'No message was sent to Cbox Engage.'
            : 'No message matching the condition was sent to Cbox Engage.');
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->messages, 'Messages were sent to Cbox Engage: '.implode(', ', array_map(static fn (OutgoingMessage $message): string => $message->idempotencyKey, $this->messages)));
    }
}
