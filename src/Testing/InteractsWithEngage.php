<?php

declare(strict_types=1);

namespace Cbox\Engage\Testing;

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Contracts\ProductEvents;
use Illuminate\Contracts\Foundation\Application;

/**
 * Swap Cbox Engage for {@see FakeEngage}: product events and messages are kept in memory,
 * nothing is queued or sent. The `engage` mail driver, if built afterwards, sends to the
 * fake too.
 */
trait InteractsWithEngage
{
    protected function fakeEngage(): FakeEngage
    {
        $fake = new FakeEngage;

        $this->engageApplication()->instance(ProductEvents::class, $fake);
        $this->engageApplication()->instance(Messages::class, $fake);

        return $fake;
    }

    private function engageApplication(): Application
    {
        $app = $this->app ?? null;

        if (! $app instanceof Application) {
            throw new \LogicException('fakeEngage() needs a booted application; use it from a Laravel or Testbench test case.');
        }

        return $app;
    }
}
