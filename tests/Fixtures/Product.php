<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests\Fixtures;

use BJTheCod3r\Docket\Concerns\HasProposals;
use BJTheCod3r\Docket\Contracts\Proposable;
use BJTheCod3r\Docket\Concerns\IsReportable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model implements Proposable
{
    use HasProposals;
    use IsReportable;

    protected $guarded = [];

    protected $casts = ['tags' => 'array', 'published_at' => 'datetime'];

    /** @var array<int, string> */
    protected array $proposable = ['price', 'description', 'tags', 'published_at'];

    /** @var array<int, Model>|null */
    public static ?array $approvers = null;

    /** @return BelongsTo<Team, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id');
    }

    public function proposalRules(): array
    {
        return ['price' => ['integer', 'min:0'], 'description' => ['string', 'max:2000']];
    }

    public function proposalApprovers(): ?iterable
    {
        return static::$approvers;
    }
}
