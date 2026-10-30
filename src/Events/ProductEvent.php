<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * One thing that happened, in the shape Cbox Engage takes (schema version 1).
 *
 * The event id must be deterministic per occurrence (`{app}:{name}:{natural key}`, e.g.
 * `cbox-id:member.invited:inv_01J…`), so a retry or a double call is one event
 * server-side. The subject is the person when one did it, the organization when none is
 * at hand. Props are ids and enums only: no names, no addresses, no free text.
 */
readonly class ProductEvent
{
    public const int SCHEMA_VERSION = 1;

    private const string NAME = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/';

    public CarbonImmutable $occurredAt;

    /**
     * @param  array<string, bool|int|float|string|null>  $props
     */
    public function __construct(
        public string $name,
        public string $eventId,
        public SubjectType $subject,
        public string $subjectExternalId,
        public ?string $companyExternalId = null,
        public array $props = [],
        ?CarbonImmutable $occurredAt = null,
        public Category $category = Category::Behavior,
    ) {
        if (preg_match(self::NAME, $name) !== 1 || strlen($name) > 64) {
            throw new InvalidArgumentException("'{$name}' is not a product event name (lowercase dotted words, at most 64 characters).");
        }

        if ($eventId === '' || strlen($eventId) > 128) {
            throw new InvalidArgumentException("The event id for '{$name}' must be 1 to 128 characters.");
        }

        if ($subjectExternalId === '') {
            throw new InvalidArgumentException("The subject of '{$name}' needs an external id; an event without one is worthless to Engage.");
        }

        $this->occurredAt = $occurredAt ?? CarbonImmutable::now();
    }

    /**
     * Done by a person: the subject is the Cbox ID user, in the organization when known.
     *
     * @param  array<string, bool|int|float|string|null>  $props
     */
    public static function byContact(string $name, string $eventId, string $userExternalId, ?string $companyExternalId = null, array $props = []): self
    {
        return new self($name, $eventId, SubjectType::Contact, $userExternalId, $companyExternalId, $props);
    }

    /**
     * About the organization, with no person at hand (a plan synced from billing, a
     * scheduled job).
     *
     * @param  array<string, bool|int|float|string|null>  $props
     */
    public static function byCompany(string $name, string $eventId, string $companyExternalId, array $props = []): self
    {
        return new self($name, $eventId, SubjectType::Company, $companyExternalId, $companyExternalId, $props);
    }

    /**
     * The envelope Engage's `POST /v1/events` takes.
     *
     * @return array<string, mixed>
     */
    public function envelope(?string $product, string $environment): array
    {
        $context = ['environment' => $environment];

        if ($product !== null) {
            $context = ['product' => $product, ...$context];
        }

        if ($this->companyExternalId !== null) {
            $context = ['company_external_id' => $this->companyExternalId, ...$context];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'event_id' => $this->eventId,
            'name' => $this->name,
            'category' => $this->category->value,
            'occurred_at' => $this->occurredAt->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'subject' => ['type' => $this->subject->value, 'external_id' => $this->subjectExternalId],
            'context' => $context,
            'props' => (object) $this->props,
        ];
    }
}
