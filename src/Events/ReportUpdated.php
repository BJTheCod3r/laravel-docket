<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

class ReportUpdated extends ReportEvent
{
    public static function key(): string
    {
        return 'docket.report.updated';
    }
}
