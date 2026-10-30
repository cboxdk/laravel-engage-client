<?php

declare(strict_types=1);

use Cbox\Engage\Mail\IdempotencyKey;

it('uses the key as is for the first recipient and suffixes the others', function (): void {
    expect(IdempotencyKey::for('<abc@example.com>', 0))->toBe('abc@example.com')
        ->and(IdempotencyKey::for('reset:1', 1))->toBe('reset:1:1')
        ->and(IdempotencyKey::for('reset:1', 2))->toBe('reset:1:2');
});

it('hashes a key that does not fit in 64 characters, deterministically', function (): void {
    $long = str_repeat('x', 80);

    expect(IdempotencyKey::for($long, 0))->toHaveLength(64)
        ->and(IdempotencyKey::for($long, 0))->toBe(IdempotencyKey::for($long, 0))
        ->and(IdempotencyKey::for($long, 1))->toHaveLength(64)
        ->and(IdempotencyKey::for($long, 1))->toEndWith(':1');
});
