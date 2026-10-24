<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;

class StatusChanged extends ReportEvent
{
    public function __construct(
        Report $report,
        public string $from,
        public string $to,
        ?Model $actor = null,
        public ?string $reason = null,
        public bool $viaRollup = false,
    ) {
        parent::__construct($report, $actor);
    }

    public static function key(): string
    {
        return 'docket.report.status_changed';
    }
}
