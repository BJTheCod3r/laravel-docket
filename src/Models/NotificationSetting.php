<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Models\Concerns\ScopesMorphs;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/**
 * @property string $notifiable_type
 * @property string|int $notifiable_id
 * @property string|null $timezone
 * @property string|null $quiet_from  HH:MM
 * @property string|null $quiet_to    HH:MM
 * @property array<int, string>|null $quiet_channels null = all
 * @property bool|null $digest_enabled
 * @property int|null $digest_window_minutes
 * @property array<string, array<string, bool>>|null $preferences  event key => channel => enabled
 */
class NotificationSetting extends Model
{
    use DocketModel;
    use ScopesMorphs;

    protected string $docketTable = 'notification_settings';

    protected $guarded = [];

    protected $casts = [
        'quiet_channels' => 'array',
        'digest_enabled' => 'boolean',
        'digest_window_minutes' => 'integer',
        'preferences' => 'array',
    ];

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Channel => enabled for the event: '*' first, then patterns like "docket.proposal.*",
     * then the exact key, each overriding the last for the channels it names.
     *
     * @return array<string, bool>
     */
    public function preferencesFor(string $eventKey): array
    {
        $preferences = collect($this->preferences ?? []);

        return array_merge(
            $preferences->get('*', []),
            ...$preferences->filter(fn ($channels, string $pattern) => $pattern !== '*' && $pattern !== $eventKey && Str::is($pattern, $eventKey))->values()->all(),
            ...[$preferences->get($eventKey, [])],
        );
    }

    public function hasQuietHours(): bool
    {
        return $this->quiet_from !== null && $this->quiet_to !== null;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, Model $notifiable): Builder
    {
        return $query->whereMorph('notifiable', $notifiable, stringKey: StringKeyedMorphMany::morphKeysAreStrings());
    }
}
