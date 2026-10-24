<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Attributes\WithoutRelations;

abstract class ProposalEvent extends ReportEvent
{
    public function __construct(
        #[WithoutRelations]
        public Proposal $proposal,
        ?Model $actor = null,
    ) {
        parent::__construct($proposal->report, $actor);
    }

    public function report(): Report
    {
        return $this->proposal->report;
    }
}
