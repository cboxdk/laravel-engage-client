---
title: Testing
description: Fake Engage in your tests with FakeEngage and the InteractsWithEngage trait, as the package's own suite does.
weight: 12
---

# Testing

`Cbox\Engage\Testing\FakeEngage` is Engage in memory: it implements both
`ProductEvents` and `Messages`, keeps what it is given, and answers sends with a queued
receipt unless told otherwise. `InteractsWithEngage` binds it. The package's own suite
runs on them; if the fake is awkward for your test, that is a bug in the fake.

## Setup

```php
namespace Tests;

use Cbox\Engage\Testing\InteractsWithEngage;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithEngage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeEngage();   // nothing is queued, nothing leaves the process
    }
}
```

`fakeEngage()` returns the fake and binds it as both contracts. The `engage` mailer, if
it is built after that, sends to the fake too, so a feature test can assert on the
message a Mailable became.

## Assertions

| Method | What it checks |
|--------|----------------|
| `assertRecorded(string $name, ?callable $where = null)` | A product event with that name (and matching `$where`) was recorded. |
| `assertNotRecorded(string $name)` | No event with that name was recorded. |
| `assertNothingRecorded()` | No event at all. |
| `assertSent(?callable $where = null)` | A message (matching `$where`) was sent. |
| `assertNothingSent()` | No message at all. |
| `recorded(?string $name = null): list<ProductEvent>` | The events, for your own assertions. |
| `sent(?callable $where = null): list<OutgoingMessage>` | The messages. |

```php
$engage = $this->fakeEngage();

$this->post('/projects', ['name' => 'Web Shop']);

$engage->assertRecorded('project.created', fn (ProductEvent $e): bool => $e->subjectExternalId === $user->cbox_id
    && $e->eventId === "cbox-id:project.created:{$project->id}"
    && $e->props === ['project_id' => $project->id]);
```

## Making Engage misbehave

| Method | Effect |
|--------|--------|
| `willRefuse(array $reasons)` | Every send is gated out with these reasons. `['suppressed']` makes the mail driver throw `MailRefused`; anything else sends it through the fallback. |
| `willFail(string $reason = 'timeout')` | Every call throws `EngageUnavailable`. The mail driver counts a breaker failure and uses the fallback. |
| `willAccept()` | Back to queued receipts. |

```php
$this->fakeEngage()->willRefuse(['suppressed']);

$this->expectException(MailRefused::class);
Mail::to('dead@example.com')->send(new PasswordReset($token));
```

The fake's `status()` answers for ids it handed out and throws `EngageRejected`
(`not_found`) for any other, like Engage does.

## Testing the driver's fallback

Point the `engage` mailer's `fallback` at Laravel's `array` mailer and read its transport:

```php
config(['mail.mailers.engage' => ['transport' => 'engage', 'fallback' => 'array']]);
$this->fakeEngage()->willFail();

Mail::to($user)->send(new PasswordReset($token));

$sent = Mail::mailer('array')->getSymfonyTransport()->messages();
expect($sent)->toHaveCount(1);
```

The breaker lives in the cache; `app(CircuitBreaker::class)->reset()` closes it between
tests that share a store.
