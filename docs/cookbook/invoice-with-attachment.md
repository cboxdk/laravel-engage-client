---
title: Invoice with an attachment
description: A PDF invoice through Messages::send() with an Attachment, and the same mail through the engage driver with attachData().
weight: 32
---

# Invoice with an attachment

Engage takes up to 10 attachments and 5 MB decoded per message. `Attachment` holds the
raw bytes; the client base64-encodes them on the wire.

## Through the messages API

```php
use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Messages\{Attachment, InlineContent, OutgoingMessage, Recipient};

$receipt = app(Messages::class)->send(new OutgoingMessage(
    idempotencyKey: "invoice:{$invoice->number}",
    to: new Recipient(externalId: $customer->cbox_id, email: $customer->billing_email, name: $customer->name),
    brand: config('engage.mail.brand'),
    template: 'tp_invoice',
    variables: ['number' => $invoice->number, 'total' => $invoice->total->formatted()],
    attachments: [new Attachment("invoice-{$invoice->number}.pdf", 'application/pdf', $pdf->bytes())],
    replyTo: 'billing@example.com',
    tags: ['invoice'],
    product: 'cbox-billing',
));

if ($receipt->gatedOut()) {
    // e.g. domain_not_verified or over_entitlement: the invoice is not on its way.
    report(new RuntimeException('Invoice not sent: '.implode(', ', $receipt->reasons)));
}
```

Later, from a scheduled check or a webhook handler of your own:

```php
$status = app(Messages::class)->status($receipt->id);

if ($status->deliveryStatus === DeliveryStatus::Bounced) {
    // The billing address is wrong; flag the customer.
}
```

## Through the mail driver

The same, as a Mailable. The driver turns `attachData()` into an `Attachment`.

```php
class InvoiceMail extends Mailable
{
    use EngageOptions;

    public function __construct(private readonly Invoice $invoice) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Invoice {$this->invoice->number}", replyTo: ['billing@example.com']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.invoice');
    }

    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->invoice->pdf(), "invoice-{$this->invoice->number}.pdf")->withMime('application/pdf'),
        ];
    }
}

Mail::to($customer->billing_email)->send((new InvoiceMail($invoice))->engage(externalId: $customer->cbox_id, idempotencyKey: "invoice:{$invoice->number}", tags: ['invoice']));
```

The difference from the API call: the mail driver always sends the rendered Mailable as
inline content, never an Engage template, and it falls back to the other mailer when
Engage is down. For an invoice that is what you want; for a marketing send it is not
(see the [threat model](../security/threat-model.md)).
