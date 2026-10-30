<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

use Cbox\Engage\Engage;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Http\Responses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Psr\Log\LoggerInterface;

/**
 * Engage's ingest endpoint, `POST /v1/events`, with this app's key. One call, one batch;
 * what Engage answers decides whether the batch is sent again.
 */
class EventIngest
{
    public function __construct(
        private readonly Engage $engage,
        private readonly Factory $http,
        private readonly LoggerInterface $log,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $envelopes
     *
     * @throws EngageRejected when sending again would not help (any 4xx but 429)
     * @throws EngageUnavailable when it might (unreachable, 429, 5xx)
     */
    public function send(array $envelopes): void
    {
        try {
            $response = $this->http
                ->baseUrl($this->engage->url())
                ->withToken($this->engage->key())
                ->acceptJson()
                ->asJson()
                ->connectTimeout(min(3, $this->engage->eventsTimeout()))
                ->timeout($this->engage->eventsTimeout())
                ->post('/v1/events', $envelopes);
        } catch (ConnectionException $e) {
            throw EngageUnavailable::fromConnection($e);
        }

        if ($response->successful()) {
            $rejected = $response->json('rejected');

            // Taken as a batch, but Engage would not have one of ours: a bug in what the
            // app builds, not something a retry fixes.
            if (is_array($rejected) && $rejected !== []) {
                $this->log->warning('Cbox Engage rejected product events from a batch.', ['rejected' => $rejected]);
            }

            return;
        }

        throw Responses::failure($response);
    }
}
