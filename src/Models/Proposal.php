<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Database\Factories\ProposalFactory;
use BJTheCod3r\Docket\Enums\ApprovalDecision;
use BJTheCod3r\Docket\Enums\ProposalState;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Models\Concerns\ScopesMorphs;
use BJTheCod3r\Docket\Proposals\ProposalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * The side table behind a Report{kind: proposal}: the target, the diff and the snapshot.
 *
 * @property array<string, mixed>|null $changes
 * @property array<string, array<int, array<string, mixed>>>|null $file_changes
 * @property array<string, mixed>|null $original
 * @property string $strategy
 * @property string $state
 * @property int $required_approvals
 * @property array<string, mixed>|null $applied_snapshot
 * @property array<string, mixed>|null $conflicts
 * @property Carbon|null $applied_at
 * @property Carbon|null $reverted_at
 * @property-read Report $report  Never null: the row is created with its report and cascades on delete.
 */
class Proposal extends Model
{
    use DocketModel;
    use ScopesMorphs;

    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    protected string $docketTable = 'proposals';

    protected $guarded = [];

    protected $casts = [
        'changes' => 'array',
        'file_changes' => 'array',
        'original' => 'array',
        'applied_snapshot' => 'array',
        'conflicts' => 'array',
        'required_approvals' => 'integer',
        'applied_at' => 'datetime',
        'reverted_at' => 'datetime',
    ];

    protected static function newFactory(): ProposalFactory
    {
        return ProposalFactory::new();
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Docket::model('report'), 'report_id');
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<ProposalApproval, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Docket::model('approval'), 'proposal_id')->orderBy('created_at');
    }

    public function state(): ProposalState
    {
        return ProposalState::from($this->state);
    }

    /**
     * Inside the model, `changes` and `original` collide with Eloquent's own
     * protected properties — always go through getAttribute() here.
     *
     * @return array<string, mixed>
     */
    public function proposedChanges(): array
    {
        return (array) $this->getAttribute('changes');
    }

    /** @return array<string, mixed> */
    public function originalSnapshot(): array
    {
        return (array) $this->getAttribute('original');
    }

    public function isTextual(): bool
    {
        return $this->proposedChanges() === [] && empty($this->file_changes);
    }

    public function isApplied(): bool
    {
        return $this->state() === ProposalState::Applied;
    }

    public function approvalCount(): int
    {
        return $this->approvals()
            ->where('decision', ApprovalDecision::Approved)
            ->get(['approver_type', 'approver_id'])
            ->unique(fn (ProposalApproval $approval) => $approval->approver_type.'|'.$approval->approver_id)
            ->count();
    }

    public function hasRequiredApprovals(): bool
    {
        return $this->approvalCount() >= $this->required_approvals;
    }

    public function approve(?Model $by = null, ?string $comment = null): Proposal
    {
        return $this->service()->approve($this, $by, $comment);
    }

    public function reject(?Model $by = null, ?string $comment = null): Proposal
    {
        return $this->service()->reject($this, $by, $comment);
    }

    public function withdraw(?Model $by = null): Proposal
    {
        return $this->service()->withdraw($this, $by);
    }

    /**
     * Apply to the target. Returns false (and moves to `conflicted`) when the
     * target drifted since the proposal was made.
     */
    public function apply(?Model $by = null): bool
    {
        return $this->service()->apply($this, $by);
    }

    /**
     * Apply even if the target drifted, overwriting the drift.
     */
    public function forceApply(?Model $by = null): bool
    {
        return $this->service()->apply($this, $by, force: true);
    }

    public function revert(?Model $by = null): Proposal
    {
        return $this->service()->revert($this, $by);
    }

    /**
     * Re-snapshot against the target's current state and drop attributes that no longer differ.
     */
    public function rebase(): Proposal
    {
        return $this->service()->rebase($this);
    }

    /**
     * @return array<string, array{from: mixed, to: mixed, changed: bool}>
     */
    public function diff(): array
    {
        return $this->service()->diff($this);
    }

    /**
     * @return array<string, array{original: mixed, current: mixed, proposed: mixed}>
     */
    public function detectConflicts(): array
    {
        return $this->service()->detectConflicts($this);
    }

    public function hasConflicts(): bool
    {
        return $this->detectConflicts() !== [];
    }

    protected function service(): ProposalService
    {
        return Docket::proposals();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('state', ProposalState::pending());
    }
}
