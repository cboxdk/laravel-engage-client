<?php

declare(strict_types=1);

namespace Cbox\Engage\Events;

use Cbox\Engage\Exceptions\EngageRejected;
use Cbox\Engage\Exceptions\EngageUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;

/**
 * One product event to Cbox Engage, after the transaction that produced it. Retried with
 * backoff while Engage is unavailable (its event id makes a retry harmless); dropped after
 * one warning when Engage refuses it, since sending again would not help.
 */
class SendProductEvent implements ShouldQueue
{
    use Queueable;

    public int $tries;

    /**
     * @param  array<string, mixed>  $envelope
     */
    public function __construct(public readonly array $envelope, int $tries = 5, ?string $queue = null)
    {
        $this->tries = max(1, $tries);
        $this->afterCommit();

        if ($queue !== null && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(EventIngest $ingest, LoggerInterface $log): void
    {
        try {
            $ingest->send([$this->envelope]);
        } catch (EngageRejected $e) {
            $log->warning('Cbox Engage refused a product event; it is not sent again.', $this->context($e->getMessage()));
        } catch (EngageUnavailable $e) {
            $log->warning('Cbox Engage did not take a product event yet.', $this->context($e->getMessage()));

            if ($this->attempts() < $this->tries) {
                $this->release(max($this->backoff()[$this->attempts() - 1] ?? 3600, $e->retryAfter ?? 0));
            }
        }
    }

    /** @return array<string, mixed> */
    private function context(string $error): array
    {
        return ['event' => $this->envelope['name'] ?? null, 'event_id' => $this->envelope['event_id'] ?? null, 'error' => $error];
    }
}
