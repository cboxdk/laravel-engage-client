<?php

declare(strict_types=1);

namespace Cbox\Engage\Http;

use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Illuminate\Http\Client\Response;

/** What an unsuccessful Engage answer means, shared by the events and messages calls. */
class Responses
{
    /** The typed exception for a non-2xx response: retryable or not. */
    public static function failure(Response $response): EngageUnavailable|EngageRejected
    {
        $status = $response->status();

        if ($status === 429 || $status >= 500) {
            return EngageUnavailable::fromStatus($status, self::retryAfter($response));
        }

        return new EngageRejected($status, self::error($response, $status), self::errors($response));
    }

    private static function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max((int) $header, 0) : null;
    }

    private static function error(Response $response, int $status): string
    {
        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return $status === 404 ? 'not_found' : 'unknown';
    }

    /**
     * @return array<string, list<string>>
     */
    private static function errors(Response $response): array
    {
        $errors = $response->json('errors');
        $out = [];

        if (! is_array($errors)) {
            return $out;
        }

        foreach ($errors as $field => $messages) {
            if (! is_string($field) || ! is_array($messages)) {
                continue;
            }

            $out[$field] = array_values(array_filter($messages, 'is_string'));
        }

        return $out;
    }
}
