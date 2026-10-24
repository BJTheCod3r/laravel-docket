<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Rollup;

use BJTheCod3r\Docket\Contracts\RollupStrategy;
use BJTheCod3r\Docket\Exceptions\DocketException;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Support\Kind;
use BJTheCod3r\Docket\Support\KindRegistry;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class RollupManager
{
    /** @var array<string, class-string<RollupStrategy>> */
    protected array $aliases = [
        'none' => NoRollup::class,
        'latest_child' => LatestChild::class,
        'all_terminal' => AllTerminal::class,
        'severest' => Severest::class,
    ];

    /** @var array<string, RollupStrategy> */
    protected array $instances = [];

    /** @var array<string, true> Report keys currently rolling up. */
    protected array $inFlight = [];

    protected bool $disabled = false;

    public function __construct(protected Container $container) {}

    public function strategyFor(string $kind): RollupStrategy
    {
        return $this->resolve($this->container->make(KindRegistry::class)->find($kind)->rollup ?? $this->defaultStrategy());
    }

    public function resolve(string $name): RollupStrategy
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        $class = $this->aliases[$name] ?? $name;

        if (! class_exists($class) || ! is_subclass_of($class, RollupStrategy::class)) {
            throw new DocketException("Rollup strategy [{$name}] is not a valid RollupStrategy.");
        }

        return $this->instances[$name] = $this->container->make($class);
    }

    /**
     * Run a callback with rollup switched off (e.g. bulk imports).
     */
    public function withoutRollup(callable $callback): mixed
    {
        $previous = $this->disabled;
        $this->disabled = true;

        try {
            return $callback();
        } finally {
            $this->disabled = $previous;
        }
    }

    public function apply(Report $child, ?Model $actor = null): void
    {
        if ($this->disabled || $child->parent_id === null || ($strategy = $this->strategyFor($child->kind)) instanceof NoRollup) {
            return;
        }

        $parent = $this->currentParent($child);

        if ($parent === null || isset($this->inFlight[(string) $parent->getKey()])) {
            return;
        }

        $this->transitionParent($parent, $strategy->resolve($parent, $child, $this->participants($parent)), $child, $actor);
    }

    /**
     * Re-derive the parent's status from its remaining children, e.g. after one was deleted.
     * A parent with no participating children left keeps its status.
     */
    public function recalculate(Report $parent, ?Model $actor = null): void
    {
        if ($this->disabled || isset($this->inFlight[(string) $parent->getKey()])) {
            return;
        }

        $children = $this->participants($parent);
        $latest = $children->sortByDesc('status_changed_at')->first();

        if ($latest !== null) {
            $this->transitionParent($parent, $this->strategyFor($latest->kind)->resolve($parent, $latest, $children), $latest, $actor);
        }
    }

    /**
     * Children whose kind takes part in rollup; a comment never holds its parent open.
     *
     * @return Collection<int, Report>
     */
    protected function participants(Report $parent): Collection
    {
        $kinds = $this->container->make(KindRegistry::class)->all()
            ->groupBy(fn (Kind $kind) => $this->resolve($kind->rollup ?? $this->defaultStrategy()) instanceof NoRollup ? 'none' : 'rolls', preserveKeys: true);

        // Unregistered kinds use the default strategy, so filter by whichever list is exact.
        return $this->resolve($this->defaultStrategy()) instanceof NoRollup
            ? $parent->children()->whereIn('kind', $kinds->get('rolls', collect())->keys()->all())->get()
            : $parent->children()->whereNotIn('kind', $kinds->get('none', collect())->keys()->all())->get();
    }

    /**
     * The parent instance the caller holds when it is loaded (so their object sees the new
     * status), refreshed from the database so the transition starts from the current row.
     */
    protected function currentParent(Report $child): ?Report
    {
        if (! $child->relationLoaded('parent') || $child->parent === null) {
            return $child->parent()->first();
        }

        $parent = $child->parent;
        $current = $parent->newQueryWithoutScopes()->whereKey($parent->getKey())->first();

        return $current === null ? null : $parent->setRawAttributes($current->getAttributes(), sync: true);
    }

    protected function defaultStrategy(): string
    {
        return (string) config('docket.rollup.default', 'severest');
    }

    protected function transitionParent(Report $parent, ?string $target, Report $child, ?Model $actor): void
    {
        if ($target === null || $target === $parent->status) {
            return;
        }

        $this->inFlight[(string) $parent->getKey()] = true;

        try {
            $parent->tryTransitionTo($target, by: $actor, reason: "Rolled up from {$child->reference}", viaRollup: true);
        } finally {
            unset($this->inFlight[(string) $parent->getKey()]);
        }
    }
}
