<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

use InvalidArgumentException;

/** Contact/consent data for the leads API, never event props. The host owns durable retry. */
readonly class LeadChange
{
    public function __construct(
        public string $workspaceId,
        public string $environment,
        public string $leadId,
        public int $sequence,
        public string $occurredAt,
        public string $operation = 'update',
        public ?string $email = null,
        public ?string $verifiedAt = null,
        public ?Interest $interest = null,
        public ?ConsentChoice $consent = null,
        public ?IdentityProof $identity = null,
    ) {
        if ($workspaceId === '' || $environment === '' || $leadId === '' || $sequence < 1 || ! in_array($operation, ['update', 'erase'], true)) {
            throw new InvalidArgumentException('A lead change needs a destination, source identity and positive sequence.');
        }
        if ($operation === 'update' && ($email === null || $verifiedAt === null || $interest === null)) {
            throw new InvalidArgumentException('An update needs a verified address and an interest.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $base = ['workspace_id' => $this->workspaceId, 'environment' => $this->environment, 'lead_id' => $this->leadId,
            'sequence' => $this->sequence, 'occurred_at' => $this->occurredAt, 'operation' => $this->operation];
        if ($this->operation === 'erase') {
            return $base;
        }

        return array_filter([...$base, 'email' => $this->email, 'verified_at' => $this->verifiedAt,
            'topic' => $this->interest?->topic(), 'status' => $this->interest?->status,
            'consent' => $this->consent?->toArray(), 'identity' => $this->identity?->toArray()], static fn (mixed $v): bool => $v !== null);
    }
}
