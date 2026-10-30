<?php

declare(strict_types=1);

namespace Cbox\Engage\Exceptions;

/**
 * Engage refused the call and would again (any 4xx but 429): a bad or under-scoped key,
 * a suspended workspace, an invalid body, an unknown id. `$error` is the body's `error`
 * (`invalid_key`, `insufficient_scope`, `workspace_suspended`, `validation`, `not_found`),
 * `$errors` the per-field messages of a validation error.
 */
class EngageRejected extends EngageException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        public readonly array $errors = [],
    ) {
        $detail = $errors === [] ? '' : ': '.implode('; ', array_map(
            static fn (string $field, array $messages): string => $field.' '.implode(', ', $messages),
            array_keys($errors),
            $errors,
        ));

        parent::__construct("Cbox Engage refused the request ({$status} {$error}){$detail}.");
    }
}
