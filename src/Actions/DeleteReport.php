<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Actions;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class DeleteReport
{
    /** @throws AuthorizationException */
    public function handle(string $report, ?Model $by): void
    {
        /** @var class-string<Report> $model */
        $model = Docket::model('report');

        $found = $model::query()->visibleTo($by)->byReferenceOrKey($report)->firstOrFail();

        Docket::authorize($by, 'delete', $found);

        $found->delete();
    }
}
