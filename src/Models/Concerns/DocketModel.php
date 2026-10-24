<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models\Concerns;

use BJTheCod3r\Docket\Facades\Docket;
use Illuminate\Support\Str;

trait DocketModel
{
    /**
     * Runs from Model::__construct before fill(), via Laravel's initialize{Trait} hook.
     */
    public function initializeDocketModel(): void
    {
        if ($this->docketKeyType() !== 'id') {
            $this->usesUniqueIds = true;
            $this->incrementing = false;
            $this->keyType = 'string';
        }
    }

    public function getTable(): string
    {
        return $this->table ?? Docket::table($this->docketTable);
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return $this->docketKeyType() !== 'id' ? [$this->getKeyName()] : [];
    }

    public function newUniqueId(): string
    {
        return $this->docketKeyType() === 'uuid' ? (string) Str::uuid() : (string) Str::ulid();
    }

    /**
     * Whether $value could be this model's primary key. Comparing a reference or a
     * slug with an integer or native uuid key column is an error on Postgres.
     */
    public function couldBeKey(mixed $value): bool
    {
        return match ($this->docketKeyType()) {
            'uuid' => Str::isUuid((string) $value),
            'ulid' => Str::isUlid((string) $value),
            default => ctype_digit((string) $value),
        };
    }

    protected function docketKeyType(): string
    {
        return (string) config('docket.key_type', 'ulid');
    }
}
