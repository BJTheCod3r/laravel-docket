<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum ProposalStrategy: string
{
    /** Keyed arrays (JSON objects) merge into the current value. */
    case Patch = 'patch';

    /** Every attribute is overwritten wholesale. */
    case Replace = 'replace';
}
