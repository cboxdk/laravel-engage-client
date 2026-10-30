<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use InvalidArgumentException;

/**
 * One message to one recipient, as `POST /v1/messages` takes it. Either an Engage
 * template (with variables) or inline content, never both. The idempotency key is the
 * app's own natural key for the send (`otp:usr_1:2026-10-30T10`): the same key twice is
 * one message.
 */
readonly class OutgoingMessage
{
    /**
     * @param  array<string, bool|int|float|string|null>  $variables
     * @param  list<Attachment>  $attachments
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $idempotencyKey,
        public Recipient $to,
        public string $brand,
        public string $purpose = 'transactional',
        public ?string $template = null,
        public array $variables = [],
        public ?InlineContent $content = null,
        public array $attachments = [],
        public ?string $replyTo = null,
        public array $tags = [],
        public ?bool $trackClicks = null,
        public ?bool $trackOpens = null,
        public ?string $product = null,
    ) {
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 64) {
            throw new InvalidArgumentException('The idempotency key must be 1 to 64 characters.');
        }

        if ($brand === '') {
            throw new InvalidArgumentException('A message needs a brand.');
        }

        if (($template === null) === ($content === null)) {
            throw new InvalidArgumentException('A message has either a template or inline content.');
        }

        if (count($attachments) > 10) {
            throw new InvalidArgumentException('A message carries at most 10 attachments.');
        }

        if (count($tags) > 10) {
            throw new InvalidArgumentException('A message carries at most 10 tags.');
        }

        foreach ($tags as $tag) {
            if (preg_match('/^[a-z0-9_-]+$/', $tag) !== 1) {
                throw new InvalidArgumentException("'{$tag}' is not a tag ([a-z0-9_-] only).");
            }
        }
    }

    /**
     * The request body. Optional fields that are unset are left out, so Engage applies
     * its own defaults (the brand's tracking settings, no reply-to).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'idempotency_key' => $this->idempotencyKey,
            'to' => $this->to->toArray(),
            'brand' => $this->brand,
            'purpose' => $this->purpose,
        ];

        if ($this->template !== null) {
            $body['template'] = $this->template;
            $body['variables'] = (object) $this->variables;
        }

        if ($this->content !== null) {
            $body['content'] = $this->content->toArray();
        }

        if ($this->attachments !== []) {
            $body['attachments'] = array_map(static fn (Attachment $attachment): array => $attachment->toArray(), $this->attachments);
        }

        if ($this->replyTo !== null && $this->replyTo !== '') {
            $body['reply_to'] = $this->replyTo;
        }

        if ($this->tags !== []) {
            $body['tags'] = $this->tags;
        }

        if ($this->trackClicks !== null) {
            $body['track_clicks'] = $this->trackClicks;
        }

        if ($this->trackOpens !== null) {
            $body['track_opens'] = $this->trackOpens;
        }

        if ($this->product !== null && $this->product !== '') {
            $body['product'] = $this->product;
        }

        return $body;
    }
}
