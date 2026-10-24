<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Models\Concerns\ScopesMorphs;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $subscribable_type
 * @property string|null $subscribable_id
 * @property array<int, string>|null $events   null = all
 * @property array<int, string>|null $channels null = user preference
 * @property array<int, string>|null $kinds    null = all kinds
 * @property array<int, string>|null $except_kinds
 * @property Carbon|null $muted_until
 */
class Subscription extends Model
{
    use DocketModel;
    use ScopesMorphs;

    /** Pseudo morph type for "every report of this kind". */
    public const KIND_TYPE = 'docket:kind';

    protected string $docketTable = 'subscriptions';

    protected $guarded = [];

    protected $casts = [
        'events' => 'array',
        'channels' => 'array',
        'kinds' => 'array',
        'except_kinds' => 'array',
        'muted_until' => 'datetime',
    ];

    /** @return MorphTo<Model, $this> */
    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isMuted(): bool
    {
        return $this->muted_until !== null && $this->muted_until->isFuture();
    }

    public function wantsEvent(string $eventKey): bool
    {
        return $this->events === null || in_array($eventKey, $this->events, true);
    }

    public function wantsKind(string $kind): bool
    {
        if ($this->except_kinds !== null && in_array($kind, $this->except_kinds, true)) {
            return false;
        }

        return $this->kinds === null || in_array($kind, $this->kinds, true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, Model $subscribable): Builder
    {
        return $query->whereMorph('subscribable', $subscribable, stringKey: true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForKind(Builder $query, string $kind): Builder
    {
        return $query->where('subscribable_type', self::KIND_TYPE)->where('subscribable_id', $kind);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBy(Builder $query, Model $subscriber): Builder
    {
        return $query->whereMorph('subscriber', $subscriber, stringKey: StringKeyedMorphMany::morphKeysAreStrings());
    }
}
