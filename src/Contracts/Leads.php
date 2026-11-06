<?php

declare(strict_types=1);

namespace Cbox\Engage\Contracts;

use Cbox\Engage\Leads\LeadChange;
use Cbox\Engage\Leads\LeadReceipt;

interface Leads
{
    public function sync(LeadChange $change): LeadReceipt;
}
