<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

use Illuminate\Contracts\Config\Repository;

/** The driver's defaults from `config('engage.mail')`, each overridable per message. */
readonly class MailOptions
{
    public function __construct(
        public ?string $brand = null,
        public string $purpose = 'transactional',
        public ?bool $trackClicks = false,
        public ?bool $trackOpens = null,
        public ?string $product = null,
        public bool $fallbackOnGate = true,
        public bool $fallbackOnRejection = false,
    ) {}

    public static function fromConfig(Repository $config): self
    {
        $brand = $config->get('engage.mail.brand');
        $purpose = $config->get('engage.mail.purpose');
        $trackClicks = $config->get('engage.mail.track_clicks');
        $trackOpens = $config->get('engage.mail.track_opens');
        $product = $config->get('engage.product');

        return new self(
            is_string($brand) && $brand !== '' ? $brand : null,
            is_string($purpose) && $purpose !== '' ? $purpose : 'transactional',
            is_bool($trackClicks) ? $trackClicks : null,
            is_bool($trackOpens) ? $trackOpens : null,
            is_string($product) && $product !== '' ? $product : null,
            $config->get('engage.mail.fallback_on_gate') !== false,
            $config->get('engage.mail.fallback_on_rejection') === true,
        );
    }
}
