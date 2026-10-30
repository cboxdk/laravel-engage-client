<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/** Where a message is in Engage's own pipeline. */
enum MessageState: string
{
    case Queued = 'queued';
    case Sent = 'sent';
    case GatedOut = 'gated_out';
    case Failed = 'failed';
}
