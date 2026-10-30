---
title: Quickstart
description: Events in five lines, the mail driver in ten.
weight: 2
---

# Quickstart

```bash
composer require cboxdk/laravel-engage-client
```

```dotenv
ENGAGE_INGEST_URL=https://app.cboxengage.com
ENGAGE_INGEST_KEY=ek_…
ENGAGE_PRODUCT=cbox-id          # this product's key, shown on the timeline
```

## Events in five lines

```php
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;

app(ProductEvents::class)->record(
    ProductEvent::byContact('project.created', "cbox-id:project.created:{$project->id}", $user->cbox_id, $organization->cbox_id, ['project_id' => $project->id])
);
```

That is a queued job after the current transaction commits. If the queue or Engage is
down, nothing reaches the caller. See [Events](core-concepts/events.md) for the envelope
and the rules on ids and props.

## The mail driver in ten

```php
// config/mail.php
'default' => env('MAIL_MAILER', 'engage'),
'mailers' => [
    'engage' => ['transport' => 'engage', 'fallback' => 'resend'],
    'resend' => ['transport' => 'resend'],
],
```

```dotenv
ENGAGE_MAIL_BRAND=br_01…        # the brand Engage sends as
```

```php
use Cbox\Engage\Mail\EngageOptions;

class PasswordReset extends Mailable
{
    use EngageOptions;
    // envelope(), content() as usual
}

Mail::to($user)->send((new PasswordReset($token))->engage(externalId: $user->cbox_id, idempotencyKey: "reset:{$token->id}"));
```

Every Mailable now goes through Engage's send gate. When Engage is unavailable five
times inside a minute the breaker opens for two minutes and mail goes through `resend`
directly; a probe every two minutes brings Engage back. A suppressed address throws
`Cbox\Engage\Mail\MailRefused`. See [Mail driver](core-concepts/mail-driver.md) and
[Circuit breaker](core-concepts/circuit-breaker.md).

## Messages without the mail driver

```php
use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Messages\{InlineContent, OutgoingMessage, Recipient};

$receipt = app(Messages::class)->send(new OutgoingMessage(
    idempotencyKey: "otp:{$user->id}:{$slot}",
    to: new Recipient(externalId: $user->cbox_id, email: $user->email),
    brand: 'br_01…',
    content: new InlineContent('Your code', "<p>{$code}</p>", $code),
));
```

See [Messages](core-concepts/messages.md).

## In tests

```php
use Cbox\Engage\Testing\InteractsWithEngage;

$engage = $this->fakeEngage();
// … act …
$engage->assertRecorded('project.created');
$engage->assertSent(fn ($m) => $m->idempotencyKey === "reset:{$token->id}");
```

See [Testing](getting-started/testing.md).
