<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Collection;

interface RollupStrategy
{
    /**
     * Decide which status key the parent should take after a child was created,
     * transitioned or deleted. Return null to leave the parent untouched.
     *
     * @param  Collection<int, Report>  $children  The parent's direct children whose kind takes part in rollup, freshly loaded.
     */
    public function resolve(Report $parent, Report $child, Collection $children): ?string;
}
