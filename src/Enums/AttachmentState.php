<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum AttachmentState: string
{
    case Staged = 'staged';
    case Live = 'live';
    case Archived = 'archived';
}
