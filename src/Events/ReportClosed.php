<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

/** Category-based: fired when a report enters the closed category. */
class ReportClosed extends ReportEvent
{
    public static function key(): string
    {
        return 'docket.report.closed';
    }
}
