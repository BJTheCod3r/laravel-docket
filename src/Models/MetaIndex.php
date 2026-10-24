<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MetaIndex extends Model
{
    use DocketModel;

    public const MAX_STRING_LENGTH = 255;

    public $timestamps = false;

    protected string $docketTable = 'meta_index';

    protected $guarded = [];

    protected $casts = [
        'value_number' => 'float',
        'value_bool' => 'boolean',
    ];

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Docket::model('report'), 'report_id');
    }

    /**
     * Strings longer than the value_string column are indexed by key only.
     *
     * @param  array<string, mixed>  $meta
     * @return array<int, array<string, mixed>>
     */
    public static function rowsFor(array $meta): array
    {
        return collect($meta)
            ->reject(fn ($value) => $value === null || is_array($value) || is_object($value))
            ->map(fn ($value, $key) => [
                'key' => (string) $key,
                'value_string' => is_bool($value) || Str::length((string) $value) > self::MAX_STRING_LENGTH ? null : (string) $value,
                'value_number' => is_numeric($value) && abs((float) $value) < 1e14 ? (float) $value : null,
                'value_bool' => is_bool($value) ? $value : null,
            ])
            ->values()
            ->all();
    }
}
