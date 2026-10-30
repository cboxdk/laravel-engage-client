<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use InvalidArgumentException;

/** The message's own body, when it is not rendered from an Engage template. */
readonly class InlineContent
{
    public function __construct(
        public string $subject,
        public string $html,
        public ?string $text = null,
    ) {
        if ($subject === '' || $html === '') {
            throw new InvalidArgumentException('Inline content needs a subject and an HTML body.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        $content = ['subject' => $this->subject, 'html' => $this->html];

        if ($this->text !== null && $this->text !== '') {
            $content['text'] = $this->text;
        }

        return $content;
    }
}
