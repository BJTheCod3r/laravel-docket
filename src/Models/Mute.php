<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Enums\MuteBehaviour;
use BJTheCod3r\Docket\Enums\MuteDirection;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Models\Concerns\ScopesMorphs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $direction
 * @property array<int, string>|null $event_keys
 * @property array<int, string>|null $kinds
 * @property array<int, string>|null $channels
 * @property int|null $min_priority  Reports with priority <= this (more urgent) punch through
 * @property string $behaviour
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property string|null $reason
 * @property string|null $scope_type
 * @property int|string|null $scope_id
 */
class Mute extends Model
{
    use DocketModel;
    use ScopesMorphs;

    protected string $docketTable = 'mutes';

    protected $guarded = [];

    protected $casts = [
        'event_keys' => 'array',
        'kinds' => 'array',
        'channels' => 'array',
        'min_priority' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function direction(): MuteDirection
    {
        return MuteDirection::from($this->direction);
    }

    public function behaviour(): MuteBehaviour
    {
        return MuteBehaviour::from($this->behaviour);
    }

    public function isGlobal(): bool
    {
        return $this->scope_type === null;
    }

    public function isActive(?Carbon $at = null): bool
    {
        $at ??= Carbon::now();

        return $this->starts_at->lte($at) && ($this->ends_at === null || $this->ends_at->gt($at));
    }

    public function drops(): bool
    {
        return $this->behaviour() === MuteBehaviour::Drop;
    }

    /**
     * Whether notifications it covers are dropped rather than deferred. An open-ended
     * defer mute has no time to release them at, so it drops too.
     *
     * @phpstan-assert-if-false !null $this->ends_at
     */
    public function suppresses(): bool
    {
        return $this->drops() || $this->ends_at === null;
    }

    public function covers(string $eventKey, ?string $kind = null, ?int $priority = null, ?string $channel = null): bool
    {
        if ($this->event_keys !== null && ! in_array($eventKey, $this->event_keys, true)) {
            return false;
        }

        if ($kind !== null && $this->kinds !== null && ! in_array($kind, $this->kinds, true)) {
            return false;
        }

        if ($channel !== null && $this->channels !== null && ! in_array($channel, $this->channels, true)) {
            return false;
        }

        if ($priority !== null && $this->min_priority !== null && $priority <= $this->min_priority) {
            return false; // urgent enough to punch through
        }

        return true;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query, ?Carbon $at = null): Builder
    {
        $at ??= Carbon::now();

        return $query
            ->where('starts_at', '<=', $at)
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDirection(Builder $query, MuteDirection|string $direction): Builder
    {
        return $query->where('direction', $direction instanceof MuteDirection ? $direction->value : $direction);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeScopedTo(Builder $query, ?Model $scope): Builder
    {
        return $query->whereMorph('scope', $scope, stringKey: true);
    }
}
