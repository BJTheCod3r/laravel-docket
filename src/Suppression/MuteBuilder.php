<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Suppression;

use BJTheCod3r\Docket\Enums\MuteBehaviour;
use BJTheCod3r\Docket\Enums\MuteDirection;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Mute;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Fluent wrapper around a Mute row. The row is created immediately (open-ended)
 * and each chained call updates it, so `Docket::mute($service)->for(hours: 2)`
 * needs no terminal call.
 */
class MuteBuilder
{
    final protected function __construct(protected Mute $mute) {}

    public static function about(?Model $scope = null): static
    {
        return static::create($scope, MuteDirection::About);
    }

    public static function deliveredTo(Model $scope): static
    {
        return static::create($scope, MuteDirection::For);
    }

    protected static function create(?Model $scope, MuteDirection $direction): static
    {
        /** @var class-string<Mute> $class */
        $class = Docket::model('mute');

        $mute = $class::query()->create([
            'direction' => $direction->value,
            'scope_type' => $scope?->getMorphClass(),
            'scope_id' => $scope?->getKey(),
            'behaviour' => config('docket.notifications.suppression.default_behaviour', MuteBehaviour::Drop->value),
            'min_priority' => config('docket.notifications.suppression.min_priority_bypass'),
            'starts_at' => Carbon::now(),
            'ends_at' => null,
        ]);

        return new static($mute);
    }

    public function direction(MuteDirection|string $direction): static
    {
        return $this->set(['direction' => $direction instanceof MuteDirection ? $direction->value : $direction]);
    }

    public function for(int $hours = 0, int $minutes = 0, int $days = 0, int $seconds = 0): static
    {
        return $this->set(['ends_at' => Carbon::now()->addDays($days)->addHours($hours)->addMinutes($minutes)->addSeconds($seconds)]);
    }

    public function until(DateTimeInterface|string $end): static
    {
        return $this->set(['ends_at' => Carbon::parse($end)]);
    }

    public function between(DateTimeInterface|string $start, DateTimeInterface|string|null $end): static
    {
        return $this->set([
            'starts_at' => Carbon::parse($start),
            'ends_at' => $end === null ? null : Carbon::parse($end),
        ]);
    }

    public function deferring(): static
    {
        return $this->set(['behaviour' => MuteBehaviour::Defer->value]);
    }

    public function reason(?string $reason): static
    {
        return $this->set(['reason' => $reason]);
    }

    /** @param  array<int, string>|null  $eventKeys */
    public function events(?array $eventKeys): static
    {
        return $this->set(['event_keys' => $eventKeys]);
    }

    /** @param  array<int, string>|null  $kinds */
    public function kinds(?array $kinds): static
    {
        return $this->set(['kinds' => $kinds]);
    }

    /** @param  array<int, string>|null  $channels */
    public function channels(?array $channels): static
    {
        return $this->set(['channels' => $channels]);
    }

    /**
     * Reports with priority <= $priority (more urgent) punch through. Null = nothing punches through.
     */
    public function minPriority(?int $priority): static
    {
        return $this->set(['min_priority' => $priority]);
    }

    public function by(?Model $creator): static
    {
        return $this->set([
            'created_by_type' => $creator?->getMorphClass(),
            'created_by_id' => $creator?->getKey(),
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function set(array $attributes): static
    {
        $this->mute->forceFill($attributes)->save();

        return $this;
    }
}
