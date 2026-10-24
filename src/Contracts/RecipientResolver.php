<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;

interface RecipientResolver
{
    /** @return iterable<int, Model|AnonymousNotifiable|null> */
    public function resolve(DocketEvent $event): iterable;
}
