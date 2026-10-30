<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Events\Category;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Engage\Events\SubjectType;
use Cbox\Engage\Exceptions\EngageException;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\Attachment;
use Cbox\Engage\Messages\InlineContent;
use Cbox\Engage\Messages\OutgoingMessage;
use Cbox\Engage\Messages\Recipient;
use Closure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\LogicException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\IdentificationHeader;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\RawMessage;

/**
 * The `engage` mail driver: every recipient of a mail becomes one Engage message through
 * the workspace's send gate. When Engage is unavailable, keeps failing (the circuit
 * breaker), or gates a message out for a reason other than a dead address, the mail
 * goes through the mailer's configured fallback instead, and Engage is told afterwards
 * with a `message.sent` product event.
 */
class EngageTransport extends AbstractTransport
{
    public const string MESSAGE_ID_HEADER = 'X-Engage-Message-Id';

    private ?Steering $steering = null;

    /**
     * @param  (Closure(): TransportInterface)|null  $fallback  the fallback mailer's own transport, resolved on first use; null when none is configured
     */
    public function __construct(
        private readonly Messages $messages,
        private readonly CircuitBreaker $breaker,
        private readonly ProductEvents $events,
        private readonly MailOptions $options,
        private readonly ?Closure $fallback,
        private readonly ?string $fallbackName,
        LoggerInterface $logger,
    ) {
        parent::__construct(null, $logger);
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        if (! $message instanceof Email) {
            throw new LogicException('The engage mail driver sends Symfony Email messages only.');
        }

        // Read and strip on a copy before anything is built from the message, so the
        // steering headers exist in no sent copy, debug string or fallback mail.
        $message = clone $message;
        $this->steering = Steering::strip($message);

        try {
            return parent::send($message, $envelope);
        } finally {
            $this->steering = null;
        }
    }

    protected function doSend(SentMessage $message): void
    {
        $email = $message->getOriginalMessage();

        if (! $email instanceof Email) {
            throw new LogicException('The engage mail driver sends Symfony Email messages only.');
        }

        $steering = $this->steering ?? new Steering;
        $envelope = $message->getEnvelope();
        $recipients = array_values($envelope->getRecipients());
        $base = $steering->idempotencyKey ?? $this->messageId($email);
        $ids = [];

        if (! $this->breaker->allows()) {
            $this->fallback($message, $recipients, 'breaker_open', $steering, $base);

            return;
        }

        foreach ($recipients as $index => $address) {
            $remaining = array_slice($recipients, $index);
            $key = IdempotencyKey::for($base, $index);
            $outgoing = $this->outgoing($email, $steering, $address, $key, $index === 0 ? $steering->externalId : null);

            try {
                $receipt = $this->messages->send($outgoing);
            } catch (EngageUnavailable $e) {
                $this->breaker->failure();
                $this->fallback($message, $remaining, $e->reason, $steering, $base, $index, $e);

                return;
            } catch (EngageRejected $e) {
                if (! $this->options->fallbackOnRejection) {
                    throw $e;
                }

                $this->fallback($message, $remaining, 'rejected:'.$e->error, $steering, $base, $index, $e);

                return;
            }

            $this->breaker->success();

            if ($receipt->gatedOut()) {
                if (! $this->options->fallbackOnGate || $receipt->suppressed()) {
                    throw new MailRefused($receipt->reasons, $receipt->id);
                }

                $this->fallback($message, $remaining, 'gated_out:'.($receipt->reasons[0] ?? 'unknown'), $steering, $base, $index, new MailRefused($receipt->reasons, $receipt->id));

                return;
            }

            $ids[] = $receipt->id;
        }

        $this->stamp($message, $email, $ids);
    }

    public function __toString(): string
    {
        return 'engage';
    }

    private function outgoing(Email $email, Steering $steering, Address $to, string $key, ?string $externalId): OutgoingMessage
    {
        $brand = $steering->brand ?? $this->options->brand;

        if ($brand === null) {
            throw new LogicException('The engage mail driver needs a brand: set engage.mail.brand or send an X-Engage-Brand header.');
        }

        $replyTo = $email->getReplyTo()[0] ?? null;

        return new OutgoingMessage(
            idempotencyKey: $key,
            to: new Recipient($externalId, $to->getAddress(), $to->getName() === '' ? null : $to->getName()),
            brand: $brand,
            purpose: $steering->purpose ?? $this->options->purpose,
            content: new InlineContent($email->getSubject() ?? '(no subject)', $this->html($email), $this->text($email)),
            attachments: array_values(array_map($this->attachment(...), $email->getAttachments())),
            replyTo: $replyTo?->getAddress(),
            tags: $steering->tags,
            trackClicks: $steering->trackClicks ?? $this->options->trackClicks,
            trackOpens: $steering->trackOpens ?? $this->options->trackOpens,
            product: $steering->product ?? $this->options->product,
        );
    }

    private function html(Email $email): string
    {
        $html = $email->getHtmlBody();

        if (is_resource($html)) {
            $html = stream_get_contents($html);
        }

        if (is_string($html) && $html !== '') {
            return $html;
        }

        // Engage requires HTML; a text-only mail is sent as its text, escaped.
        $text = $this->text($email);

        return '<pre>'.htmlspecialchars($text ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</pre>';
    }

    private function text(Email $email): ?string
    {
        $text = $email->getTextBody();

        if (is_resource($text)) {
            $text = stream_get_contents($text);
        }

        return is_string($text) && $text !== '' ? $text : null;
    }

    private function attachment(DataPart $part): Attachment
    {
        return new Attachment(
            $part->getFilename() ?? 'attachment',
            $part->getMediaType().'/'.$part->getMediaSubtype(),
            $part->getBody(),
        );
    }

    /**
     * The mail's Message-ID, generated here when the app set none, so the Engage key and
     * the fallback mail carry the same id.
     */
    private function messageId(Email $email): string
    {
        $headers = $email->getHeaders();

        if (! $headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', $email->generateMessageId());
        }

        $header = $headers->get('Message-ID');

        return $header instanceof IdentificationHeader ? ($header->getId() ?? '') : '';
    }

    /**
     * Puts the Engage message id(s) where the app can read them after the send: the
     * transport-level id of the SentMessage and an `X-Engage-Message-Id` header per
     * recipient on the message.
     *
     * @param  list<string>  $ids
     */
    private function stamp(SentMessage $message, Email $email, array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $message->setMessageId($ids[0]);

        $email->getHeaders()->remove(self::MESSAGE_ID_HEADER);

        foreach ($ids as $id) {
            $email->getHeaders()->addTextHeader(self::MESSAGE_ID_HEADER, $id);
        }
    }

    /**
     * Sends the mail to the recipients Engage did not take through the fallback mailer's
     * own transport, then tells Engage (best-effort, queued) and the log that it happened.
     *
     * @param  list<Address>  $recipients
     */
    private function fallback(SentMessage $message, array $recipients, string $reason, Steering $steering, string $base, int $index = 0, ?EngageException $cause = null): void
    {
        if ($this->fallback === null) {
            $this->getLogger()->error('engage mail could not be sent and no fallback mailer is configured', ['reason' => $reason]);

            throw $cause ?? new EngageUnavailable('Cbox Engage is not taking mail ('.$reason.') and the engage mailer has no fallback.');
        }

        $email = $message->getOriginalMessage();
        $envelope = new Envelope($message->getEnvelope()->getSender(), $recipients);

        $sent = ($this->fallback)()->send($email, $envelope);

        if ($sent !== null) {
            $message->setMessageId($sent->getMessageId());
            $message->appendDebug($sent->getDebug());
        }

        $this->getLogger()->warning('engage mail sent through fallback', ['reason' => $reason, 'mailer' => $this->fallbackName]);

        // Only the first recipient is the person the app named; the others are unknown
        // to Engage by id, and an event without a subject is worthless to it.
        if ($steering->externalId !== null && $index === 0 && $email instanceof Email) {
            $this->events->record(new ProductEvent(
                'message.sent',
                'engage-client:message.sent:'.IdempotencyKey::for($base, 0),
                SubjectType::Contact,
                $steering->externalId,
                null,
                [
                    'via' => 'fallback',
                    'reason' => $reason,
                    'subject_hash' => hash('sha256', $email->getSubject() ?? ''),
                ],
                null,
                Category::Communication,
            ));
        }
    }
}
