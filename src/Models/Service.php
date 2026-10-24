<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Concerns\IsReportable;
use BJTheCod3r\Docket\Concerns\Subscribable;
use BJTheCod3r\Docket\Database\Factories\ServiceFactory;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Suppression\MuteBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $key
 * @property string $title
 * @property string|null $description
 * @property string|null $email
 * @property bool $is_active
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property array<string, mixed>|null $meta
 */
class Service extends Model
{
    use DocketModel;

    /** @use HasFactory<ServiceFactory> */
    use HasFactory;
    use IsReportable;
    use SoftDeletes;
    use Subscribable;

    protected string $docketTable = 'services';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Reports that implicate this service (many-to-many), distinct from
     * reports *about* it, which live on subjectReports().
     *
     * @return BelongsToMany<Report, $this>
     */
    public function reports(): BelongsToMany
    {
        return $this->belongsToMany(
            Docket::model('report'),
            Docket::table('report_service'),
            'service_id',
            'report_id',
        )->withPivot('role')->withTimestamps();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Sugar for a maintenance window: drop notifications about this service for a while.
     */
    public function maintenance(?int $hours = null, ?int $minutes = null, ?string $reason = null, ?Model $by = null): MuteBuilder
    {
        $builder = Docket::mute($this)->reason($reason);

        if ($by !== null) {
            $builder->by($by);
        }

        if ($hours !== null || $minutes !== null) {
            $builder->for(hours: $hours ?? 0, minutes: $minutes ?? 0);
        }

        return $builder;
    }

    public function inMaintenance(): bool
    {
        return Docket::activeMute($this) !== null;
    }
}
