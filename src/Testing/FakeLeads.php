<?php

declare(strict_types=1);

namespace Cbox\Engage\Testing;

use Cbox\Engage\Contracts\Leads;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Leads\LeadChange;
use Cbox\Engage\Leads\LeadReceipt;
use PHPUnit\Framework\Assert;

class FakeLeads implements Leads
{
    /** @var list<LeadChange> */
    public array $changes = [];

    public bool $unavailable = false;

    public function sync(LeadChange $change): LeadReceipt
    {
        if ($this->unavailable) {
            throw new EngageUnavailable('Unavailable (faked).', 'timeout');
        }
        $this->changes[] = $change;

        return new LeadReceipt($change->operation === 'erase' ? 'erased' : 'accepted', 'ct_fake');
    }

    /** @param callable(LeadChange): bool $matches */
    public function assertSynced(callable $matches): void
    {
        Assert::assertNotEmpty(array_filter($this->changes, $matches));
    }
}
