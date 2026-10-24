<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Proposal;
use Illuminate\Database\Eloquent\Model;

interface ChangeApplier
{
    /**
     * Write the proposed changes to the target and return a snapshot that
     * revert() can restore from.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    public function apply(Model $target, array $changes, Proposal $proposal): array;

    /** @param  array<string, mixed>  $snapshot */
    public function revert(Model $target, array $snapshot, Proposal $proposal): void;
}
