<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Events;

class ProposalRejected extends ProposalEvent
{
    public static function key(): string
    {
        return 'docket.proposal.rejected';
    }
}
