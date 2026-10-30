<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/**
 * What `POST /v1/messages` answered. `created` is false when the idempotency key was seen
 * before: then this is the existing message, whatever its state. `reasons` are the send
 * gate's when the status is `gated_out` (`suppressed`, `no_consent`, `frequency_cap`, …).
 *
 * The zero-argument form is a queued receipt with an empty id, so a test double can build
 * one without reflection.
 */
readonly class MessageReceipt
{
    public const string SUPPRESSED = 'suppressed';

    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public string $id = '',
        public ReceiptStatus $status = ReceiptStatus::Queued,
        public array $reasons = [],
        public ?string $contactId = null,
        public bool $created = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'id'),
            ReceiptStatus::tryFrom(Payload::string($data, 'status')) ?? ReceiptStatus::Queued,
            Payload::strings($data, 'reasons'),
            Payload::nullableString($data, 'contact_id'),
            Payload::bool($data, 'created', true),
        );
    }

    public function queued(): bool
    {
        return $this->status === ReceiptStatus::Queued;
    }

    public function gatedOut(): bool
    {
        return $this->status === ReceiptStatus::GatedOut;
    }

    /** The address is dead to Engage (a hard bounce): sending it elsewhere would not help. */
    public function suppressed(): bool
    {
        return in_array(self::SUPPRESSED, $this->reasons, true);
    }
}
