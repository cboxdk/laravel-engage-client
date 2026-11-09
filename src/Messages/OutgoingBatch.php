<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use InvalidArgumentException;

/**
 * One message to many recipients, as `POST /v1/message-batches` takes it. Either an
 * Engage template or inline content, never both. Inline content is a template over each
 * recipient's variables (`{{ name }}`, escaped): the batch's shared `variables`,
 * overridden by the recipient's own. A workspace template needs recipients that are
 * contacts. No attachments.
 *
 * The idempotency key names the batch (`incident:inc_42:update:3`): the same key again is
 * the same batch. Each recipient's own key names its message.
 */
readonly class OutgoingBatch
{
    public const int MAX_RECIPIENTS = 1000;

    /**
     * @param  list<BatchRecipient>  $recipients
     * @param  array<string, bool|int|float|string|null>  $variables  shared by every recipient
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $idempotencyKey,
        public string $brand,
        public array $recipients,
        public string $purpose = 'transactional',
        public ?string $template = null,
        public array $variables = [],
        public ?InlineContent $content = null,
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
            throw new InvalidArgumentException('A batch needs a brand.');
        }

        if (($template === null) === ($content === null)) {
            throw new InvalidArgumentException('A batch has either a template or inline content.');
        }

        if ($recipients === [] || count($recipients) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException('A batch has 1 to 1000 recipients; split a longer list into several batches.');
        }

        $keys = [];

        foreach ($recipients as $recipient) {
            if (isset($keys[$recipient->idempotencyKey])) {
                throw new InvalidArgumentException("Two recipients share the idempotency key '{$recipient->idempotencyKey}'.");
            }

            $keys[$recipient->idempotencyKey] = true;
        }

        if (count($variables) > 100) {
            throw new InvalidArgumentException('A batch carries at most 100 shared variables.');
        }

        if (count($tags) > 10) {
            throw new InvalidArgumentException('A batch carries at most 10 tags.');
        }

        foreach ($tags as $tag) {
            if (preg_match('/^[a-z0-9_-]+$/', $tag) !== 1) {
                throw new InvalidArgumentException("'{$tag}' is not a tag ([a-z0-9_-] only).");
            }
        }
    }

    /**
     * The request body. Optional fields that are unset are left out, so Engage applies
     * its own defaults.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $body = [
            'idempotency_key' => $this->idempotencyKey,
            'brand' => $this->brand,
            'purpose' => $this->purpose,
        ];

        if ($this->template !== null) {
            $body['template'] = $this->template;
        }

        if ($this->content !== null) {
            $body['content'] = $this->content->toArray();
        }

        if ($this->variables !== []) {
            $body['variables'] = $this->variables;
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

        $body['recipients'] = array_map(static fn (BatchRecipient $recipient): array => $recipient->toArray(), $this->recipients);

        return $body;
    }
}
