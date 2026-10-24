<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications\Recipients;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;

/** Owners up the parent chain; depth from config('docket.notifications.ancestor_depth'). */
class AncestorOwnerResolver implements RecipientResolver
{
    public function resolve(DocketEvent $event): iterable
    {
        $depth = (int) config('docket.notifications.ancestor_depth', 1);

        return $depth > 0 ? $event->report()->ancestorOwners($depth) : [];
    }
}
