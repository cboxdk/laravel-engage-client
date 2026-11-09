<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/**
 * What `POST /v1/message-batches` answered: the batch id, and `created` false when the
 * batch key was seen before (then this is the existing batch, whatever its state). The
 * recipients go through the send gate on Engage's queue afterwards; `batch($id)` says
 * where each one stands.
 *
 * The zero-argument form is an accepted receipt with an empty id, so a test double can
 * build one without reflection.
 */
readonly class BatchReceipt
{
    public function __construct(
        public string $id = '',
        public BatchState $status = BatchState::Accepted,
        public int $recipients = 0,
        public bool $created = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $recipients = $data['recipients'] ?? null;

        return new self(
            Payload::string($data, 'id'),
            BatchState::tryFrom(Payload::string($data, 'status')) ?? BatchState::Accepted,
            is_int($recipients) ? $recipients : 0,
            Payload::bool($data, 'created', true),
        );
    }
}
