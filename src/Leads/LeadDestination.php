<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

use InvalidArgumentException;

/** Explicit per-workspace credentials. No fallback to the host's own ingest key. */
readonly class LeadDestination
{
    public function __construct(public string $url, public string $key, public float $timeout = 5.0)
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true) || $timeout <= 0 || $timeout > 30
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || $key === '') {
            throw new InvalidArgumentException('Lead destinations need an HTTPS origin and an explicit ingest key.');
        }
    }
}
