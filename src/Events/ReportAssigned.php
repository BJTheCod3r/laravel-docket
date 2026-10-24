<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;

class ReportAssigned extends ReportEvent
{
    public function __construct(
        Report $report,
        public ?Model $assignee,
        public ?Model $previousAssignee = null,
        ?Model $actor = null,
    ) {
        parent::__construct($report, $actor);
    }

    public static function key(): string
    {
        return 'docket.report.assigned';
    }
}
