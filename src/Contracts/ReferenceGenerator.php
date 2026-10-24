<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Report;

interface ReferenceGenerator
{
    public function generate(Report $report): string;
}
