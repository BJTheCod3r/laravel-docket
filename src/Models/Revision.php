<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property array<string, array{from: mixed, to: mixed}> $changes
 * @property string|null $reason
 */
class Revision extends Model
{
    use DocketModel;

    public const UPDATED_AT = null;

    protected string $docketTable = 'revisions';

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Docket::model('report'), 'report_id');
    }

    /** @return MorphTo<Model, $this> */
    public function editor(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<int, string> */
    public function changedFields(): array
    {
        // Eloquent declares its own protected $changes property, so read the attribute explicitly.
        return array_map(strval(...), array_keys((array) $this->getAttribute('changes')));
    }
}
