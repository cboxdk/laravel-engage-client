<?php

declare(strict_types=1);

namespace Cbox\Engage\Mail;

use Illuminate\Mail\Mailable;
use Symfony\Component\Mime\Email;

/**
 * Tells the `engage` mail driver who a Mailable is for and how to send it, through the
 * `X-Engage-*` headers the driver reads and strips. Use it on a Mailable:
 *
 *     return (new PasswordReset($token))->engage(externalId: $user->cbox_id, idempotencyKey: "password-reset:{$token->id}");
 *
 * @phpstan-require-extends Mailable
 */
trait EngageOptions
{
    /**
     * @param  list<string>  $tags
     */
    public function engage(
        ?string $externalId = null,
        ?string $idempotencyKey = null,
        ?string $purpose = null,
        ?bool $trackClicks = null,
        ?bool $trackOpens = null,
        array $tags = [],
    ): static {
        $this->withSymfonyMessage(static function (Email $message) use ($externalId, $idempotencyKey, $purpose, $trackClicks, $trackOpens, $tags): void {
            $headers = $message->getHeaders();

            foreach ([
                Steering::EXTERNAL_ID => $externalId,
                Steering::IDEMPOTENCY_KEY => $idempotencyKey,
                Steering::PURPOSE => $purpose,
                Steering::TRACK_CLICKS => $trackClicks === null ? null : ($trackClicks ? 'true' : 'false'),
                Steering::TRACK_OPENS => $trackOpens === null ? null : ($trackOpens ? 'true' : 'false'),
                Steering::TAGS => $tags === [] ? null : implode(',', $tags),
            ] as $name => $value) {
                if ($value === null) {
                    continue;
                }

                $headers->remove($name);
                $headers->addTextHeader($name, $value);
            }
        });

        return $this;
    }
}
