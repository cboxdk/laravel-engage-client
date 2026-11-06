<?php

declare(strict_types=1);

use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Leads\ConsentChoice;
use Cbox\Engage\Leads\HttpLeads;
use Cbox\Engage\Leads\IdentityProof;
use Cbox\Engage\Leads\Interest;
use Cbox\Engage\Leads\LeadChange;
use Cbox\Engage\Leads\LeadDestination;
use Cbox\Engage\Testing\FakeLeads;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function verifiedLead(): LeadChange
{
    return new LeadChange('org_customer', 'production', 'e58c9182-a2a4-418f-808e-aa3278794f9e', 7, '2026-11-06T10:00:00Z',
        email: 'ada@example.test', verifiedAt: '2026-11-06T09:00:00Z',
        interest: new Interest('sites-beta', 'br_customer', 'sites', 'Sites editor beta', kind: 'beta', feature: 'editor', program: 'beta-2026'),
        consent: new ConsentChoice('c246a0bd-dc1e-4841-84eb-5b30a18f1e54', 'granted', '2026-11-06T08:59:00Z', '2026-11-06T09:00:00Z', 'Email me about the Sites editor beta.', 'v1'),
        identity: new IdentityProof('usr_ada', 'lead_token_and_verified_identity', 'a206f0e3-2697-42cf-a253-aa67cb5c2b39', '2026-11-06T09:30:00Z'));
}

function leadTransport(): HttpLeads
{
    return new HttpLeads(app(Factory::class), new LeadDestination('https://customer.engage.test', 'ek_customer'));
}

it('uses explicit customer credentials and the dedicated contact endpoint', function (): void {
    Http::preventStrayRequests();
    Http::fake(['customer.engage.test/v1/leads/sync' => Http::response(['status' => 'accepted', 'contact_id' => 'ct_1', 'topic_id' => 'tp_1', 'duplicate' => true])]);
    $receipt = leadTransport()->sync(verifiedLead());
    expect($receipt->contactId)->toBe('ct_1')->and($receipt->topicId)->toBe('tp_1')->and($receipt->duplicate)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://customer.engage.test/v1/leads/sync'
        && $request->hasHeader('Authorization', 'Bearer ek_customer') && $request->data() === verifiedLead()->toArray()
        && ! isset($request->data()['props']));
});

it('sends erasure without address consent or identity data', function (): void {
    Http::fake(['*' => Http::response(['status' => 'erased', 'contact_id' => null])]);
    $change = new LeadChange('org_customer', 'production', 'e58c9182-a2a4-418f-808e-aa3278794f9e', 8, '2026-11-06T10:00:00Z', 'erase', email: 'must-not-leak@example.test');
    expect(leadTransport()->sync($change)->status)->toBe('erased')->and($change->toArray())->not->toHaveKey('email')->not->toHaveKey('identity')->not->toHaveKey('consent');
});

it('reports retryable outages without silently dropping a change', function (int $status): void {
    Http::fake(['*' => Http::response(['error' => 'down'], $status, ['Retry-After' => '60'])]);
    expect(fn () => leadTransport()->sync(verifiedLead()))->toThrow(EngageUnavailable::class);
})->with([429, 500, 503]);

it('reports permanent auth scope and conflict failures', function (int $status): void {
    Http::fake(['*' => Http::response(['error' => 'rejected'], $status)]);
    expect(fn () => leadTransport()->sync(verifiedLead()))->toThrow(EngageRejected::class);
})->with([401, 403, 409, 422]);

it('rejects a redirect without forwarding customer credentials', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://elsewhere.test'])]);
    expect(fn () => leadTransport()->sync(verifiedLead()))->toThrow(EngageRejected::class);
    Http::assertSentCount(1);
});

it('reports malformed successful responses as retryable', function (): void {
    Http::fake(['*' => Http::response(['unexpected' => true])]);
    expect(fn () => leadTransport()->sync(verifiedLead()))->toThrow(EngageUnavailable::class);
});

it('maps transport failures', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection failed'));
    expect(fn () => leadTransport()->sync(verifiedLead()))->toThrow(EngageUnavailable::class);
});

it('rejects invalid destinations before issuing a request', function (string $url): void {
    expect(fn () => new LeadDestination($url, 'key'))->toThrow(InvalidArgumentException::class);
})->with(['http://engage.test', 'https://user:secret@engage.test', 'https://engage.test?key=secret', 'https://engage.test/other', 'https://engage.test#fragment']);

it('provides a fake without using the global product credentials', function (): void {
    $fake = new FakeLeads;
    $fake->sync(verifiedLead());
    $fake->assertSynced(fn (LeadChange $change): bool => $change->workspaceId === 'org_customer');
    $fake->unavailable = true;
    expect(fn () => $fake->sync(verifiedLead()))->toThrow(EngageUnavailable::class);
});
