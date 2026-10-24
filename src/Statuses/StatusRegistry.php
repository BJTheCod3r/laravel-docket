<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Statuses;

use BJTheCod3r\Docket\Contracts\Status;
use BJTheCod3r\Docket\Enums\StatusCategory;
use BJTheCod3r\Docket\Exceptions\InvalidStatusException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Merges statuses in override order:
 * CoreStatus enum → config('docket.statuses.custom') → runtime register() calls.
 */
class StatusRegistry
{
    /** @var array<string, StatusDefinition> */
    protected array $runtime = [];

    /** @var array<string, array<int, string>> Extra outgoing edges added with allow(). */
    protected array $extraTransitions = [];

    /** @var array<string, StatusDefinition>|null */
    protected ?array $resolved = null;

    /**
     * @param  array<int, string>  $transitions
     */
    public function register(
        string|Status $key,
        ?string $label = null,
        StatusCategory|string|null $category = null,
        array $transitions = ['*'],
        ?string $color = null,
        ?string $icon = null,
        bool $isDefault = false,
        ?bool $isTerminal = null,
        int $sort = 0,
    ): StatusDefinition {
        $definition = $key instanceof Status
            ? StatusDefinition::fromStatus($key, 'runtime')
            : StatusDefinition::fromArray($key, [
                'label' => $label,
                'category' => $category ?? StatusCategory::Active,
                'transitions' => $transitions,
                'color' => $color,
                'icon' => $icon,
                'is_default' => $isDefault,
                'is_terminal' => $isTerminal,
                'sort' => $sort,
            ], 'runtime');

        $this->runtime[$definition->key] = $definition;
        $this->resolved = null;

        return $definition;
    }

    /**
     * Allow an additional transition from an existing status without redefining it.
     *
     * @param  string|array<int, string>  $to
     */
    public function allow(string $from, string|array $to): static
    {
        $this->extraTransitions[$from] = [...($this->extraTransitions[$from] ?? []), ...Arr::wrap($to)];
        $this->resolved = null;

        return $this;
    }

    public function has(string $key): bool
    {
        return $this->all()->has($key);
    }

    public function find(string|Status $key): ?StatusDefinition
    {
        $key = $key instanceof Status ? $key->key() : $key;

        return $this->all()->get($key);
    }

    /**
     * @throws InvalidStatusException
     */
    public function get(string|Status $key): StatusDefinition
    {
        $key = $key instanceof Status ? $key->key() : $key;

        return $this->find($key) ?? throw new InvalidStatusException("Status [{$key}] is not registered.");
    }

    public function category(string|Status $key): StatusCategory
    {
        return $this->get($key)->category;
    }

    public function canTransition(string|Status $from, string|Status $to): bool
    {
        $to = $to instanceof Status ? $to->key() : $to;

        return $this->has($to) && ($this->find($from)?->allowsTransitionTo($to) ?? false);
    }

    public function default(): StatusDefinition
    {
        $configured = (string) config('docket.reports.default_status', CoreStatus::Open->value);

        if ($this->has($configured)) {
            return $this->get($configured);
        }

        return $this->all()->first(fn (StatusDefinition $s) => $s->isDefault)
            ?? $this->get(CoreStatus::Open);
    }

    /** @return Collection<string, StatusDefinition> */
    public function all(): Collection
    {
        if ($this->resolved === null) {
            $this->resolved = $this->build();
        }

        return new Collection($this->resolved);
    }

    /** @return Collection<string, StatusDefinition> */
    public function inCategory(StatusCategory|string $category): Collection
    {
        $category = StatusCategory::fromMixed($category);

        return $this->all()->filter(fn (StatusDefinition $s) => $s->category === $category);
    }

    public function flush(): static
    {
        $this->resolved = null;

        return $this;
    }

    /** @return array<string, StatusDefinition> */
    protected function build(): array
    {
        $statuses = collect(CoreStatus::cases())
            ->mapWithKeys(fn (CoreStatus $case) => [$case->key() => StatusDefinition::fromStatus($case, 'core')])
            ->merge(collect((array) config('docket.statuses.custom', []))
                ->mapWithKeys(fn ($attributes, $key) => [$key => StatusDefinition::fromArray((string) $key, (array) $attributes, 'config')]))
            ->merge($this->runtime);

        collect($this->extraTransitions)->each(function (array $targets, string $from) use ($statuses) {
            $status = $statuses->get($from);

            if ($status !== null) {
                $statuses->put($from, $status->withTransitions([...$status->transitions, ...$targets]));
            }
        });

        return $statuses
            ->sortBy(fn (StatusDefinition $s) => [$s->sort, $s->category->rank()])
            ->all();
    }

}
