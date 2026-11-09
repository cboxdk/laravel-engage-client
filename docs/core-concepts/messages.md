---
title: Messages
description: Messages::send(), Messages::status(), Messages::sendMany() and Messages::batch() over the transactional messages API, with typed receipts and typed exceptions.
weight: 22
---

# Messages

`Cbox\Engage\Contracts\Messages` is the transactional messages API: one message to one
recipient, or one message to many in a batch, through the workspace's send gate.

```php
interface Messages
{
    public function send(OutgoingMessage $message): MessageReceipt;   // POST /v1/messages
    public function status(string $id): MessageStatus;                // GET  /v1/messages/{id}
    public function sendMany(OutgoingBatch $batch): BatchReceipt;     // POST /v1/message-batches
    public function batch(string $id, ?string $cursor = null, int $limit = 100): BatchStatus;   // GET /v1/message-batches/{id}
}
```

## Sending

```php
use Cbox\Engage\Messages\{Attachment, InlineContent, OutgoingMessage, Recipient};

$receipt = $messages->send(new OutgoingMessage(
    idempotencyKey: "invoice:{$invoice->id}",        // 1–64 chars; the same key twice is ONE message
    to: new Recipient(externalId: $user->cbox_id, email: $user->email, name: $user->name),
    brand: 'br_01…',
    purpose: 'transactional',                        // product_updates / marketing need consent and a contact
    template: 'tp_01…', variables: ['total' => '€ 120'],   // EITHER a template …
    // content: new InlineContent('Your invoice', '<p>…</p>', '…'),   // … OR inline content
    attachments: [new Attachment('invoice.pdf', 'application/pdf', $pdfBytes)],   // ≤ 10, ≤ 5 MB decoded in total
    replyTo: 'billing@example.com',
    tags: ['invoice'],                               // ≤ 10, [a-z0-9_-]
    trackClicks: false,
    product: 'cbox-billing',
));
```

`OutgoingMessage` validates at construction: a key out of bounds, both or neither of
template and content, too many attachments or tags, a tag with the wrong characters.
`Attachment::$content` is the raw bytes; the client base64-encodes on the wire.

### The receipt

```php
$receipt->id;          // msg_…
$receipt->status;      // ReceiptStatus::Queued | ReceiptStatus::GatedOut
$receipt->reasons;     // the gate's, when gated out: suppressed, no_consent, frequency_cap, …
$receipt->contactId;   // ct_… or null when sent without a contact
$receipt->created;     // false when the idempotency key was seen before
$receipt->queued(); $receipt->gatedOut(); $receipt->suppressed();
```

A `gated_out` receipt is a successful call: Engage took the request and decided not to
send. The reasons are `workspace_suspended`, `contact_erased`, `no_contact_point`,
`suppressed`, `no_consent`, `template_not_transactional`, `purpose_mismatch`,
`frequency_cap`, `domain_not_verified`, `over_entitlement`, `quiet_hours`,
`brand_paused`. For a transactional send only a hard-bounce suppression refuses.

### Recipient resolution

Engage resolves `to.external_id` in the key's identity namespace first, then the contact
owning `to.email`; with both given and neither matching it creates the contact; with
only an email and no contact it sends without a contact (transactional only). An email
belonging to another contact than the external id names is never merged.

## Status

```php
$status = $messages->status($receipt->id);

$status->status;           // MessageState::Queued | Sent | GatedOut | Failed
$status->deliveryStatus;   // null | DeliveryStatus::Delivered | Bounced | Complained | Opened | Clicked
$status->provider;         // 'resend' or null
$status->gate;             // GateDecision{allowed, reasons, stage}
$status->events;           // list<DeliveryEvent{status, at, detail}>, oldest first
$status->latestEvent();    // the newest entry, or null
```

`to` is the masked address as Engage shows it. `DeliveryEvent::$status` is a string
(`sent`, `failed`, `delivered`, `bounced`, `complained`, `opened`, `clicked`,
`unsubscribed`) because the log is Engage's and may grow; `detail` is the provider's
own record for the entry.

## Many recipients: `sendMany()`

An incident update to every subscriber, a notice to every admin of a company: the same
message to many people, each with their own details. One request carries up to 1000
recipients; Engage validates the batch as a whole, accepts it, and then sends each
recipient as its own message through the gate, metered and capped exactly like `send()`.
There is no loop of `send()` calls to write, and no rate of your own to keep.

```php
use Cbox\Engage\Messages\{BatchRecipient, InlineContent, OutgoingBatch, Recipient};

$receipt = $messages->sendMany(new OutgoingBatch(
    idempotencyKey: "incident:{$incident->id}:update:{$update->id}",   // names the batch
    brand: 'br_01…',
    recipients: [
        new BatchRecipient(
            idempotencyKey: "incident:{$incident->id}:update:{$update->id}:{$subscriber->id}",   // names this recipient's message
            to: new Recipient(email: $subscriber->email),
            variables: ['unsubscribe_url' => $subscriber->unsubscribeUrl()],   // ≤ 50, override the shared ones
            // companyExternalId: $organization->cbox_id,
        ),
        // … up to 1000
    ],
    content: new InlineContent('{{ page }}: {{ title }}', '<p>{{ update }}</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>'),
    // template: 'tp_01…',          // or a workspace template, for recipients that are contacts
    variables: ['page' => 'Acme', 'title' => 'API errors', 'update' => 'We are investigating.'],   // shared, ≤ 100
    tags: ['incident'],
));

$receipt->id;          // mb_…
$receipt->status;      // BatchState::Accepted (then Processing, Completed)
$receipt->recipients;  // 1
$receipt->created;     // false when the batch key was seen before: nothing is sent again
```

- **Two kinds of key.** The batch key names the request. Each recipient key names that
  recipient's message in the ingest key's source: if it was already used, by an earlier
  batch or by `send()`, Engage answers with that message instead of sending a second one.
  A retry with a new batch key is therefore safe as long as the recipient keys are stable.
- **Inline content is a template.** In a batch, `{{ name }}` in the subject, HTML and text
  is filled from the recipient's variables (shared, overridden by its own), escaped, in
  Engage's sandboxed mustache. A workspace `template` works too, but only for recipients
  that are contacts; a bare address in a template batch is `rejected`.
- **Limits.** 1000 recipients a batch (`OutgoingBatch` refuses more: send several), 500 KB
  of inline body, no attachments, and 10 000 recipients a minute per source. Over that
  Engage answers 429 and `sendMany()` throws `EngageUnavailable` with `$retryAfter`.
- **Purposes.** `transactional` by default. `product_updates` and `marketing` need a
  contact with consent per recipient, as everywhere.

More than 1000 recipients: one batch per chunk, named by who is in it, so the same chunk
again is the same batch and a chunk that changed is a new one (its recipients already
sent are still caught by their own keys).

```php
foreach (array_chunk($recipients, OutgoingBatch::MAX_RECIPIENTS) as $chunk) {
    $who = substr(hash('sha256', implode(',', array_map(fn (BatchRecipient $r): string => $r->idempotencyKey, $chunk))), 0, 32);
    $messages->sendMany(new OutgoingBatch("incident:{$id}:update:{$n}:{$who}", $brand, $chunk, content: $content));
}
```

Call it from a queued job with backoff, like `send()`: a 429 or a timeout is a retry,
and the keys make the retry harmless.

### Where each recipient stands: `batch()`

```php
$status = $messages->batch($receipt->id);                       // the first 100
$next = $messages->batch($receipt->id, cursor: $status->nextCursor, limit: 500);

$status->completed();          // every recipient is through the gate (or rejected)
$status->count('sent');        // also pending, rejected, queued, gated_out, failed, expired
foreach ($status->items as $item) {
    $item->idempotencyKey;     // yours
    $item->status;             // 'pending', 'rejected', then the message's: 'queued', 'gated_out', 'sent', 'failed'; 'expired' after retention
    $item->messageId;          // msg_…: status($item->messageId) for its delivery log
    $item->reasons;            // the gate's, when gated out
    $item->deliveryStatus;     // DeliveryStatus or null
    $item->errorField; $item->error;   // why a rejected recipient was refused
}
```

## Exceptions

All extend `Cbox\Engage\Exceptions\EngageException`.

| Exception | When | Retry? |
|-----------|------|--------|
| `EngageUnavailable` | Connection failed, timed out, 5xx, 429. `$reason` is `timeout`, `unreachable`, `http_5xx` or `http_429`; `$retryAfter` from the header. | Yes, later. |
| `EngageRejected` | Any other 4xx. `$status`, `$error` (`invalid_key`, `insufficient_scope`, `workspace_suspended`, `validation`, `not_found`), `$errors` per field. | No. Fix the cause. |
| `EngageNotConfigured` | `ENGAGE_INGEST_URL` or `ENGAGE_INGEST_KEY` is empty. | No. |

The HTTP client uses `config('engage.timeout')` (2 s) for both the connection and the
whole call.
