<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Enums;

enum MuteDirection: string
{
    /** Suppress notifications *concerning* the scope (a Service, a Report, or everything). */
    case About = 'about';

    /** Suppress notifications *delivered to* the scope (a User, a Team). */
    case For = 'for';
}
