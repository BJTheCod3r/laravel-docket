<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Statuses;

use BJTheCod3r\Docket\Contracts\Status;
use BJTheCod3r\Docket\Enums\StatusCategory;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/** @implements Arrayable<string, mixed> */
final class StatusDefinition implements Arrayable, JsonSerializable, Status
{
    /**
     * @param  array<int, string>  $transitions
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly StatusCategory $category,
        public readonly array $transitions = ['*'],
        public readonly ?string $color = null,
        public readonly ?string $icon = null,
        public readonly bool $isDefault = false,
        public readonly ?bool $isTerminal = null,
        public readonly int $sort = 0,
        public readonly string $source = 'runtime',
    ) {}

    public static function fromStatus(Status $status, string $source = 'core'): self
    {
        return new self(
            key: $status->key(),
            label: $status->label(),
            category: $status->category(),
            transitions: $status->transitionsTo(),
            color: $status->color(),
            isTerminal: $status->isTerminal(),
            source: $source,
        );
    }

    /** @param  array<string, mixed>  $attributes */
    public static function fromArray(string $key, array $attributes, string $source = 'config'): self
    {
        return new self(
            key: $key,
            label: (string) ($attributes['label'] ?? str_replace('_', ' ', ucfirst($key))),
            category: StatusCategory::fromMixed($attributes['category'] ?? 'active'),
            transitions: array_values((array) ($attributes['transitions'] ?? ['*'])),
            color: $attributes['color'] ?? null,
            icon: $attributes['icon'] ?? null,
            isDefault: (bool) ($attributes['is_default'] ?? false),
            isTerminal: isset($attributes['is_terminal']) ? (bool) $attributes['is_terminal'] : null,
            sort: (int) ($attributes['sort'] ?? 0),
            source: $source,
        );
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function category(): StatusCategory
    {
        return $this->category;
    }

    public function transitionsTo(): array
    {
        return $this->transitions;
    }

    public function isTerminal(): bool
    {
        return $this->isTerminal ?? $this->category->isTerminal();
    }

    public function color(): ?string
    {
        return $this->color;
    }

    public function allowsTransitionTo(string $key): bool
    {
        return in_array('*', $this->transitions, true) || in_array($key, $this->transitions, true);
    }

    /** @param  array<int, string>  $transitions */
    public function withTransitions(array $transitions): self
    {
        return new self(
            $this->key, $this->label, $this->category, array_values(array_unique($transitions)),
            $this->color, $this->icon, $this->isDefault, $this->isTerminal, $this->sort,
            $this->source,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'category' => $this->category->value,
            'transitions' => $this->transitions,
            'color' => $this->color,
            'icon' => $this->icon,
            'is_default' => $this->isDefault,
            'is_terminal' => $this->isTerminal(),
            'sort' => $this->sort,
            'source' => $this->source,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
