<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

/** What Engage did with a message on arrival: took it, or the send gate refused it. */
enum ReceiptStatus: string
{
    case Queued = 'queued';
    case GatedOut = 'gated_out';
}
