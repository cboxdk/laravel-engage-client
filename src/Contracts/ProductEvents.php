<?php

declare(strict_types=1);

namespace Cbox\Engage\Contracts;

use Cbox\Engage\Events\ProductEvent;

/**
 * What happened in the product, told to Cbox Engage: a sign-in, a project, a key, a
 * plan change. Recording never fails the caller; delivery is queued and retried behind
 * it, and with Engage unconfigured it goes nowhere.
 */
interface ProductEvents
{
    public function record(ProductEvent $event): void;
}
