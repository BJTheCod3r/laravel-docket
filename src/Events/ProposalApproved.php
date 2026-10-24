<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

class ProposalApproved extends ProposalEvent
{
    public static function key(): string
    {
        return 'docket.proposal.approved';
    }
}
