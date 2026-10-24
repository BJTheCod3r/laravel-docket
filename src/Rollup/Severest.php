<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Rollup;

use BJTheCod3r\Docket\Contracts\RollupStrategy;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Collection;

/**
 * The parent takes the least-resolved child status by category rank
 * (open > active > resolved > closed). Ties go to the most recently changed child.
 */
class Severest implements RollupStrategy
{
    public function resolve(Report $parent, Report $child, Collection $children): ?string
    {
        if ($children->isEmpty()) {
            return null;
        }

        $severest = $children
            ->sortBy([
                fn (Report $a, Report $b) => $a->statusCategory()->rank() <=> $b->statusCategory()->rank(),
                fn (Report $a, Report $b) => ($b->status_changed_at ?? $b->created_at) <=> ($a->status_changed_at ?? $a->created_at),
            ])
            ->first();

        return $severest?->status;
    }
}
