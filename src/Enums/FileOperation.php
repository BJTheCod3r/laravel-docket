<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum FileOperation: string
{
    case Replace = 'replace';
    case Add = 'add';
    case Remove = 'remove';
    case Reorder = 'reorder';
}
