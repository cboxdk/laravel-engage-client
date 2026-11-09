<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/**
 * One recipient of a batch, as `GET /v1/message-batches/{id}` lists it. `status` is
 * `pending` until Engage has fanned it out, `rejected` when this recipient alone could
 * not be sent (`errorField`, `error`), then its message's own status (`queued`,
 * `gated_out`, `sent`, `failed`), and `expired` once that message is past the
 * workspace's retention. A string, because the list is Engage's and may grow.
 */
readonly class BatchItem
{
    public const string PENDING = 'pending';

    public const string REJECTED = 'rejected';

    /**
     * @param  list<string>  $reasons  the send gate's, when gated out
     */
    public function __construct(
        public string $idempotencyKey,
        public string $status,
        public ?string $messageId = null,
        public ?string $contactId = null,
        public ?DeliveryStatus $deliveryStatus = null,
        public array $reasons = [],
        public ?string $errorField = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $error = Payload::map($data, 'error');

        return new self(
            Payload::string($data, 'idempotency_key'),
            Payload::string($data, 'status', self::PENDING),
            Payload::nullableString($data, 'message_id'),
            Payload::nullableString($data, 'contact_id'),
            DeliveryStatus::tryFrom(Payload::string($data, 'delivery_status')),
            Payload::strings($data, 'reasons'),
            Payload::nullableString($error, 'field'),
            Payload::nullableString($error, 'message'),
        );
    }

    public function pending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function rejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function gatedOut(): bool
    {
        return $this->status === MessageState::GatedOut->value;
    }

    public function sent(): bool
    {
        return $this->status === MessageState::Sent->value;
    }
}
