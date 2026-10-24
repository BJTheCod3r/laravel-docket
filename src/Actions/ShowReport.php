<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Actions;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;

class ShowReport
{
    public function handle(string $report, ?Model $viewer): Report
    {
        /** @var class-string<Report> $model */
        $model = Docket::model('report');

        return $model::query()->visibleTo($viewer)->byReferenceOrKey($report)->firstOrFail()
            ->load(['children' => fn ($query) => $query->visibleTo($viewer), 'transitions', 'attachments' => fn ($query) => $query->live()]);
    }
}
