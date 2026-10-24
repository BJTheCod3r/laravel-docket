<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests\Fixtures;

use BJTheCod3r\Docket\Concerns\IsReportable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Order extends Model
{
    use IsReportable;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
