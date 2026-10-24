<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications\Recipients;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;

/** Only when config('docket.notifications.notify_reporter') is true. */
class ReporterResolver implements RecipientResolver
{
    public function resolve(DocketEvent $event): iterable
    {
        if (config('docket.notifications.notify_reporter', false)) {
            yield $event->report()->reporter;
        }
    }
}
