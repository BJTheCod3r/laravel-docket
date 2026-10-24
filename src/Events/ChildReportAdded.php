<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\WithoutRelations;

/** Fired on the *parent* when a child report is filed beneath it. */
class ChildReportAdded extends ReportEvent
{
    public function __construct(
        Report $report,
        #[WithoutRelations]
        public Report $child,
        ?Model $actor = null,
    ) {
        parent::__construct($report, $actor);
    }

    public static function key(): string
    {
        return 'docket.report.child_added';
    }

    public function relevantReport(): Report
    {
        return $this->child;
    }
}
