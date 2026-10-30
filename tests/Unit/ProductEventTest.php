<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Cbox\Engage\Events\Category;
use Cbox\Engage\Events\ProductEvent;
use Cbox\Engage\Events\SubjectType;

it('builds the schema version 1 envelope', function (): void {
    $event = new ProductEvent(
        'site.published',
        'sites:site.published:pub_1',
        SubjectType::Contact,
        'usr_1',
        'org_1',
        ['site_id' => 'site_1', 'first' => true],
        CarbonImmutable::parse('2026-10-29 12:00:00.123', 'Europe/Copenhagen'),
    );

    expect($event->envelope('cbox-sites', 'production'))->toEqual([
        'schema_version' => 1,
        'event_id' => 'sites:site.published:pub_1',
        'name' => 'site.published',
        'category' => 'behavior',
        'occurred_at' => '2026-10-29T11:00:00.123Z',
        'subject' => ['type' => 'contact', 'external_id' => 'usr_1'],
        'context' => ['company_external_id' => 'org_1', 'product' => 'cbox-sites', 'environment' => 'production'],
        'props' => (object) ['site_id' => 'site_1', 'first' => true],
    ]);
});

it('leaves out the company and the product when there are none, and sends empty props as an object', function (): void {
    $event = ProductEvent::byContact('user.signed_in', 'app:user.signed_in:usr_1:2026-10-29T12', 'usr_1');

    $envelope = $event->envelope(null, 'local');

    expect($envelope['context'])->toBe(['environment' => 'local'])
        ->and(json_encode($envelope['props']))->toBe('{}');
});

it('makes an organization both the subject and the company', function (): void {
    $event = ProductEvent::byCompany('plan.changed', 'app:plan.changed:org_1:pro', 'org_1', ['plan' => 'pro']);

    expect($event->subject)->toBe(SubjectType::Company)
        ->and($event->subjectExternalId)->toBe('org_1')
        ->and($event->companyExternalId)->toBe('org_1')
        ->and($event->category)->toBe(Category::Behavior);
});

it('refuses a name that is not lowercase dotted words', function (string $name): void {
    expect(fn (): ProductEvent => ProductEvent::byContact($name, 'app:x:1', 'usr_1'))->toThrow(InvalidArgumentException::class);
})->with(['Site.Published', 'published', 'site..published', 'site.published!', str_repeat('a', 60).'.b.cdef']);

it('refuses an empty subject or an event id out of bounds', function (): void {
    expect(fn (): ProductEvent => ProductEvent::byContact('a.b', 'app:a.b:1', ''))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ProductEvent => ProductEvent::byContact('a.b', '', 'usr_1'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): ProductEvent => ProductEvent::byContact('a.b', str_repeat('x', 129), 'usr_1'))->toThrow(InvalidArgumentException::class);
});
