<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Jobs;

use BJTheCod3r\Docket\Enums\ProposalState;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Proposals\ProposalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\WithoutRelations;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyProposal implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        #[WithoutRelations]
        public Proposal $proposal,
        #[WithoutRelations]
        public ?Model $by = null,
    )
    {
        $this->afterCommit();

        if ($queue = config('docket.proposals.queue')) {
            $this->onQueue($queue);
        }
    }

    public function handle(ProposalService $proposals): void
    {
        // A retry, or a manual apply in the meantime, leaves nothing to do.
        if ($this->proposal->state() !== ProposalState::Approved) {
            return;
        }

        $proposals->apply($this->proposal, by: $this->by);
    }
}
