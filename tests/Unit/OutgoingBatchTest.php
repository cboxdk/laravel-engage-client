<?php

declare(strict_types=1);

use Cbox\Engage\Messages\BatchRecipient;
use Cbox\Engage\Messages\InlineContent;
use Cbox\Engage\Messages\OutgoingBatch;
use Cbox\Engage\Messages\Recipient;

it('serializes to the POST body with each recipient\'s own key and variables, unset options left out', function (): void {
    $batch = new OutgoingBatch(
        idempotencyKey: 'incident:inc_42:update:3',
        brand: 'cbox-status',
        recipients: [
            new BatchRecipient('incident:inc_42:update:3:sub_1', new Recipient(email: 'ada@example.com'), ['unsubscribe_url' => 'https://status.test/u/1']),
            new BatchRecipient('incident:inc_42:update:3:sub_2', new Recipient('usr_2', 'grace@example.com', 'Grace'), companyExternalId: 'org_1'),
        ],
        content: new InlineContent('{{ page }}: API errors', '<p>{{ update }}</p>', '{{ update }}'),
        variables: ['page' => 'Cbox', 'update' => 'We are investigating.'],
        tags: ['incident'],
        product: 'cbox-status',
    );

    expect($batch->toArray())->toBe([
        'idempotency_key' => 'incident:inc_42:update:3',
        'brand' => 'cbox-status',
        'purpose' => 'transactional',
        'content' => ['subject' => '{{ page }}: API errors', 'html' => '<p>{{ update }}</p>', 'text' => '{{ update }}'],
        'variables' => ['page' => 'Cbox', 'update' => 'We are investigating.'],
        'tags' => ['incident'],
        'product' => 'cbox-status',
        'recipients' => [
            ['idempotency_key' => 'incident:inc_42:update:3:sub_1', 'to' => ['email' => 'ada@example.com'], 'variables' => ['unsubscribe_url' => 'https://status.test/u/1']],
            ['idempotency_key' => 'incident:inc_42:update:3:sub_2', 'to' => ['external_id' => 'usr_2', 'email' => 'grace@example.com', 'name' => 'Grace'], 'company_external_id' => 'org_1'],
        ],
    ]);
});

it('sends a template by id instead of content', function (): void {
    expect(incidentBatch(template: 'tp_1')->toArray())->toHaveKey('template', 'tp_1')->not->toHaveKey('content');
});

it('refuses a batch Engage would refuse', function (callable $build, string $message): void {
    expect($build)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no recipients' => [fn () => incidentBatch([]), '1 to 1000 recipients'],
    'too many recipients' => [fn () => incidentBatch(array_map(fn (int $i): BatchRecipient => new BatchRecipient("k{$i}", new Recipient(email: "a{$i}@example.com")), range(1, 1001))), '1 to 1000 recipients'],
    'a shared key' => [fn () => incidentBatch([new BatchRecipient('k', new Recipient('usr_1')), new BatchRecipient('k', new Recipient('usr_2'))]), "share the idempotency key 'k'"],
    'template and content' => [fn () => new OutgoingBatch('k', 'b', [new BatchRecipient('k1', new Recipient('usr_1'))], template: 'tp_1', content: new InlineContent('s', '<p>h</p>')), 'either a template or inline content'],
    'neither' => [fn () => new OutgoingBatch('k', 'b', [new BatchRecipient('k1', new Recipient('usr_1'))]), 'either a template or inline content'],
    'an empty key' => [fn () => new OutgoingBatch('', 'b', [new BatchRecipient('k1', new Recipient('usr_1'))], template: 'tp_1'), '1 to 64 characters'],
    'no brand' => [fn () => new OutgoingBatch('k', '', [new BatchRecipient('k1', new Recipient('usr_1'))], template: 'tp_1'), 'needs a brand'],
    'a bad tag' => [fn () => new OutgoingBatch('k', 'b', [new BatchRecipient('k1', new Recipient('usr_1'))], template: 'tp_1', tags: ['Bad Tag']), 'is not a tag'],
    'a long recipient key' => [fn () => new BatchRecipient(str_repeat('a', 65), new Recipient('usr_1')), '1 to 64 characters'],
    'too many recipient variables' => [fn () => new BatchRecipient('k', new Recipient('usr_1'), array_fill_keys(array_map(fn (int $i): string => "v{$i}", range(1, 51)), 'x')), 'at most 50 variables'],
]);
