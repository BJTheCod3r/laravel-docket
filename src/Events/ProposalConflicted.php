<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

use BJTheCod3r\Docket\Models\Proposal;
use Illuminate\Database\Eloquent\Model;

class ProposalConflicted extends ProposalEvent
{
    /** @param  array<string, array{original: mixed, current: mixed, proposed: mixed}>  $conflicts */
    public function __construct(
        Proposal $proposal,
        public array $conflicts,
        ?Model $actor = null,
    ) {
        parent::__construct($proposal, $actor);
    }

    public static function key(): string
    {
        return 'docket.proposal.conflicted';
    }
}
