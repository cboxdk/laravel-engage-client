<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

use Cbox\Engage\Exceptions\EngageUnavailable;

readonly class LeadReceipt
{
    public function __construct(public string $status = 'accepted', public ?string $contactId = null, public ?string $topicId = null, public bool $duplicate = false) {}

    public static function fromJson(mixed $body): self
    {
        if (! is_array($body) || ! in_array($body['status'] ?? null, ['accepted', 'erased'], true)) {
            throw new EngageUnavailable('Invalid lead receipt.', 'invalid_response');
        }

        return new self($body['status'], is_string($body['contact_id'] ?? null) ? $body['contact_id'] : null,
            is_string($body['topic_id'] ?? null) ? $body['topic_id'] : null, ($body['duplicate'] ?? false) === true);
    }
}
