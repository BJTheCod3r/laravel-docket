<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
