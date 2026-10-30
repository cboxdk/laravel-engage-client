---
title: Mail driver
description: MAIL_MAILER=engage sends every Mailable through Engage's send gate, with steering headers, a fallback mailer and the Engage message id on the SentMessage.
weight: 23
---

# The `engage` mail driver

A Symfony transport registered as `engage`. Set it as the default mailer and every
Mailable, notification mail and `Mail::raw()` goes through Engage.

```php
'mailers' => [
    'engage' => ['transport' => 'engage', 'fallback' => 'resend'],
    'resend' => ['transport' => 'resend'],
],
```

## What a mail becomes

Engage messages have one recipient, so **every recipient of the mail (To, Cc, Bcc) gets
its own Engage message**. The first carries the idempotency key as given, the others the
key suffixed `:1`, `:2`, …. Only the first recipient carries the external id; the
others are resolved by address.

| Mail | Engage message |
|------|----------------|
| Subject, HTML body, text body | `content.subject`, `content.html`, `content.text`. A text-only mail is sent as its text inside `<pre>`, escaped. |
| `getAttachments()` | `attachments[]`, base64-encoded. |
| First Reply-To | `reply_to` |
| Recipient address and name | `to.email`, `to.name` |
| `X-Engage-External-Id` | `to.external_id` (first recipient only) |
| `X-Engage-Idempotency-Key`, else the `Message-ID` | `idempotency_key`, fitted to 64 characters |
| `X-Engage-Purpose`, else `engage.mail.purpose` | `purpose` |
| `X-Engage-Brand`, else `engage.mail.brand` | `brand` |
| `X-Engage-Track-Clicks`, else `engage.mail.track_clicks` | `track_clicks` |
| `X-Engage-Track-Opens`, else `engage.mail.track_opens` | `track_opens` |
| `X-Engage-Tags` (comma list) | `tags` |
| `X-Engage-Product`, else `engage.product` | `product` |

The steering headers are read and **stripped before anything is sent anywhere**: they
reach neither Engage nor the fallback mailer, and they do not appear in the
`SentMessage`'s debug string.

A note on the default key: the `Message-ID` is generated per send, so a queued Mailable
that is retried gets a new key and may be sent twice. Set
`X-Engage-Idempotency-Key` (the trait below) with the app's own natural key for anything
that must be sent once.

## Steering from a Mailable

```php
use Cbox\Engage\Mail\EngageOptions;

class PasswordReset extends Mailable
{
    use EngageOptions;
}

(new PasswordReset($token))->engage(
    externalId: $user->cbox_id,
    idempotencyKey: "reset:{$token->id}",
    purpose: 'transactional',
    trackClicks: false,
    trackOpens: null,
    tags: ['auth'],
);
```

`engage()` sets the headers through `withSymfonyMessage()`; the last call wins. Brand
and product have no parameter because they are per app, not per mail; set them in
config, or add the header yourself for the odd case:

```php
$mailable->withSymfonyMessage(fn (Email $m) => $m->getHeaders()->addTextHeader('X-Engage-Brand', 'br_other'));
```

## After the send

The Engage message id is on the returned `SentMessage`:

```php
$sent = Mail::to($user)->send($mailable);
$sent->getSymfonySentMessage()->getMessageId();                                   // 'msg_…' (first recipient)
$sent->getSymfonySentMessage()->getOriginalMessage()->getHeaders()->all('X-Engage-Message-Id');   // one per recipient
```

With that id, `Messages::status()` tells what happened to it.

## When Engage does not send

| What happened | The driver |
|---------------|------------|
| Receipt `gated_out`, reasons include `suppressed` | Throws `Cbox\Engage\Mail\MailRefused` with the reasons. The address is dead (a hard bounce); no mailer would get it through, and the app should know. |
| Receipt `gated_out`, any other reason | Sends through the fallback when `engage.mail.fallback_on_gate` is on (default). Otherwise throws `MailRefused`. |
| `EngageUnavailable` (timeout, 5xx, 429, unreachable) | Counts a breaker failure, sends through the fallback. |
| The breaker is open | Does not call Engage at all, sends through the fallback. |
| `EngageRejected` (4xx: a bad key, a validation error) | Throws. No breaker count: a misconfiguration must be loud. `engage.mail.fallback_on_rejection` turns this into a fallback send too. |
| No fallback configured | Rethrows: `EngageUnavailable`, `MailRefused` or `EngageRejected` as above. |

When Engage took some recipients and failed on a later one, only the remaining recipients
go through the fallback.

## The fallback

The fallback is the configured mailer's own Symfony transport
(`Mail::mailer($name)->getSymfonyTransport()`), so it needs nothing beyond its usual
configuration. After every fallback send the driver:

1. Records a `message.sent` product event (category `communication`, id
   `engage-client:message.sent:<idempotency key>`, subject the external id, props
   `{via: fallback, reason, subject_hash}`) through `ProductEvents`, queued and
   best-effort. Skipped when the mail named no external id: an event without a subject
   is worthless to Engage.
2. Logs `warning: engage mail sent through fallback` with `reason` and `mailer`. Never
   the address, never the key.

`reason` is `breaker_open`, `timeout`, `unreachable`, `http_5xx`, `http_429`,
`gated_out:<gate reason>` or `rejected:<error>`.

The fallback path sends **around Engage's consent gate**. That is right for a password
reset and wrong for a newsletter. See the [threat model](../security/threat-model.md).
