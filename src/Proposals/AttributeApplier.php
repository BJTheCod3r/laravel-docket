<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Proposals;

use BJTheCod3r\Docket\Enums\ProposalStrategy;
use BJTheCod3r\Docket\Contracts\ChangeApplier;
use BJTheCod3r\Docket\Models\Proposal;
use Illuminate\Database\Eloquent\Model;

/**
 * Under the `patch` strategy, keyed arrays (JSON objects) merge into the
 * current value; under `replace` every attribute is overwritten wholesale.
 */
class AttributeApplier implements ChangeApplier
{
    public function apply(Model $target, array $changes, Proposal $proposal): array
    {
        // Model::only() reads through getAttribute(), so casts are honoured on revert.
        $snapshot = $target->only(array_keys($changes));

        if ($proposal->strategy !== ProposalStrategy::Replace->value) {
            $changes = collect($changes)->map(fn ($value, string $attribute) => $this->patch($snapshot[$attribute] ?? null, $value))->all();
        }

        $target->forceFill($changes)->save();

        return $snapshot;
    }

    protected function patch(mixed $current, mixed $proposed): mixed
    {
        if (! is_array($current) || ! is_array($proposed) || array_is_list($proposed)) {
            return $proposed;
        }

        foreach ($proposed as $key => $value) {
            $current[$key] = $this->patch($current[$key] ?? null, $value);
        }

        return $current;
    }

    public function revert(Model $target, array $snapshot, Proposal $proposal): void
    {
        $target->forceFill($snapshot)->save();
    }
}
