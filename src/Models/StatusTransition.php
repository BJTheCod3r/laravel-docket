<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string|null $from
 * @property string $to
 * @property string|null $from_category
 * @property string $to_category
 * @property string|null $reason
 * @property array<string, mixed>|null $meta
 * @property bool $via_rollup
 */
class StatusTransition extends Model
{
    use DocketModel;

    public const UPDATED_AT = null;

    protected string $docketTable = 'status_transitions';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'via_rollup' => 'boolean',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Docket::model('report'), 'report_id');
    }

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
