<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

/**
 * Fits a key into Engage's 1–64 characters. A Message-ID or a long natural key keeps its
 * meaning through a deterministic hash when it does not fit as is.
 */
class IdempotencyKey
{
    public const int MAX = 64;

    /** The key for the `$index`-th recipient (0-based) of one mail: the base, then `:<n>`. */
    public static function for(string $base, int $index): string
    {
        $base = trim($base, " \t\n\r\0\x0B<>");

        if ($base === '') {
            $base = bin2hex(random_bytes(16));
        }

        $suffix = $index === 0 ? '' : ':'.$index;

        if (strlen($base) + strlen($suffix) > self::MAX) {
            $base = substr(hash('sha256', $base), 0, self::MAX - strlen($suffix));
        }

        return $base.$suffix;
    }
}
