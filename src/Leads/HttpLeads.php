<?php

declare(strict_types=1);

namespace Cbox\Engage\Leads;

use Cbox\Engage\Contracts\Leads;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Http\Responses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

class HttpLeads implements Leads
{
    public function __construct(private Factory $http, private LeadDestination $destination) {}

    public function sync(LeadChange $change): LeadReceipt
    {
        try {
            $response = $this->http->withToken($this->destination->key)->acceptJson()->asJson()->withoutRedirecting()
                ->connectTimeout($this->destination->timeout)->timeout($this->destination->timeout)
                ->post(rtrim($this->destination->url, '/').'/v1/leads/sync', $change->toArray());
        } catch (ConnectionException $e) {
            throw EngageUnavailable::fromConnection($e);
        }
        if (! $response->successful()) {
            throw Responses::failure($response);
        }

        return LeadReceipt::fromJson($response->json());
    }
}
