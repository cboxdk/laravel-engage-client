<?php

declare(strict_types=1);

namespace Cbox\Engage;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Where Cbox Engage is and how this app is named there, read from `config/engage.php`.
 * Without both the endpoint and the ingest key nothing in this package talks to Engage.
 */
class Engage
{
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
    ) {}

    public function configured(): bool
    {
        return $this->url() !== '' && $this->key() !== '';
    }

    /** The instance, without a trailing slash; empty when unconfigured. */
    public function url(): string
    {
        return rtrim($this->string('engage.url'), '/');
    }

    /** The ingest key; empty when unconfigured. Never log it. */
    public function key(): string
    {
        return $this->string('engage.key');
    }

    /** Seconds a message call may take. */
    public function timeout(): float
    {
        $timeout = $this->config->get('engage.timeout');

        return is_numeric($timeout) && (float) $timeout > 0 ? (float) $timeout : 2.0;
    }

    /** Seconds an events batch may take. */
    public function eventsTimeout(): int
    {
        $timeout = $this->config->get('engage.events.timeout');

        return is_int($timeout) && $timeout > 0 ? $timeout : 10;
    }

    /** The product key Engage shows on the timeline; null when the app has none. */
    public function product(): ?string
    {
        $product = $this->string('engage.product');

        return $product === '' ? null : $product;
    }

    /** `production`, or the environment's own name everywhere else. */
    public function environment(): string
    {
        $environment = $this->app->environment();

        return $environment === '' ? 'production' : $environment;
    }

    private function string(string $key): string
    {
        $value = $this->config->get($key);

        return is_string($value) ? trim($value) : '';
    }
}
