<?php

declare(strict_types=1);

namespace Cbox\Engage\Contracts;

use Cbox\Engage\Exceptions\EngageNotConfigured;
use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Cbox\Engage\Messages\BatchReceipt;
use Cbox\Engage\Messages\BatchStatus;
use Cbox\Engage\Messages\MessageReceipt;
use Cbox\Engage\Messages\MessageStatus;
use Cbox\Engage\Messages\OutgoingBatch;
use Cbox\Engage\Messages\OutgoingMessage;

/**
 * Cbox Engage's transactional messages API: one message to one recipient, or one message
 * to many in a batch, through the workspace's send gate, and what became of it afterwards.
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

    /**
     * `POST /v1/message-batches`: one message to up to 1000 recipients. Engage validates the
     * batch as a whole and accepts it; each recipient then goes through the send gate on
     * Engage's queue as its own message, metered and capped like `send()`.
     *
     * @throws EngageUnavailable when Engage could not be reached, timed out or answered 5xx/429
     * @throws EngageRejected when Engage answered any other 4xx (a bad key, a validation error)
     * @throws EngageNotConfigured when there is no Engage to talk to
     */
    public function sendMany(OutgoingBatch $batch): BatchReceipt;

    /**
     * `GET /v1/message-batches/{id}`: the batch, its counts by status and one page of its
     * recipients. Pass the previous page's `nextCursor` for the next one.
     *
     * @throws EngageUnavailable when Engage could not be reached, timed out or answered 5xx/429
     * @throws EngageRejected when the id is unknown or any other 4xx
     * @throws EngageNotConfigured when there is no Engage to talk to
     */
    public function batch(string $id, ?string $cursor = null, int $limit = 100): BatchStatus;
}
