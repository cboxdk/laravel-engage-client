# Cbox Engage client

The Cbox Engage client for Laravel. Product telemetry and messaging against the workspace's ingest key:

- **Product events in.** `ProductEvents::record()` queues one event to Engage's
  `POST /v1/events`; nothing that goes wrong reaches the caller.
- **Transactional messages out.** `Messages::send()` and `Messages::status()` over
  `POST /v1/messages` and `GET /v1/messages/{id}`, with typed receipts and exceptions;
  `Messages::sendMany()` and `Messages::batch()` for one message to up to 1000
  recipients over `POST /v1/message-batches`.
- **The `engage` mail driver.** `MAIL_MAILER=engage` sends every Mailable through
  Engage's send gate, with a circuit breaker and a fallback mailer for when Engage is
  down, so a password reset always leaves.

Verified account-free contacts and independent service/feature interests use the separate
[lead client](docs/cookbook/verified-leads.md), with explicit per-workspace credentials,
typed consent evidence and identity proof. The host owns durable retry.

Documentation: [docs/index.md](docs/index.md). Start with the
[quickstart](docs/quickstart.md).

## Install

```bash
composer require cboxdk/laravel-engage-client
```

Two environment keys, the same ones every Cbox app already uses:

```dotenv
ENGAGE_INGEST_URL=https://app.cboxengage.com
ENGAGE_INGEST_KEY=ek_…
```

With either empty, product events go nowhere, `Messages` throws `EngageNotConfigured`
on use, and the `engage` mailer refuses to be built. Publish the config with
`php artisan vendor:publish --tag=engage-config` for the rest (`config/engage.php`).

## Product events

```php
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\ProductEvent;

public function __construct(private ProductEvents $events) {}

$this->events->record(ProductEvent::byContact(
    name: 'project.created',
    eventId: "cbox-id:project.created:{$project->id}",   // deterministic: a retry is one event
    userExternalId: $user->cbox_id,
    companyExternalId: $organization->cbox_id,
    props: ['project_id' => $project->id],
));
```

## Messages

```php
use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Messages\{BatchRecipient, InlineContent, OutgoingBatch, OutgoingMessage, Recipient};

$receipt = app(Messages::class)->send(new OutgoingMessage(
    idempotencyKey: "otp:{$user->id}:{$slot}",
    to: new Recipient(externalId: $user->cbox_id, email: $user->email),
    brand: 'br_01…',
    content: new InlineContent('Your code', "<p>{$code}</p>", $code),
));

$receipt->queued();          // or ->gatedOut() with ->reasons
app(Messages::class)->status($receipt->id)->latestEvent()?->status;   // 'delivered', 'bounced', …

// One message to many: each recipient its own key and variables, each its own message.
$batch = app(Messages::class)->sendMany(new OutgoingBatch(
    idempotencyKey: "incident:{$incident->id}:update:{$update->id}",
    brand: 'br_01…',
    content: new InlineContent('{{ page }}: {{ title }}', '<p>{{ update }}</p><a href="{{ unsubscribe_url }}">Unsubscribe</a>'),
    variables: ['page' => $page->name, 'title' => $incident->title, 'update' => $update->body],
    recipients: $subscribers->map(fn ($s) => new BatchRecipient(
        "incident:{$incident->id}:update:{$update->id}:{$s->id}",
        new Recipient(email: $s->email),
        ['unsubscribe_url' => $s->unsubscribeUrl()],
    ))->all(),
));
app(Messages::class)->batch($batch->id)->count('gated_out');
```

## The mail driver

```php
// config/mail.php
'default' => env('MAIL_MAILER', 'engage'),
'mailers' => [
    'engage' => ['transport' => 'engage', 'fallback' => 'resend'],
    'resend' => ['transport' => 'resend'],
],

// A Mailable
use Cbox\Engage\Mail\EngageOptions;

class PasswordReset extends Mailable { use EngageOptions; /* … */ }

Mail::to($user)->send((new PasswordReset($token))->engage(externalId: $user->cbox_id, idempotencyKey: "reset:{$token->id}"));
```

When Engage is unavailable five times in a minute, the breaker opens for two minutes and
mail goes straight through `resend`; one probe every two minutes brings Engage back. A
suppressed address throws `Cbox\Engage\Mail\MailRefused` instead of being sent elsewhere.

## Testing

```php
use Cbox\Engage\Testing\InteractsWithEngage;   // in your TestCase

$engage = $this->fakeEngage();
$engage->assertRecorded('project.created', fn ($e) => $e->props['project_id'] === $project->id);
$engage->assertSent(fn ($m) => $m->idempotencyKey === "reset:{$token->id}");
```

## Development

```bash
composer qa    # pint, phpstan (max), pest, licenses, audit, sbom
```

MIT. See [LICENSE.md](LICENSE.md) and [SECURITY.md](SECURITY.md).
