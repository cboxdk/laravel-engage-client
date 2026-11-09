<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Exceptions\EngageNotConfigured;

/**
 * Bound when ENGAGE_INGEST_URL or ENGAGE_INGEST_KEY is empty. Unlike product events, a
 * message that silently goes nowhere is a bug, so every call throws.
 */
class NullMessages implements Messages
{
    public function send(OutgoingMessage $message): MessageReceipt
    {
        throw EngageNotConfigured::forMessages();
    }

    public function status(string $id): MessageStatus
    {
        throw EngageNotConfigured::forMessages();
    }

    public function sendMany(OutgoingBatch $batch): BatchReceipt
    {
        throw EngageNotConfigured::forMessages();
    }

    public function batch(string $id, ?string $cursor = null, int $limit = 100): BatchStatus
    {
        throw EngageNotConfigured::forMessages();
    }
}
