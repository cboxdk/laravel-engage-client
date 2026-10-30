<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

use Cbox\Engage\Contracts\ProductEvents;

/** Bound when ENGAGE_INGEST_URL or ENGAGE_INGEST_KEY is empty: events go nowhere. */
class NullProductEvents implements ProductEvents
{
    public function record(ProductEvent $event): void {}
}
