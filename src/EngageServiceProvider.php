<?php

declare(strict_types=1);

namespace Cbox\Engage;

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\HttpProductEvents;
use Cbox\Engage\Events\NullProductEvents;
use Cbox\Engage\Exceptions\EngageNotConfigured;
use Cbox\Engage\Mail\CircuitBreaker;
use Cbox\Engage\Mail\EngageTransport;
use Cbox\Engage\Mail\MailOptions;
use Cbox\Engage\Messages\HttpMessages;
use Cbox\Engage\Messages\NullMessages;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Binds the two contracts to their HTTP implementations when `engage.url` and
 * `engage.key` are set, to their unconfigured stand-ins otherwise, and registers the
 * `engage` mail transport.
 */
class EngageServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/engage.php', 'engage');

        $this->app->singleton(Engage::class);

        $this->app->bind(ProductEvents::class, fn (Application $app): ProductEvents => $app->make(Engage::class)->configured()
            ? $app->make(HttpProductEvents::class)
            : new NullProductEvents);

        $this->app->bind(Messages::class, fn (Application $app): Messages => $app->make(Engage::class)->configured()
            ? $app->make(HttpMessages::class)
            : new NullMessages);

        $this->app->singleton(CircuitBreaker::class, function (Application $app): CircuitBreaker {
            $config = $app->make(Repository::class);
            $store = $config->get('engage.mail.breaker.store');

            return new CircuitBreaker(
                $app->make(CacheFactory::class)->store(is_string($store) && $store !== '' ? $store : null),
                $this->int($config, 'engage.mail.breaker.failures', 5),
                $this->int($config, 'engage.mail.breaker.window', 60),
                $this->int($config, 'engage.mail.breaker.open_for', 120),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/engage.php' => $this->app->configPath('engage.php')], 'engage-config');
        }

        $this->callAfterResolving(MailManager::class, function (MailManager $manager): void {
            $manager->extend('engage', fn (array $config): TransportInterface => $this->transport($manager, $config));
        });
    }

    /**
     * @param  array<array-key, mixed>  $config  the mailer's entry in `config/mail.php`, as MailManager hands it over
     */
    protected function transport(MailManager $manager, array $config): EngageTransport
    {
        if (! $this->app->make(Engage::class)->configured()) {
            throw EngageNotConfigured::forMailer();
        }

        $name = $config['name'] ?? null;
        $fallback = $config['fallback'] ?? null;
        $fallback = is_string($fallback) && $fallback !== '' ? $fallback : null;

        if ($fallback !== null && $fallback === $name) {
            throw new InvalidArgumentException("The engage mailer '{$fallback}' cannot be its own fallback.");
        }

        return new EngageTransport(
            $this->app->make(Messages::class),
            $this->app->make(CircuitBreaker::class),
            $this->app->make(ProductEvents::class),
            MailOptions::fromConfig($this->app->make(Repository::class)),
            $fallback === null ? null : static fn (): TransportInterface => $manager->mailer($fallback)->getSymfonyTransport(),
            $fallback,
            $this->app->make(LoggerInterface::class),
        );
    }

    private function int(Repository $config, string $key, int $default): int
    {
        $value = $config->get($key);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
