<?php

declare(strict_types=1);

use Cbox\Engage\Mail\Steering;
use Cbox\Engage\Tests\Fixtures\WelcomeMail;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

function headersOfLastArrayMail(): Email
{
    $transport = Mail::mailer('array')->getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);
    $sent = $transport->messages()->last();
    $email = $sent?->getOriginalMessage();
    expect($email)->toBeInstanceOf(Email::class);

    return $email;
}

it('sets every steering header the driver reads', function (): void {
    Mail::mailer('array')->to('ada@example.com')->send((new WelcomeMail)->engage(
        externalId: 'usr_1',
        idempotencyKey: 'welcome:usr_1',
        purpose: 'product_updates',
        trackClicks: true,
        trackOpens: false,
        tags: ['welcome', 'onboarding'],
    ));

    $headers = headersOfLastArrayMail()->getHeaders();

    expect($headers->getHeaderBody(Steering::EXTERNAL_ID))->toBe('usr_1')
        ->and($headers->getHeaderBody(Steering::IDEMPOTENCY_KEY))->toBe('welcome:usr_1')
        ->and($headers->getHeaderBody(Steering::PURPOSE))->toBe('product_updates')
        ->and($headers->getHeaderBody(Steering::TRACK_CLICKS))->toBe('true')
        ->and($headers->getHeaderBody(Steering::TRACK_OPENS))->toBe('false')
        ->and($headers->getHeaderBody(Steering::TAGS))->toBe('welcome,onboarding');
});

it('sets only what was given, and the last call wins', function (): void {
    Mail::mailer('array')->to('ada@example.com')->send((new WelcomeMail)->engage(externalId: 'usr_1')->engage(externalId: 'usr_2', trackOpens: true));

    $headers = headersOfLastArrayMail()->getHeaders();

    expect($headers->getHeaderBody(Steering::EXTERNAL_ID))->toBe('usr_2')
        ->and($headers->getHeaderBody(Steering::TRACK_OPENS))->toBe('true')
        ->and($headers->has(Steering::IDEMPOTENCY_KEY))->toBeFalse()
        ->and($headers->has(Steering::PURPOSE))->toBeFalse()
        ->and($headers->has(Steering::TRACK_CLICKS))->toBeFalse()
        ->and($headers->has(Steering::TAGS))->toBeFalse()
        ->and($headers->has(Steering::BRAND))->toBeFalse();
});

it('reads the headers back as typed steering and strips them', function (): void {
    $email = (new Email)->subject('s')->html('<p>h</p>');
    $email->getHeaders()->addTextHeader(Steering::EXTERNAL_ID, ' usr_1 ');
    $email->getHeaders()->addTextHeader(Steering::TRACK_CLICKS, 'yes');
    $email->getHeaders()->addTextHeader(Steering::TRACK_OPENS, 'nonsense');
    $email->getHeaders()->addTextHeader(Steering::TAGS, 'a, b,,a');
    $email->getHeaders()->addTextHeader(Steering::BRAND, 'br_x');

    $steering = Steering::strip($email);

    expect($steering->externalId)->toBe('usr_1')
        ->and($steering->trackClicks)->toBeTrue()
        ->and($steering->trackOpens)->toBeNull()
        ->and($steering->tags)->toBe(['a', 'b'])
        ->and($steering->brand)->toBe('br_x')
        ->and($steering->idempotencyKey)->toBeNull();

    foreach (Steering::HEADERS as $name) {
        expect($email->getHeaders()->has($name))->toBeFalse();
    }
});
