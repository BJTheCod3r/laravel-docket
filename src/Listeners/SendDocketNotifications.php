<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Listeners;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Notifications\NotificationPipeline;

class SendDocketNotifications
{
    public function __construct(protected NotificationPipeline $pipeline) {}

    public function handle(DocketEvent $event): void
    {
        $this->pipeline->handle($event);
    }
}
