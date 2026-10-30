<?php

declare(strict_types=1);

namespace Cbox\Engage\Contracts;

use Cbox\Engage\Exceptions\EngageNotConfigured;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\MessageReceipt;
use Cbox\Engage\Messages\MessageStatus;
use Cbox\Engage\Messages\OutgoingMessage;

/**
 * Cbox Engage's transactional messages API: one message to one recipient, through the
 * workspace's send gate, and what became of it afterwards.
 */
interface Messages
{
    /**
     * `POST /v1/messages`. Accepted or gated out, the receipt says which; the send itself
     * happens on Engage's queue.
     *
     * @throws EngageUnavailable when Engage could not be reached, timed out or answered 5xx/429
     * @throws EngageRejected when Engage answered any other 4xx (a bad key, a validation error)
     * @throws EngageNotConfigured when there is no Engage to talk to
     */
    public function send(OutgoingMessage $message): MessageReceipt;

    /**
     * `GET /v1/messages/{id}`: the message's state and its delivery log, oldest first.
     *
     * @throws EngageUnavailable when Engage could not be reached, timed out or answered 5xx/429
     * @throws EngageRejected when the id is unknown or any other 4xx
     * @throws EngageNotConfigured when there is no Engage to talk to
     */
    public function status(string $id): MessageStatus;
}
