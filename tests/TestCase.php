<?php

declare(strict_types=1);

namespace Cbox\Engage\Tests;

use Cbox\Engage\EngageServiceProvider;
use Cbox\Engage\Testing\InteractsWithEngage;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use InteractsWithEngage;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [EngageServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        $config->set('cache.default', 'array');
        $config->set('queue.default', 'sync');
        $config->set('engage.url', 'https://engage.test');
        $config->set('engage.key', 'ek_test');
        $config->set('engage.product', 'cbox-test');
        $config->set('engage.mail.brand', 'br_test');
        $config->set('mail.default', 'engage');
        $config->set('mail.mailers.engage', ['transport' => 'engage', 'fallback' => 'array']);
        $config->set('mail.mailers.array', ['transport' => 'array']);
        $config->set('mail.from', ['address' => 'noreply@example.com', 'name' => 'Example']);
    }
}
