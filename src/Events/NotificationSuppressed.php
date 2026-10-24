<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Notifications\AnonymousNotifiable;

/**
 * "Why didn't I get paged?" — emitted whenever the pipeline decides not to
 * deliver. Not a DocketEvent itself, so it never re-enters the pipeline.
 */
class NotificationSuppressed
{
    use Dispatchable;

    /**
     * @param  string  $reason  muted | muted_for | quiet_hours | cooldown | ignored | disabled | kind | deferred | no_channels
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public DocketEvent $event,
        public Model|AnonymousNotifiable|null $recipient,
        public string $reason,
        public array $context = [],
    ) {}

    public function eventKey(): string
    {
        return $this->event::key();
    }
}
