<?php

declare(strict_types=1);

namespace Cbox\Engage\Exceptions;

use Throwable;

/**
 * Engage did not take the call this time (unreachable, timed out, 5xx, over quota);
 * later it may. `$reason` names which: `timeout`, `unreachable`, `http_5xx` or `http_429`.
 */
class EngageUnavailable extends EngageException
{
    public const string TIMEOUT = 'timeout';

    public const string UNREACHABLE = 'unreachable';

    public const string HTTP_5XX = 'http_5xx';

    public const string HTTP_429 = 'http_429';

    public function __construct(
        string $message,
        public readonly string $reason = self::UNREACHABLE,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromConnection(Throwable $e): self
    {
        $timedOut = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');

        return new self('Cbox Engage could not be reached: '.$e->getMessage(), $timedOut ? self::TIMEOUT : self::UNREACHABLE, null, $e);
    }

    public static function fromStatus(int $status, ?int $retryAfter = null): self
    {
        return new self("Cbox Engage answered {$status}.", $status === 429 ? self::HTTP_429 : self::HTTP_5XX, $retryAfter);
    }
}
