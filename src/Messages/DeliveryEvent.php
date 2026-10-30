<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use Carbon\CarbonImmutable;

/**
 * One entry of a message's delivery log (`sent`, `failed`, `delivered`, `bounced`,
 * `complained`, `opened`, `clicked`, `unsubscribed`). `detail` is the provider's own
 * record for the entry, e.g. `{"type": "hard", "bounce": {…}}` on a bounce.
 */
readonly class DeliveryEvent
{
    /**
     * @param  array<string, mixed>  $detail
     */
    public function __construct(
        public string $status,
        public CarbonImmutable $at,
        public array $detail = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::string($data, 'status'),
            Payload::time($data, 'at') ?? CarbonImmutable::now(),
            Payload::map($data, 'detail'),
        );
    }
}
