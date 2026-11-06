<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

readonly class Interest
{
    public function __construct(public string $id, public string $brandId, public string $service, public string $name,
        public string $status = 'waiting', public string $kind = 'service_access', public ?string $feature = null, public ?string $program = null) {}

    /** @return array<string, mixed> */
    public function topic(): array
    {
        return ['id' => $this->id, 'brand_id' => $this->brandId, 'service' => $this->service, 'name' => $this->name,
            'kind' => $this->kind, 'feature' => $this->feature, 'program' => $this->program];
    }
}
