<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Concerns;

use BJTheCod3r\Docket\Enums\ProposalStrategy;
use BJTheCod3r\Docket\Contracts\Proposable;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Declare `protected array $proposable = [...]` for attributes and
 * `protected array $proposableFiles = ['avatar' => [...]]` for file fields.
 *
 * @property array<int, string> $proposable
 * @property array<string, array<string, mixed>> $proposableFiles
 *
 * @mixin Model
 * @phpstan-require-implements Proposable
 */
trait HasProposals
{
    /** @return MorphMany<Proposal, $this> */
    public function proposals(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('proposal'), 'target', StringKeyedMorphMany::morphKeysAreStrings());
    }

    /** @return MorphMany<Proposal, $this> */
    public function pendingProposals(): MorphMany
    {
        return $this->proposals()->pending();
    }

    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $files
     */
    public function proposeChanges(
        ?array $changes = null,
        ?array $files = null,
        ?Model $by = null,
        ?string $reason = null,
        ?string $title = null,
        ?int $requiredApprovals = null,
        ProposalStrategy|string|null $strategy = null,
    ): Proposal {
        return Docket::proposals()->propose(
            target: $this,
            changes: $changes,
            files: $files,
            by: $by,
            reason: $reason,
            title: $title,
            requiredApprovals: $requiredApprovals,
            strategy: $strategy,
        );
    }

    /**
     * property_exists() rather than `$this->proposable ?? []`: on a model without the
     * property, Eloquent's __get would treat the same-named method as a relation.
     *
     * @return array<int, string>
     */
    public function proposableAttributes(): array
    {
        return property_exists($this, 'proposable') ? $this->proposable : [];
    }

    /** @return array<string, array<string, mixed>> */
    public function proposableFiles(): array
    {
        return property_exists($this, 'proposableFiles') ? $this->proposableFiles : [];
    }

    /**
     * Validation rules run at propose time and again at apply time.
     *
     * @return array<string, mixed>
     */
    public function proposalRules(): array
    {
        return [];
    }

    /**
     * Who may approve proposals against this model. Null = anyone.
     *
     * @return iterable<int, Model>|null
     */
    public function proposalApprovers(): ?iterable
    {
        return null;
    }
}
