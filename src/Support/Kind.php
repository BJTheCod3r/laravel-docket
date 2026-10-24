<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Support;

final class Kind
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $rollup = null,
        public readonly ?string $defaultStatus = null,
        public readonly ?string $defaultVisibility = null,
        public readonly bool $notifies = true,
    ) {}

    /** @param  array<string, mixed>  $attributes */
    public static function fromArray(string $key, array $attributes): self
    {
        return new self(
            key: $key,
            label: (string) ($attributes['label'] ?? str_replace('_', ' ', ucfirst($key))),
            rollup: $attributes['rollup'] ?? null,
            defaultStatus: $attributes['default_status'] ?? null,
            defaultVisibility: $attributes['default_visibility'] ?? null,
            notifies: (bool) ($attributes['notifies'] ?? true),
        );
    }
}
