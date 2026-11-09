<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/** Where a batch is: taken, being fanned out to the send gate, or through it. */
enum BatchState: string
{
    case Accepted = 'accepted';
    case Processing = 'processing';
    case Completed = 'completed';
}
