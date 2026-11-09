<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use Carbon\CarbonImmutable;

/**
 * What `GET /v1/message-batches/{id}` answered: the batch, how many recipients stand
 * where (`pending`, `rejected`, `queued`, `gated_out`, `sent`, `failed`, `expired`), and
 * one page of recipients in the order they were sent. Pass `nextCursor` back for the
 * next page; it is null on the last.
 *
 * The zero-argument form is an accepted batch with an empty id, so a test double can
 * build one without reflection.
 */
readonly class BatchStatus
{
    /**
     * @param  array<string, int>  $counts
     * @param  list<BatchItem>  $items
     */
    public function __construct(
        public string $id = '',
        public BatchState $status = BatchState::Accepted,
        public ?string $idempotencyKey = null,
        public string $purpose = 'transactional',
        public ?string $brandId = null,
        public ?string $templateId = null,
        public int $recipients = 0,
        public array $counts = [],
        public array $items = [],
        public ?string $nextCursor = null,
        public ?CarbonImmutable $createdAt = null,
        public ?CarbonImmutable $completedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $counts = [];

        foreach (Payload::map($data, 'counts') as $status => $count) {
            if (is_int($count)) {
                $counts[$status] = $count;
            }
        }

        $recipients = $data['recipients'] ?? null;

        return new self(
            Payload::string($data, 'id'),
            BatchState::tryFrom(Payload::string($data, 'status')) ?? BatchState::Accepted,
            Payload::nullableString($data, 'idempotency_key'),
            Payload::string($data, 'purpose', 'transactional'),
            Payload::nullableString($data, 'brand_id'),
            Payload::nullableString($data, 'template_id'),
            is_int($recipients) ? $recipients : 0,
            $counts,
            array_map(BatchItem::fromArray(...), Payload::list($data, 'items')),
            Payload::nullableString($data, 'next_cursor'),
            Payload::time($data, 'created_at'),
            Payload::time($data, 'completed_at'),
        );
    }

    /** Every recipient is through the send gate (or rejected); nothing is pending. */
    public function completed(): bool
    {
        return $this->status === BatchState::Completed;
    }

    /** How many recipients have this status (`sent`, `gated_out`, …). */
    public function count(string $status): int
    {
        return $this->counts[$status] ?? 0;
    }
}
