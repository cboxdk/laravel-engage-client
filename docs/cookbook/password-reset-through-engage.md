---
title: Password reset through Engage
description: A password reset Mailable with a deterministic idempotency key, the user's external id, and MailRefused handled.
weight: 31
---

# Password reset through Engage

A password reset is the mail that must always leave: through Engage when it is up, through
the fallback when it is not, and never twice for one token.

## The Mailable

```php
namespace App\Mail;

use Cbox\Engage\Mail\EngageOptions;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\{Content, Envelope};

class PasswordReset extends Mailable
{
    use EngageOptions;

    public function __construct(public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your password');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.password-reset');
    }
}
```

## Sending it

```php
use Cbox\Engage\Mail\MailRefused;

$mail = (new PasswordReset($url))->engage(
    externalId: $user->cbox_id,                  // the contact on Engage's side
    idempotencyKey: "password-reset:{$token->id}",   // one token, one mail, however often the job retries
    trackClicks: false,                          // the link must not depend on Engage
    tags: ['auth'],
);

try {
    Mail::to($user)->send($mail);
} catch (MailRefused $e) {
    // Engage knows this address bounces hard. Tell the user on the page instead of
    // pretending a mail is on its way.
    return back()->withErrors(['email' => 'We cannot deliver to this address.']);
}
```

Why `trackClicks: false` is the default for transactional mail: a tracked link is
rewritten to go through Engage first. A reset link that only works while Engage is up
would defeat the fallback.

## In a queued job

A `ShouldQueue` Mailable is rendered and sent by the worker. The key above is what makes
a retried job safe: Engage sees the same key and answers with the existing message
(`created: false`), nothing is sent twice. Without the key the driver falls back to the
`Message-ID`, which is new on every attempt.

## In tests

```php
$engage = $this->fakeEngage();

$this->post('/forgot-password', ['email' => $user->email]);

$engage->assertSent(fn (OutgoingMessage $m): bool => $m->to->externalId === $user->cbox_id
    && str_starts_with($m->idempotencyKey, 'password-reset:')
    && $m->trackClicks === false);
```
