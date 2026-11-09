---
title: An update to every subscriber
description: An incident update mailed to every subscriber through Messages::sendMany(), from a queued job, with keys that make every retry harmless.
weight: 34
---

# An update to every subscriber

A status page posts an incident update; every subscriber gets it, each with their own
unsubscribe link. One `sendMany()` per 1000 subscribers, from a queued job.

```php
use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\{BatchRecipient, InlineContent, OutgoingBatch, Recipient};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MailIncidentUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $updateId) {}

    public function handle(Messages $messages): void
    {
        $update = IncidentUpdate::with('incident.page')->findOrFail($this->updateId);
        $content = new InlineContent(
            '{{ page }}: {{ title }}',
            '<p>{{ body }}</p><p><a href="{{ unsubscribe_url }}">Unsubscribe</a></p>',
            "{{ body }}\n\nUnsubscribe: {{ unsubscribe_url }}",
        );

        $update->incident->page->subscribers()->confirmed()->orderBy('id')
            ->chunkById(OutgoingBatch::MAX_RECIPIENTS, function ($subscribers) use ($messages, $update, $content): void {
                $recipients = $subscribers->map(fn (Subscriber $s): BatchRecipient => new BatchRecipient(
                    idempotencyKey: "update:{$update->id}:{$s->id}",
                    to: new Recipient(email: $s->email),
                    variables: ['unsubscribe_url' => $s->unsubscribeUrl()],
                ))->values()->all();

                $receipt = $messages->sendMany(new OutgoingBatch(
                    // Named by who is in it: the same chunk again is the same batch.
                    idempotencyKey: "update:{$update->id}:".substr(hash('sha256', implode(',', array_map(fn (BatchRecipient $r): string => $r->idempotencyKey, $recipients))), 0, 32),
                    brand: config('engage.mail.brand'),
                    content: $content,
                    variables: ['page' => $update->incident->page->name, 'title' => $update->incident->title, 'body' => $update->body],
                    tags: ['incident'],
                    recipients: $recipients,
                ));

                $update->batches()->firstOrCreate(['engage_batch_id' => $receipt->id]);
            });
    }
}
```

Why it is safe to retry, whole or in part:

- The batch key is the update and a hash of who is in the chunk: a retry of the job sends
  the same batches and Engage answers `created: false` without sending again. Do not
  name a chunk by its position or its first subscriber: if the list moved, a new
  subscriber would land in a batch Engage already knows, and be skipped.
- The recipient key is the update and the subscriber. A chunk that changed between
  attempts is a new batch, and its recipients already sent meet their old keys: Engage
  returns those messages instead of sending again.
- A 429 (over 10 000 recipients a minute) or a timeout throws `EngageUnavailable`; the
  job's backoff is the retry.

Afterwards, `Messages::batch($id)` says who got it: `count('sent')`, `count('gated_out')`
(a hard-bounced address, with `reasons` on the item), and `rejected` items with the
field at fault. A support question about one subscriber is `status($item->messageId)`.

## In the tests

```php
$engage = $this->fakeEngage();

MailIncidentUpdate::dispatchSync($update->id);

$engage->assertBatchSent(fn (OutgoingBatch $b): bool => str_starts_with($b->idempotencyKey, "update:{$update->id}:"));
$engage->assertBatchRecipient(fn (BatchRecipient $r): bool => $r->to->email === $ada->email
    && $r->variables['unsubscribe_url'] === $ada->unsubscribeUrl());
```
