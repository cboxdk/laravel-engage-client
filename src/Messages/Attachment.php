<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use InvalidArgumentException;

/** A file on the message. `$content` is the raw bytes; the client base64-encodes on the wire. */
readonly class Attachment
{
    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
    ) {
        if ($filename === '' || $contentType === '') {
            throw new InvalidArgumentException('An attachment needs a filename and a content type.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'filename' => $this->filename,
            'content_type' => $this->contentType,
            'content' => base64_encode($this->content),
        ];
    }
}
