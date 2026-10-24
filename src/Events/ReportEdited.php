<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Models\Revision;
use Illuminate\Database\Eloquent\Model;

/** title / description / meta / visibility changed; carries the Revision. */
class ReportEdited extends ReportEvent
{
    public function __construct(
        Report $report,
        public Revision $revision,
        ?Model $actor = null,
    ) {
        parent::__construct($report, $actor);
    }

    public static function key(): string
    {
        return 'docket.report.edited';
    }
}
