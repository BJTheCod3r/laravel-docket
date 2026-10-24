<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications\Recipients;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;
use BJTheCod3r\Docket\Models\Service;
use Illuminate\Notifications\AnonymousNotifiable;

/** Each implicated service's owner, plus its contact email (as an on-demand mail notifiable). */
class ServiceStakeholderResolver implements RecipientResolver
{
    public function resolve(DocketEvent $event): iterable
    {
        foreach ($event->report()->services as $service) {
            /** @var Service $service */
            yield $service->owner;

            if ($service->email) {
                yield (new AnonymousNotifiable)->route('mail', $service->email);
            }
        }
    }
}
