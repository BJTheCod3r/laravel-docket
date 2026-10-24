<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

/** Category-based: fired when a report enters the resolved category. */
class ReportResolved extends ReportEvent
{
    public static function key(): string
    {
        return 'docket.report.resolved';
    }
}
