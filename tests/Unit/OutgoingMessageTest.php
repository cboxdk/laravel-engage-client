<?php

declare(strict_types=1);

use Cbox\Engage\Messages\Attachment;
use Cbox\Engage\Messages\InlineContent;
use Cbox\Engage\Messages\OutgoingMessage;
use Cbox\Engage\Messages\Recipient;

it('serializes to the POST body with binary attachments base64-encoded and unset options left out', function (): void {
    $message = new OutgoingMessage(
        idempotencyKey: 'otp:usr_1:2026-10-30T10',
        to: new Recipient('usr_1', 'ada@example.com', 'Ada'),
        brand: 'br_1',
        content: new InlineContent('Your code', '<p>123456</p>', '123456'),
        attachments: [new Attachment('invoice.pdf', 'application/pdf', "%PDF-1.4\x00\xff")],
        replyTo: 'support@example.com',
        tags: ['otp'],
        trackClicks: false,
        product: 'cbox-id',
    );

    expect($message->toArray())->toBe([
        'idempotency_key' => 'otp:usr_1:2026-10-30T10',
        'to' => ['external_id' => 'usr_1', 'email' => 'ada@example.com', 'name' => 'Ada'],
        'brand' => 'br_1',
        'purpose' => 'transactional',
        'content' => ['subject' => 'Your code', 'html' => '<p>123456</p>', 'text' => '123456'],
        'attachments' => [['filename' => 'invoice.pdf', 'content_type' => 'application/pdf', 'content' => base64_encode("%PDF-1.4\x00\xff")]],
        'reply_to' => 'support@example.com',
        'tags' => ['otp'],
        'track_clicks' => false,
        'product' => 'cbox-id',
    ]);
});

it('sends a template with its variables as an object', function (): void {
    $message = new OutgoingMessage('k', new Recipient(email: 'ada@example.com'), 'br_1', 'product_updates', 'tp_1', ['code' => '1']);

    $body = $message->toArray();

    expect($body['template'])->toBe('tp_1')
        ->and($body['variables'])->toEqual((object) ['code' => '1'])
        ->and($body)->not->toHaveKeys(['content', 'attachments', 'reply_to', 'tags', 'track_clicks', 'track_opens', 'product']);
});

it('insists on exactly one of template and content', function (): void {
    expect(fn (): OutgoingMessage => new OutgoingMessage('k', new Recipient('usr_1'), 'br_1'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OutgoingMessage => new OutgoingMessage('k', new Recipient('usr_1'), 'br_1', template: 'tp_1', content: new InlineContent('s', '<p>h</p>')))->toThrow(InvalidArgumentException::class);
});

it('keeps the key, the tags and the attachments within what Engage takes', function (): void {
    $content = new InlineContent('s', '<p>h</p>');

    expect(fn (): OutgoingMessage => new OutgoingMessage(str_repeat('k', 65), new Recipient('usr_1'), 'br_1', content: $content))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OutgoingMessage => new OutgoingMessage('k', new Recipient('usr_1'), 'br_1', content: $content, tags: ['Not Valid']))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OutgoingMessage => new OutgoingMessage('k', new Recipient('usr_1'), 'br_1', content: $content, attachments: array_fill(0, 11, new Attachment('a', 'text/plain', 'x'))))->toThrow(InvalidArgumentException::class)
        ->and(fn (): Recipient => new Recipient)->toThrow(InvalidArgumentException::class);
});
