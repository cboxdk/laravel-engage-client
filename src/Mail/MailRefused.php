<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

use Cbox\Engage\Exceptions\EngageException;

/**
 * Engage's send gate refused the mail and the driver did not send it elsewhere: the
 * address is suppressed (a hard bounce, so no mailer would get it through), or
 * `engage.mail.fallback_on_gate` is off. `$reasons` are the gate's.
 */
class MailRefused extends EngageException
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(public readonly array $reasons, public readonly ?string $messageId = null)
    {
        parent::__construct('Cbox Engage refused the mail: '.($reasons === [] ? 'no reason given' : implode(', ', $reasons)).'.');
    }
}
