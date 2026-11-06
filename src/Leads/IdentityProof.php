<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

/** The authenticated source attests both proofs; never infer an identity from an address. */
readonly class IdentityProof
{
    public function __construct(public string $externalId, public string $method, public string $proofId, public string $provedAt) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['external_id' => $this->externalId, 'method' => $this->method, 'proof_id' => $this->proofId, 'proved_at' => $this->provedAt];
    }
}
