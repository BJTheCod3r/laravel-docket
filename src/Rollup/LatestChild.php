<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Rollup;

use BJTheCod3r\Docket\Contracts\RollupStrategy;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Collection;

/** The parent mirrors the most recent child transition. */
class LatestChild implements RollupStrategy
{
    public function resolve(Report $parent, Report $child, Collection $children): ?string
    {
        return $child->status;
    }
}
