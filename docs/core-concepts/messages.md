---
title: Messages
description: Messages::send() and Messages::status() over the transactional messages API, with typed receipts and typed exceptions.
weight: 22
---

# Messages

`Cbox\Engage\Contracts\Messages` is the transactional messages API: one message to one
recipient, through the workspace's send gate.

```php
interface Messages
{
    public function send(OutgoingMessage $message): MessageReceipt;   // POST /v1/messages
    public function status(string $id): MessageStatus;                // GET  /v1/messages/{id}
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

## Exceptions

All extend `Cbox\Engage\Exceptions\EngageException`.

| Exception | When | Retry? |
|-----------|------|--------|
| `EngageUnavailable` | Connection failed, timed out, 5xx, 429. `$reason` is `timeout`, `unreachable`, `http_5xx` or `http_429`; `$retryAfter` from the header. | Yes, later. |
| `EngageRejected` | Any other 4xx. `$status`, `$error` (`invalid_key`, `insufficient_scope`, `workspace_suspended`, `validation`, `not_found`), `$errors` per field. | No. Fix the cause. |
| `EngageNotConfigured` | `ENGAGE_INGEST_URL` or `ENGAGE_INGEST_KEY` is empty. | No. |

The HTTP client uses `config('engage.timeout')` (2 s) for both the connection and the
whole call.
