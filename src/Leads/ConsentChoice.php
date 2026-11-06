<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

readonly class ConsentChoice
{
    public function __construct(public string $id, public string $status, public string $selectedAt,
        public string $confirmedAt, public string $text, public string $textVersion) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['id' => $this->id, 'status' => $this->status, 'selected_at' => $this->selectedAt,
            'confirmed_at' => $this->confirmedAt, 'text' => $this->text, 'text_version' => $this->textVersion];
    }
}
