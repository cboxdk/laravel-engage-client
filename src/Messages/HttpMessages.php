<?php

declare(strict_types=1);

namespace Cbox\Engage\Messages;

use Cbox\Engage\Contracts\Messages;
use Cbox\Engage\Engage;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Http\Responses;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/** The messages API over Laravel's HTTP client, with the configured timeout. */
class HttpMessages implements Messages
{
    public function __construct(
        private readonly Engage $engage,
        private readonly Factory $http,
    ) {}

    public function send(OutgoingMessage $message): MessageReceipt
    {
        $response = $this->call(fn (PendingRequest $request): Response => $request->post('/v1/messages', $message->toArray()));

        return MessageReceipt::fromArray($this->body($response));
    }

    public function status(string $id): MessageStatus
    {
        $response = $this->call(fn (PendingRequest $request): Response => $request->get('/v1/messages/'.rawurlencode($id)));

        return MessageStatus::fromArray($this->body($response));
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function call(callable $call): Response
    {
        $timeout = $this->engage->timeout();

        try {
            $response = $call($this->http
                ->baseUrl($this->engage->url())
                ->withToken($this->engage->key())
                ->acceptJson()
                ->asJson()
                ->connectTimeout($timeout)
                ->timeout($timeout));
        } catch (ConnectionException $e) {
            throw EngageUnavailable::fromConnection($e);
        }

        if ($response->successful()) {
            return $response;
        }

        throw Responses::failure($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? Payload::map(['body' => $json], 'body') : [];
    }
}
