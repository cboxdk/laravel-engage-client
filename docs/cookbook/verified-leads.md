---
title: Synchronize verified leads
weight: 40
description: Use explicit workspace credentials for account-free contacts, independent interests and scoped consent.
---

# Synchronize verified leads

`HttpLeads` sends contact data to Engage's dedicated lead endpoint. It is separate
from product-event ingestion and has no global credential fallback. Bind one
`LeadDestination` for the current tenant and environment; never reuse your own
product-telemetry key for customer contacts.

```php
use Cbox\Engage\Leads\HttpLeads;
use Cbox\Engage\Leads\Interest;
use Cbox\Engage\Leads\LeadChange;
use Cbox\Engage\Leads\LeadDestination;
use Illuminate\Http\Client\Factory;

$leads = new HttpLeads(app(Factory::class), new LeadDestination(
    url: $connection->url,
    key: $connection->ingest_key,
));
$receipt = $leads->sync(new LeadChange(
    workspaceId: $connection->workspace_id,
    environment: 'production',
    leadId: $registration->lead_uuid,
    sequence: 1,
    occurredAt: $registration->confirmed_at->toIso8601String(),
    email: $registration->email,
    verifiedAt: $registration->confirmed_at->toIso8601String(),
    interest: new Interest('sites-launch', $connection->brand_id, 'sites', 'Sites'),
));
```

The source must pin the same environment in Engage and hold `leads:write`. Generate
a stable UUID in your database, not from an email address. A confirmed address alone
does not grant permission: this example sends no consent. `Interest` also supports
`feature`, `program` and `kind` (`service_access`, `feature_access`, `beta`, `notify`).
Each interest has independent status; it does not grant actual product access.

An explicit optional `ConsentChoice` carries `id`, `status`, `selectedAt`,
`confirmedAt`, the exact `text` shown and `textVersion`. Its permission applies only
to that topic. Store evidence at selection/confirmation time. Never fabricate it for
historical contacts. An `IdentityProof` carries a verified ID `externalId`, `method`,
unique `proofId` and `provedAt`. A later guest link requires both lead possession and
verified identity (`lead_token_and_verified_identity`), plus the `leads:link` scope
and configured ID source. The server trusts the authenticated sender's attestation;
these value objects do not verify a session or token for you.

Persist the snapshot and sequence in the same transaction as enrollment; call HTTP
from your durable outbox worker after commit. Retry with the identical body and
sequence. `EngageUnavailable` means timeout, malformed receipt, 429 or 5xx;
`EngageRejected` means the destination refused the request. Keep failures for repair,
including identity conflicts; do not silently drop them or merge by email. Calls have
a bounded timeout and do not follow redirects. Destinations require an explicit key
and an HTTPS origin without credentials, path, query or fragment.

An erasure is `new LeadChange(..., operation: 'erase')`. Its serialized payload omits
email, consent and identity data even if the caller passed them. A receipt with
`status: erased` is terminal, including during retries and backfill. Keep a local
tombstone and deliver erasure even after normal synchronization is turned off.

`FakeLeads` implements `Contracts\Leads`, records changes, exposes `assertSynced()`
and can simulate an outage with `$fake->unavailable = true`. There is deliberately
no global singleton binding: the host supplies the correctly scoped destination.
