<?php

declare(strict_types=1);

namespace Cbox\Engage\Exceptions;

/** `ENGAGE_INGEST_URL` or `ENGAGE_INGEST_KEY` is empty and something needed Engage anyway. */
class EngageNotConfigured extends EngageException
{
    public static function forMessages(): self
    {
        return new self('Cbox Engage is not configured: set ENGAGE_INGEST_URL and ENGAGE_INGEST_KEY before sending messages.');
    }

    public static function forMailer(): self
    {
        return new self('The engage mail driver needs ENGAGE_INGEST_URL and ENGAGE_INGEST_KEY; a mailer that silently sends nothing would be worse than this error.');
    }
}
