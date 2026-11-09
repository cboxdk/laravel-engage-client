<?php

declare(strict_types=1);

use Cbox\Engage\Messages\BatchRecipient;
use Cbox\Engage\Messages\InlineContent;
use Cbox\Engage\Messages\OutgoingBatch;
use Cbox\Engage\Messages\Recipient;
use Cbox\Engage\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * An incident update to one subscriber: inline content unless a template id is given.
 *
 * @param  list<BatchRecipient>|null  $recipients
 */
function incidentBatch(?array $recipients = null, ?string $template = null, ?InlineContent $content = null): OutgoingBatch
{
    return new OutgoingBatch(
        idempotencyKey: 'incident:inc_42:update:3',
        brand: 'cbox-status',
        recipients: $recipients ?? [new BatchRecipient('incident:inc_42:update:3:sub_1', new Recipient(email: 'ada@example.com'))],
        template: $template,
        content: $template === null ? ($content ?? new InlineContent('{{ page }}: down', '<p>{{ update }}</p>')) : null,
    );
}
