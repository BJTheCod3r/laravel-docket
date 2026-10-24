<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Queue\SerializesModels;

abstract class ReportEvent implements DocketEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        #[WithoutRelations]
        public Report $report,
        #[WithoutRelations]
        public ?Model $actor = null,
    ) {}

    public function report(): Report
    {
        return $this->report;
    }

    public function actor(): ?Model
    {
        return $this->actor;
    }

    public function relevantReport(): Report
    {
        return $this->report;
    }
}
