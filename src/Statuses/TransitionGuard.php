<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Statuses;

use BJTheCod3r\Docket\Models\Report;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Closures registered with Docket::guard(). Each runs after the transition
 * map has been consulted; returning false or a string message vetoes.
 */
class TransitionGuard
{
    /** @var array<int, array{to: ?string, from: ?string, using: Closure}> */
    protected array $guards = [];

    public function add(Closure $using, ?string $to = null, ?string $from = null): static
    {
        $this->guards[] = ['to' => $to, 'from' => $from, 'using' => $using];

        return $this;
    }

    /**
     * @return true|string  true when allowed, otherwise the veto message.
     */
    public function check(Report $report, string $to, ?Model $actor = null): true|string
    {
        // Lazy so guards after the first veto never run.
        $veto = LazyCollection::make($this->guards)
            ->filter(fn (array $guard) => ($guard['to'] === null || $guard['to'] === $to)
                && ($guard['from'] === null || $guard['from'] === $report->status))
            ->map(fn (array $guard) => ($guard['using'])($report, $actor, $to))
            ->first(fn ($result) => $result === false || is_string($result));

        return match (true) {
            $veto === false => "Transition to [{$to}] was vetoed by a guard.",
            is_string($veto) => $veto,
            default => true,
        };
    }
}
