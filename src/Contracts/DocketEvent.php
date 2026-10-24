<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;

interface DocketEvent
{
    /** Stable string key, e.g. "docket.report.created". Ends up in databases and settings screens. */
    public static function key(): string;

    public function report(): Report;

    /** The report whose kind and priority drive filtering: the child for ChildReportAdded, otherwise report(). */
    public function relevantReport(): Report;

    /** Who caused the event, when known. Used to skip self-notification. */
    public function actor(): ?Model;
}
