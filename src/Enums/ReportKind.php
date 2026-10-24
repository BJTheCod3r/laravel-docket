<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

/**
 * The kinds that ship with the package. Kinds are plain strings at the
 * database level so companions can add their own via Docket::kind().
 */
enum ReportKind: string
{
    case Report = 'report';
    case Comment = 'comment';
    case Resolution = 'resolution';
    case Proposal = 'proposal';
}
