<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;

class ReportOwnerChanged extends ReportEvent
{
    public function __construct(
        Report $report,
        public ?Model $owner,
        public ?Model $previousOwner = null,
        ?Model $actor = null,
    ) {
        parent::__construct($report, $actor);
    }

    public static function key(): string
    {
        return 'docket.report.owner_changed';
    }
}
