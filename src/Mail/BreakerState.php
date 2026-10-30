<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

/**
 * Closed: Engage is called. Open: it is not, the fallback sends. Half-open: the open
 * period has passed and one call is let through to see whether Engage is back.
 */
enum BreakerState: string
{
    case Closed = 'closed';
    case Open = 'open';
    case HalfOpen = 'half_open';
}
