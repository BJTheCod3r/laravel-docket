<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum ProposalState: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';
    case Applied = 'applied';
    case Conflicted = 'conflicted';
    case Reverted = 'reverted';

    /**
     * States still open for review, apply, rebase or withdrawal.
     *
     * @return array<int, self>
     */
    public static function pending(): array
    {
        return [self::Submitted, self::Approved, self::Conflicted];
    }
}
