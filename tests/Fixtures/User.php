<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests\Fixtures;

use BJTheCod3r\Docket\Concerns\FilesReports;
use BJTheCod3r\Docket\Concerns\HasAttachments;
use BJTheCod3r\Docket\Concerns\HasProposals;
use BJTheCod3r\Docket\Contracts\Proposable;
use BJTheCod3r\Docket\Concerns\ReceivesReports;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements Proposable
{
    use FilesReports;
    use HasAttachments;
    use HasProposals;
    use Notifiable;
    use ReceivesReports;

    protected $guarded = [];

    /** @var array<int, string> */
    protected array $proposable = ['name', 'bio'];

    /** @var array<string, array<string, mixed>> */
    protected array $proposableFiles = [
        'avatar' => ['multiple' => false, 'mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max_kb' => 2048],
    ];

    /** @var array<int, string>|null Set in tests to exercise the model-method channel fallback. */
    public ?array $preferredChannels = null;

    public ?string $timezone = null;

    /** @return array<int, string>|null */
    public function preferredDocketChannels(string $eventKey): ?array
    {
        return $this->preferredChannels;
    }

    public function preferredDocketTimezone(): ?string
    {
        return $this->timezone;
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return array<int, Team> */
    public function docketMuteGroups(): array
    {
        return array_filter([$this->team]);
    }
}
