<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications\Recipients;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;

class OwnerResolver implements RecipientResolver
{
    public function resolve(DocketEvent $event): iterable
    {
        yield $event->report()->owner;
    }
}
