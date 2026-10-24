<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Rollup;

use BJTheCod3r\Docket\Contracts\RollupStrategy;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Collection;

/** The parent moves to the child's terminal status only once every child is terminal. */
class AllTerminal implements RollupStrategy
{
    public function resolve(Report $parent, Report $child, Collection $children): ?string
    {
        if (! $child->isTerminal()) {
            return null;
        }

        return $children->every(fn (Report $r) => $r->isTerminal()) ? $child->status : null;
    }
}
