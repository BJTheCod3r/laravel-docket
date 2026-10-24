<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

/** Fired for every report, children included. */
class ReportCreated extends ReportEvent
{
    public static function key(): string
    {
        return 'docket.report.created';
    }
}
