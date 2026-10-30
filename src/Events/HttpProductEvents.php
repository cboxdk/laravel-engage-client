<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

use Cbox\Engage\Contracts\ProductEvents;
use Cbox\Engage\Engage;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Product events to Cbox Engage over HTTP, one queued job per event after the transaction
 * that produced it. Nothing that goes wrong here reaches the caller.
 */
class HttpProductEvents implements ProductEvents
{
    public function __construct(
        private readonly Engage $engage,
        private readonly Dispatcher $bus,
        private readonly Repository $config,
        private readonly LoggerInterface $log,
    ) {}

    public function record(ProductEvent $event): void
    {
        try {
            $this->bus->dispatch(new SendProductEvent(
                $event->envelope($this->engage->product(), $this->engage->environment()),
                $this->tries(),
                $this->queue(),
            ));
        } catch (Throwable $e) {
            $this->log->warning('A product event could not be queued for Cbox Engage.', ['event' => $event->name, 'event_id' => $event->eventId, 'error' => $e->getMessage()]);
        }
    }

    private function tries(): int
    {
        $tries = $this->config->get('engage.events.tries');

        return is_int($tries) && $tries > 0 ? $tries : 5;
    }

    private function queue(): ?string
    {
        $queue = $this->config->get('engage.events.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
