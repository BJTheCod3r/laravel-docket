<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Support;

use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Exceptions\InvalidKindException;
use Illuminate\Support\Collection;

class KindRegistry
{
    /** @var array<string, Kind> */
    protected array $kinds = [];

    protected bool $booted = false;

    /** @var array<string, string> */
    protected const SHIPPED_ROLLUPS = [
        ReportKind::Comment->value => 'none',
        ReportKind::Resolution->value => 'latest_child',
        ReportKind::Proposal->value => 'none',
    ];

    /**
     * @param  array<string, mixed>  $options  label, rollup, default_status, default_visibility, notifies
     */
    public function register(string $key, array $options = []): Kind
    {
        $this->boot();

        return $this->kinds[$key] = Kind::fromArray($key, $options + ['rollup' => self::SHIPPED_ROLLUPS[$key] ?? null]);
    }

    public function has(string $key): bool
    {
        $this->boot();

        return isset($this->kinds[$key]);
    }

    public function find(string $key): ?Kind
    {
        $this->boot();

        return $this->kinds[$key] ?? null;
    }

    public function get(string $key): Kind
    {
        return $this->find($key) ?? throw new InvalidKindException("Report kind [{$key}] is not registered.");
    }

    /** @return Collection<string, Kind> */
    public function all(): Collection
    {
        $this->boot();

        return new Collection($this->kinds);
    }

    protected function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->booted = true;

        $this->kinds = collect(ReportKind::cases())
            ->mapWithKeys(fn (ReportKind $case) => [$case->value => []])
            ->merge((array) config('docket.kinds', []))
            ->mapWithKeys(fn ($options, $key) => [$key => Kind::fromArray((string) $key, (array) $options + ['rollup' => self::SHIPPED_ROLLUPS[$key] ?? null])])
            ->all();
    }
}
