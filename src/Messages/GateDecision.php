<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/** What the send gate decided about a message, and at which stage (`enqueue` or `send`). */
readonly class GateDecision
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $allowed = true,
        public array $reasons = [],
        public string $stage = 'enqueue',
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Payload::bool($data, 'allowed', true),
            Payload::strings($data, 'reasons'),
            Payload::string($data, 'stage', 'enqueue'),
        );
    }
}
