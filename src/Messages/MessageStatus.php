<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use Carbon\CarbonImmutable;

/**
 * What `GET /v1/messages/{id}` answered: the message's state, the send gate's decision,
 * and its delivery log oldest first. `to` is the masked address as Engage shows it.
 *
 * The zero-argument form is a queued message with an empty id, so a test double can
 * build one without reflection.
 */
readonly class MessageStatus
{
    /**
     * @param  list<DeliveryEvent>  $events
     */
    public function __construct(
        public string $id = '',
        public MessageState $status = MessageState::Queued,
        public ?DeliveryStatus $deliveryStatus = null,
        public string $purpose = 'transactional',
        public ?string $brandId = null,
        public ?string $contactId = null,
        public ?string $to = null,
        public ?string $subject = null,
        public ?string $provider = null,
        public ?string $providerMessageId = null,
        public ?CarbonImmutable $queuedAt = null,
        public ?CarbonImmutable $sentAt = null,
        public ?string $failure = null,
        public GateDecision $gate = new GateDecision,
        public array $events = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $events = [];

        foreach (Payload::list($data, 'events') as $event) {
            $events[] = DeliveryEvent::fromArray($event);
        }

        $gate = Payload::map($data, 'gate');

        return new self(
            Payload::string($data, 'id'),
            MessageState::tryFrom(Payload::string($data, 'status')) ?? MessageState::Queued,
            DeliveryStatus::tryFrom(Payload::string($data, 'delivery_status')),
            Payload::string($data, 'purpose', 'transactional'),
            Payload::nullableString($data, 'brand_id'),
            Payload::nullableString($data, 'contact_id'),
            Payload::nullableString($data, 'to'),
            Payload::nullableString($data, 'subject'),
            Payload::nullableString($data, 'provider'),
            Payload::nullableString($data, 'provider_message_id'),
            Payload::time($data, 'queued_at'),
            Payload::time($data, 'sent_at'),
            Payload::nullableString($data, 'failure'),
            $gate === [] ? new GateDecision : GateDecision::fromArray($gate),
            $events,
        );
    }

    /** The most recent entry of the delivery log, if any. */
    public function latestEvent(): ?DeliveryEvent
    {
        return $this->events === [] ? null : $this->events[count($this->events) - 1];
    }
}
