<?php

declare(strict_types=1);

namespace Cbox\Engage\Tests\Fixtures;

use Cbox\Engage\Mail\EngageOptions;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A Mailable that steers the engage driver through the trait: what every app's mail looks like. */
class WelcomeMail extends Mailable
{
    use EngageOptions;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome');
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Welcome</p>');
    }
}
