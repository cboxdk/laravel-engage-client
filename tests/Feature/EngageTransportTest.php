<?php

declare(strict_types=1);

use Cbox\Engage\Events\Category;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Engage\Events\SubjectType;
use Cbox\Engage\Exceptions\EngageNotConfigured;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Mail\BreakerState;
use Cbox\Engage\Mail\CircuitBreaker;
use Cbox\Engage\Mail\EngageTransport;
use Cbox\Engage\Mail\MailRefused;
use Cbox\Engage\Messages\OutgoingMessage;
use Cbox\Engage\Tests\Fixtures\WelcomeMail;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

/**
 * @return array<string, mixed>
 */
function accepted(string $id = 'msg_1'): array
{
    return ['id' => $id, 'status' => 'queued', 'reasons' => [], 'contact_id' => 'ct_1', 'created' => true];
}

function fallbackTransport(): ArrayTransport
{
    $transport = Mail::mailer('array')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    return $transport;
}

/**
 * Engage times out on every call. A throwing stub is not recorded by Http::fake, so the
 * calls are counted here: `$calls` is how often the driver tried to reach Engage.
 */
function timingOut(int &$calls): void
{
    Http::fake(function (Request $request) use (&$calls): never {
        $calls++;

        throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds');
    });
}

it('is registered as the engage transport', function (): void {
    expect(Mail::mailer('engage')->getSymfonyTransport())->toBeInstanceOf(EngageTransport::class)
        ->and((string) Mail::mailer('engage')->getSymfonyTransport())->toBe('engage');
});

it('sends one Engage message per recipient with the steering headers stripped and attachments base64-encoded', function (): void {
    Http::fake(['engage.test/v1/messages' => Http::sequence()->push(accepted('msg_1'), 202)->push(accepted('msg_2'), 202)]);

    $mail = (new WelcomeMail)
        ->engage(externalId: 'usr_1', idempotencyKey: 'welcome:usr_1', purpose: 'transactional', trackClicks: true, trackOpens: false, tags: ['welcome', 'onboarding'])
        ->attachData("%PDF-1.4\x00", 'guide.pdf', ['mime' => 'application/pdf'])
        ->replyTo('support@example.com');

    $sent = Mail::to([['email' => 'ada@example.com', 'name' => 'Ada'], 'bob@example.com'])->send($mail);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://engage.test/v1/messages'
        && $request->hasHeader('Authorization', 'Bearer ek_test')
        && $request['idempotency_key'] === 'welcome:usr_1'
        && $request['to'] === ['external_id' => 'usr_1', 'email' => 'ada@example.com', 'name' => 'Ada']
        && $request['brand'] === 'br_test'
        && $request['purpose'] === 'transactional'
        && $request['content']['subject'] === 'Welcome'
        && str_contains($request['content']['html'], '<p>Welcome</p>')
        && $request['attachments'] === [['filename' => 'guide.pdf', 'content_type' => 'application/pdf', 'content' => base64_encode("%PDF-1.4\x00")]]
        && $request['reply_to'] === 'support@example.com'
        && $request['tags'] === ['welcome', 'onboarding']
        && $request['track_clicks'] === true
        && $request['track_opens'] === false
        && $request['product'] === 'cbox-test');
    Http::assertSent(fn (Request $request): bool => $request['idempotency_key'] === 'welcome:usr_1:1'
        && $request['to'] === ['email' => 'bob@example.com']);

    $symfony = $sent?->getSymfonySentMessage();
    $headers = $symfony?->getOriginalMessage()->getHeaders();

    expect($symfony?->getMessageId())->toBe('msg_1')
        ->and($headers?->has('X-Engage-External-Id'))->toBeFalse()
        ->and($headers?->has('X-Engage-Idempotency-Key'))->toBeFalse()
        ->and($headers?->has('X-Engage-Tags'))->toBeFalse()
        ->and($symfony?->toString())->not->toContain('X-Engage-External-Id')
        ->and(array_map(fn ($h): string => $h->getBodyAsString(), iterator_to_array($headers?->all('X-Engage-Message-Id') ?? [], false)))->toBe(['msg_1', 'msg_2'])
        ->and(fallbackTransport()->messages())->toHaveCount(0);
});

it('uses the Message-ID as the idempotency key and the config defaults when the mail says nothing', function (): void {
    Http::fake(['engage.test/v1/messages' => Http::response(accepted(), 202)]);

    $sent = Mail::to('ada@example.com')->send(new WelcomeMail);
    $messageId = $sent?->getSymfonySentMessage()->getOriginalMessage()->getHeaders()->get('Message-ID')?->getBodyAsString();

    expect($messageId)->toStartWith('<');
    Http::assertSent(fn (Request $request): bool => $request['idempotency_key'] === trim((string) $messageId, '<>')
        && $request['to'] === ['email' => 'ada@example.com']
        && $request['purpose'] === 'transactional'
        && $request['track_clicks'] === false
        && ! isset($request['track_opens'])
        && ! isset($request['tags']));
});

it('reads brand, purpose and product from the headers when set', function (): void {
    Http::fake(['engage.test/v1/messages' => Http::response(accepted(), 202)]);

    $mail = (new WelcomeMail)->withSymfonyMessage(function (Email $email): void {
        $email->getHeaders()->addTextHeader('X-Engage-Brand', 'br_other');
        $email->getHeaders()->addTextHeader('X-Engage-Purpose', 'product_updates');
        $email->getHeaders()->addTextHeader('X-Engage-Product', 'cbox-sites');
    });

    Mail::to('ada@example.com')->send($mail);

    Http::assertSent(fn (Request $request): bool => $request['brand'] === 'br_other' && $request['purpose'] === 'product_updates' && $request['product'] === 'cbox-sites');
});

it('sends through the fallback when the gate refuses for a reason other than suppression, and tells Engage and the log', function (): void {
    $engage = $this->fakeEngage()->willRefuse(['no_consent']);
    Log::spy();

    Mail::to('ada@example.com')->send((new WelcomeMail)->engage(externalId: 'usr_1', idempotencyKey: 'welcome:usr_1'));

    $engage->assertSent(fn (OutgoingMessage $m): bool => $m->idempotencyKey === 'welcome:usr_1');
    expect(fallbackTransport()->messages())->toHaveCount(1)
        ->and(fallbackTransport()->messages()[0]->getOriginalMessage()->getHeaders()->has('X-Engage-External-Id'))->toBeFalse()
        ->and(fallbackTransport()->messages()[0]->toString())->not->toContain('X-Engage');

    $engage->assertRecorded('message.sent', fn (ProductEvent $e): bool => $e->eventId === 'engage-client:message.sent:welcome:usr_1'
        && $e->category === Category::Communication
        && $e->subject === SubjectType::Contact
        && $e->subjectExternalId === 'usr_1'
        && $e->props === ['via' => 'fallback', 'reason' => 'gated_out:no_consent', 'subject_hash' => hash('sha256', 'Welcome')]);
    Log::shouldHaveReceived('warning')->once()->with('engage mail sent through fallback', ['reason' => 'gated_out:no_consent', 'mailer' => 'array']);
});

it('records no event for a fallback mail without an external id', function (): void {
    $engage = $this->fakeEngage()->willRefuse(['frequency_cap']);

    Mail::to('ada@example.com')->send(new WelcomeMail);

    expect(fallbackTransport()->messages())->toHaveCount(1);
    $engage->assertNothingRecorded();
});

it('throws MailRefused for a suppressed address instead of sending it elsewhere', function (): void {
    $this->fakeEngage()->willRefuse(['suppressed']);

    expect(fn () => Mail::to('dead@example.com')->send(new WelcomeMail))->toThrow(MailRefused::class, 'suppressed')
        ->and(fallbackTransport()->messages())->toHaveCount(0);
});

it('throws MailRefused on any gate refusal when fallback_on_gate is off', function (): void {
    config(['engage.mail.fallback_on_gate' => false]);
    $this->fakeEngage()->willRefuse(['no_consent']);
    $refused = null;

    try {
        Mail::to('ada@example.com')->send(new WelcomeMail);
    } catch (MailRefused $e) {
        $refused = $e;
    }

    expect($refused?->reasons)->toBe(['no_consent'])
        ->and(fallbackTransport()->messages())->toHaveCount(0);
});

it('sends through the fallback on a timeout, counts the failure, and sends the rest of the recipients only', function (): void {
    Bus::fake();
    $calls = 0;
    Http::fake(function (Request $request) use (&$calls): PromiseInterface {
        if (++$calls === 1) {
            return Http::response(accepted('msg_1'), 202);
        }

        throw new ConnectionException('cURL error 28: Operation timed out');
    });
    Log::spy();

    Mail::to(['ada@example.com', 'bob@example.com', 'cy@example.com'])->send((new WelcomeMail)->engage(externalId: 'usr_1', idempotencyKey: 'k'));

    expect($calls)->toBe(2);
    $fallback = fallbackTransport()->messages();

    expect($fallback)->toHaveCount(1)
        ->and(array_map(fn ($a): string => $a->getAddress(), $fallback[0]->getEnvelope()->getRecipients()))->toBe(['bob@example.com', 'cy@example.com'])
        ->and(app(CircuitBreaker::class)->state())->toBe(BreakerState::Closed);
    Log::shouldHaveReceived('warning')->once()->with('engage mail sent through fallback', ['reason' => 'timeout', 'mailer' => 'array']);
});

it('opens the breaker after five timeouts so the sixth send never calls Engage, then recovers through one probe', function (): void {
    Bus::fake();
    Log::spy();
    $calls = 0;
    $down = true;
    Http::fake(function (Request $request) use (&$calls, &$down): PromiseInterface {
        $calls++;

        if ($down) {
            throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds');
        }

        return Http::response(accepted('msg_back'), 202);
    });

    foreach (range(1, 5) as $n) {
        Mail::to("user{$n}@example.com")->send((new WelcomeMail)->engage(externalId: "usr_{$n}", idempotencyKey: "welcome:{$n}"));
    }

    expect($calls)->toBe(5)
        ->and(fallbackTransport()->messages())->toHaveCount(5)
        ->and(app(CircuitBreaker::class)->state())->toBe(BreakerState::Open);
    Log::shouldHaveReceived('warning')->with('engage mail sent through fallback', ['reason' => 'timeout', 'mailer' => 'array'])->times(5);

    Mail::to('user6@example.com')->send((new WelcomeMail)->engage(externalId: 'usr_6'));

    expect($calls)->toBe(5)
        ->and(fallbackTransport()->messages())->toHaveCount(6);
    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('engage mail sent through fallback', ['reason' => 'breaker_open', 'mailer' => 'array'])->once();

    // Still open a minute later: no call.
    $this->travel(60)->seconds();
    Mail::to('user7@example.com')->send(new WelcomeMail);
    expect($calls)->toBe(5);

    // After open_for, one probe goes through; Engage is back, the breaker closes.
    $this->travel(61)->seconds();
    $down = false;
    $sent = Mail::to('user8@example.com')->send(new WelcomeMail);

    Http::assertSentCount(1);
    expect($calls)->toBe(6)
        ->and($sent?->getSymfonySentMessage()->getMessageId())->toBe('msg_back')
        ->and(fallbackTransport()->messages())->toHaveCount(7)
        ->and(app(CircuitBreaker::class)->state())->toBe(BreakerState::Closed);

    Mail::to('user9@example.com')->send(new WelcomeMail);
    Http::assertSentCount(2);
    expect($calls)->toBe(7);
});

it('opens the breaker again when the probe fails', function (): void {
    Bus::fake();
    $calls = 0;
    timingOut($calls);

    foreach (range(1, 5) as $n) {
        Mail::to("user{$n}@example.com")->send(new WelcomeMail);
    }

    $this->travel(121)->seconds();
    expect(app(CircuitBreaker::class)->state())->toBe(BreakerState::HalfOpen);

    Mail::to('probe@example.com')->send(new WelcomeMail);

    expect($calls)->toBe(6)
        ->and(app(CircuitBreaker::class)->state())->toBe(BreakerState::Open)
        ->and(fallbackTransport()->messages())->toHaveCount(6);

    Mail::to('after@example.com')->send(new WelcomeMail);
    expect($calls)->toBe(6);
});

it('counts a 5xx and a 429 as failures too', function (): void {
    Bus::fake();
    Http::fake(['engage.test/v1/messages' => Http::sequence()->push('', 503)->push('', 429)->push('', 500)->push('', 502)->push('', 504)]);
    Log::spy();

    foreach (range(1, 5) as $n) {
        Mail::to("user{$n}@example.com")->send(new WelcomeMail);
    }

    expect(app(CircuitBreaker::class)->state())->toBe(BreakerState::Open)
        ->and(fallbackTransport()->messages())->toHaveCount(5);
    Log::shouldHaveReceived('warning')->with('engage mail sent through fallback', ['reason' => 'http_5xx', 'mailer' => 'array'])->times(4);
    Log::shouldHaveReceived('warning')->with('engage mail sent through fallback', ['reason' => 'http_429', 'mailer' => 'array'])->once();
});

it('throws EngageRejected on a 4xx without counting a failure or using the fallback', function (): void {
    Http::fake(['engage.test/v1/messages' => Http::response(['error' => 'invalid_key'], 401)]);

    foreach (range(1, 6) as $n) {
        expect(fn () => Mail::to("user{$n}@example.com")->send(new WelcomeMail))->toThrow(EngageRejected::class, 'invalid_key');
    }

    Http::assertSentCount(6);
    expect(app(CircuitBreaker::class)->state())->toBe(BreakerState::Closed)
        ->and(fallbackTransport()->messages())->toHaveCount(0);
});

it('sends through the fallback on a 4xx only when fallback_on_rejection is on', function (): void {
    config(['engage.mail.fallback_on_rejection' => true]);
    Http::fake(['engage.test/v1/messages' => Http::response(['error' => 'validation', 'errors' => ['brand' => ['unknown']]], 422)]);
    Log::spy();

    Mail::to('ada@example.com')->send(new WelcomeMail);

    expect(fallbackTransport()->messages())->toHaveCount(1)
        ->and(app(CircuitBreaker::class)->state())->toBe(BreakerState::Closed);
    Log::shouldHaveReceived('warning')->once()->with('engage mail sent through fallback', ['reason' => 'rejected:validation', 'mailer' => 'array']);
});

it('rethrows when there is no fallback mailer', function (): void {
    config(['mail.mailers.engage' => ['transport' => 'engage']]);
    $engage = $this->fakeEngage()->willFail();

    expect(fn () => Mail::to('ada@example.com')->send(new WelcomeMail))->toThrow(EngageUnavailable::class);

    app(CircuitBreaker::class)->reset();
    $engage->willRefuse(['no_consent']);

    expect(fn () => Mail::to('ada@example.com')->send(new WelcomeMail))->toThrow(MailRefused::class, 'no_consent');
});

it('refuses to be built when Engage is not configured', function (): void {
    config(['engage.key' => '']);

    expect(fn () => Mail::to('ada@example.com')->send(new WelcomeMail))->toThrow(EngageNotConfigured::class);
});

it('refuses to be its own fallback', function (): void {
    config(['mail.mailers.engage' => ['transport' => 'engage', 'fallback' => 'engage']]);

    expect(fn () => Mail::to('ada@example.com')->send(new WelcomeMail))->toThrow(InvalidArgumentException::class);
});

it('sends a text-only mail as escaped text in a pre block', function (): void {
    $engage = $this->fakeEngage();

    Mail::raw("Hello <world>\n", fn ($m) => $m->to('ada@example.com')->subject('Plain'));

    $engage->assertSent(fn (OutgoingMessage $m): bool => $m->content?->html === "<pre>Hello &lt;world&gt;\n</pre>" && $m->content->text === "Hello <world>\n" && $m->content->subject === 'Plain');
});
