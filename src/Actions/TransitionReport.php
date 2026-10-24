<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Actions;

use BJTheCod3r\Docket\Exceptions\InvalidTransitionException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class TransitionReport
{
    /** @throws AuthorizationException|InvalidTransitionException */
    public function handle(string $report, string $status, ?Model $by = null, ?string $reason = null): Report
    {
        /** @var class-string<Report> $model */
        $model = Docket::model('report');

        $found = $model::query()->visibleTo($by)->byReferenceOrKey($report)->firstOrFail();

        Docket::authorize($by, 'transition', $found, [$status]);

        $found->transitionTo($status, by: $by, reason: $reason);

        return $found;
    }
}
