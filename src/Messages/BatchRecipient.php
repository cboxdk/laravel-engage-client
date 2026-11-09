<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use InvalidArgumentException;

/**
 * One recipient of an {@see OutgoingBatch}: who, the app's own key for this recipient's
 * message (`incident:inc_42:update:3:sub_7`), and the variables that are theirs alone
 * (an unsubscribe link, a name). The key names the message in the ingest key's source:
 * already used, by a batch or by `send()`, it is that message, not a second one.
 */
readonly class BatchRecipient
{
    public const int MAX_VARIABLES = 50;

    /**
     * @param  array<string, bool|int|float|string|null>  $variables
     */
    public function __construct(
        public string $idempotencyKey,
        public Recipient $to,
        public array $variables = [],
        public ?string $companyExternalId = null,
    ) {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new InvalidArgumentException('The idempotency key must be 1 to 64 characters.');
        }

        if (count($variables) > self::MAX_VARIABLES) {
            throw new InvalidArgumentException('A batch recipient carries at most 50 variables.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $recipient = [
            'idempotency_key' => $this->idempotencyKey,
            'to' => $this->to->toArray(),
        ];

        if ($this->variables !== []) {
            $recipient['variables'] = $this->variables;
        }

        if ($this->companyExternalId !== null && $this->companyExternalId !== '') {
            $recipient['company_external_id'] = $this->companyExternalId;
        }

        return $recipient;
    }
}
