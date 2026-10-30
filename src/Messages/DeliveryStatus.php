<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/** The furthest the provider has reported a sent message to have come. */
enum DeliveryStatus: string
{
    case Delivered = 'delivered';
    case Bounced = 'bounced';
    case Complained = 'complained';
    case Opened = 'opened';
    case Clicked = 'clicked';
}
