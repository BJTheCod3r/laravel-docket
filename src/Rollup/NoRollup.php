<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Rollup;

use BJTheCod3r\Docket\Contracts\RollupStrategy;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Collection;

class NoRollup implements RollupStrategy
{
    public function resolve(Report $parent, Report $child, Collection $children): ?string
    {
        return null;
    }
}
