<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use InvalidArgumentException;

/**
 * Who a message goes to: the app's own id for the person (`external_id`, resolved in the
 * ingest key's identity namespace) and/or an address. Engage resolves the contact from
 * the external id first, then the address; with both and no match it creates the contact.
 */
readonly class Recipient
{
    public function __construct(
        public ?string $externalId = null,
        public ?string $email = null,
        public ?string $name = null,
    ) {
        if (($externalId === null || $externalId === '') && ($email === null || $email === '')) {
            throw new InvalidArgumentException('A recipient needs an external id, an email address, or both.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return array_filter([
            'external_id' => $this->externalId,
            'email' => $this->email,
            'name' => $this->name,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }
}
