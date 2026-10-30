# Cbox Engage client

The Cbox Engage client for Laravel. Three things, all against the workspace's ingest key:

- **Product events in.** `ProductEvents::record()` queues one event to Engage's
  `POST /v1/events`; nothing that goes wrong reaches the caller.
- **Transactional messages out.** `Messages::send()` and `Messages::status()` over
  `POST /v1/messages` and `GET /v1/messages/{id}`, with typed receipts and exceptions.
- **The `engage` mail driver.** `MAIL_MAILER=engage` sends every Mailable through
  Engage's send gate, with a circuit breaker and a fallback mailer for when Engage is
  down, so a password reset always leaves.

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
use Cbox\Engage\Messages\{InlineContent, OutgoingMessage, Recipient};

$receipt = app(Messages::class)->send(new OutgoingMessage(
    idempotencyKey: "otp:{$user->id}:{$slot}",
    to: new Recipient(externalId: $user->cbox_id, email: $user->email),
    brand: 'br_01…',
    content: new InlineContent('Your code', "<p>{$code}</p>", $code),
));

$receipt->queued();          // or ->gatedOut() with ->reasons
app(Messages::class)->status($receipt->id)->latestEvent()?->status;   // 'delivered', 'bounced', …
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
