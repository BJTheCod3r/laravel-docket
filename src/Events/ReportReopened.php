<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

/** Category-based: fired when a terminal report becomes open or active again. */
class ReportReopened extends ReportEvent
{
    public static function key(): string
    {
        return 'docket.report.reopened';
    }
}
