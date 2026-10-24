<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications\Recipients;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;
use Closure;
use Illuminate\Support\Collection;

class CallbackResolver implements RecipientResolver
{
    public function __construct(protected Closure $callback) {}

    public function resolve(DocketEvent $event): iterable
    {
        return Collection::wrap(($this->callback)($event) ?? []);
    }
}
