<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

use Symfony\Component\Mime\Email;

/**
 * What a Mailable told the driver through `X-Engage-*` headers: who the recipient is to
 * the app, the idempotency key, the purpose, the brand, tracking, tags, the product.
 * Read from the message and stripped from it, so the headers reach neither Engage nor
 * the fallback mailer.
 */
readonly class Steering
{
    public const string EXTERNAL_ID = 'X-Engage-External-Id';

    public const string IDEMPOTENCY_KEY = 'X-Engage-Idempotency-Key';

    public const string PURPOSE = 'X-Engage-Purpose';

    public const string BRAND = 'X-Engage-Brand';

    public const string TRACK_CLICKS = 'X-Engage-Track-Clicks';

    public const string TRACK_OPENS = 'X-Engage-Track-Opens';

    public const string TAGS = 'X-Engage-Tags';

    public const string PRODUCT = 'X-Engage-Product';

    /** Every header the driver understands, in the order it strips them. */
    public const array HEADERS = [
        self::EXTERNAL_ID,
        self::IDEMPOTENCY_KEY,
        self::PURPOSE,
        self::BRAND,
        self::TRACK_CLICKS,
        self::TRACK_OPENS,
        self::TAGS,
        self::PRODUCT,
    ];

    /**
     * @param  list<string>  $tags
     */
    public function __construct(
        public ?string $externalId = null,
        public ?string $idempotencyKey = null,
        public ?string $purpose = null,
        public ?string $brand = null,
        public ?bool $trackClicks = null,
        public ?bool $trackOpens = null,
        public array $tags = [],
        public ?string $product = null,
    ) {}

    /** Reads the steering headers off the message and removes them from it. */
    public static function strip(Email $email): self
    {
        $headers = $email->getHeaders();
        $steering = new self(
            self::text($headers->getHeaderBody(self::EXTERNAL_ID)),
            self::text($headers->getHeaderBody(self::IDEMPOTENCY_KEY)),
            self::text($headers->getHeaderBody(self::PURPOSE)),
            self::text($headers->getHeaderBody(self::BRAND)),
            self::flag($headers->getHeaderBody(self::TRACK_CLICKS)),
            self::flag($headers->getHeaderBody(self::TRACK_OPENS)),
            self::tags($headers->getHeaderBody(self::TAGS)),
            self::text($headers->getHeaderBody(self::PRODUCT)),
        );

        foreach (self::HEADERS as $name) {
            $headers->remove($name);
        }

        return $steering;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private static function flag(mixed $value): ?bool
    {
        $text = self::text($value);

        return $text === null ? null : filter_var($text, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    }

    /**
     * @return list<string>
     */
    private static function tags(mixed $value): array
    {
        $text = self::text($value);

        if ($text === null) {
            return [];
        }

        $tags = array_map('trim', explode(',', $text));

        return array_values(array_unique(array_filter($tags, static fn (string $tag): bool => $tag !== '')));
    }
}
