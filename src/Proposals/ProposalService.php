<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Proposals;

use BJTheCod3r\Docket\Contracts\Proposable;
use BJTheCod3r\Docket\Enums\ApprovalDecision;
use BJTheCod3r\Docket\Enums\AttachmentState;
use BJTheCod3r\Docket\Enums\FileOperation;
use BJTheCod3r\Docket\Enums\ProposalState;
use BJTheCod3r\Docket\Enums\ProposalStrategy;
use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Events\ProposalApplied;
use BJTheCod3r\Docket\Events\ProposalApproved;
use BJTheCod3r\Docket\Events\ProposalConflicted;
use BJTheCod3r\Docket\Events\ProposalRejected;
use BJTheCod3r\Docket\Events\ProposalReverted;
use BJTheCod3r\Docket\Events\ProposalSubmitted;
use BJTheCod3r\Docket\Events\ProposalWithdrawn;
use BJTheCod3r\Docket\Exceptions\InvalidProposalException;
use BJTheCod3r\Docket\Exceptions\NotProposableException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Jobs\ApplyProposal;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Models\Proposal;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Statuses\CoreStatus;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

/**
 * Every state change locks the proposal row first and re-checks its state, so two
 * workers (or a stale model instance) can never apply, revert or decide it twice.
 * Proposal events implement ShouldDispatchAfterCommit, so listeners only see committed state.
 */
class ProposalService
{
    public function __construct(
        protected ConflictDetector $conflicts,
        protected FileChangeApplier $files,
    ) {}

    /**
     * @param  array<string, mixed>|null  $changes
     * @param  array<string, mixed>|null  $files
     */
    public function propose(
        Model $target,
        ?array $changes = null,
        ?array $files = null,
        ?Model $by = null,
        ?string $reason = null,
        ?string $title = null,
        ?int $requiredApprovals = null,
        ProposalStrategy|string|null $strategy = null,
    ): Proposal {
        if (! config('docket.proposals.enabled', true)) {
            throw new InvalidProposalException('Change management is disabled (docket.proposals.enabled).');
        }

        $changes ??= [];
        $files ??= [];
        $strategy = $strategy instanceof ProposalStrategy ? $strategy : (ProposalStrategy::tryFrom($strategy ?? ProposalStrategy::Patch->value)
            ?? throw new InvalidProposalException("Proposal strategy [{$strategy}] must be patch or replace."));

        $this->ensureProposable($target, $changes, $files);
        $this->validate($target, $changes);

        $original = $this->snapshot($target, array_keys($changes), array_keys($files));

        /** @var class-string<Proposal> $class */
        $class = Docket::model('proposal');

        $proposal = (new $class)->getConnection()->transaction(function () use ($class, $target, $changes, $files, $by, $reason, $title, $requiredApprovals, $strategy, $original) {
            $report = Docket::report()
                ->about($target)
                ->from($by)
                ->kind(ReportKind::Proposal)
                ->title($title ?? 'Proposed changes to '.class_basename($target).' #'.$target->getKey())
                ->body($reason)
                ->create();

            return $class::query()->create([
                'report_id' => $report->getKey(),
                'target_type' => $target->getMorphClass(),
                'target_id' => $target->getKey(),
                'changes' => $changes ?: null,
                'file_changes' => $files ? $this->stage($target, $report, $files, $original) : null,
                'original' => $original ?: null,
                'strategy' => $strategy->value,
                'state' => ProposalState::Submitted->value,
                'required_approvals' => $requiredApprovals ?? (int) config('docket.proposals.required_approvals', 1),
            ])->setRelation('report', $report);
        });

        Docket::events()->dispatch(ProposalSubmitted::class, $proposal, $by);

        return $proposal;
    }

    public function approve(Proposal $proposal, ?Model $by = null, ?string $comment = null): Proposal
    {
        $approved = $this->transaction($proposal, function () use ($proposal, $by, $comment) {
            $this->ensureState($proposal, ProposalState::pending(), 'approve');
            $this->recordDecision($proposal, $by, ApprovalDecision::Approved, $comment);

            if ($proposal->state() !== ProposalState::Submitted || ! $proposal->hasRequiredApprovals()) {
                return false;
            }

            $proposal->forceFill(['state' => ProposalState::Approved->value])->save();
            $proposal->report->tryTransitionTo(CoreStatus::InProgress, by: $by, reason: 'Proposal approved', authorize: false);

            return true;
        });

        if (! $approved) {
            return $proposal;
        }

        Docket::events()->dispatch(ProposalApproved::class, $proposal, $by);

        if (! config('docket.proposals.auto_apply_on_approval', true)) {
            return $proposal;
        }

        if (config('docket.proposals.queue')) {
            ApplyProposal::dispatch($proposal, $by);

            // A sync queue has already applied it on a copy of the model.
            return $proposal->refresh();
        }

        $this->apply($proposal, $by);

        return $proposal;
    }

    public function reject(Proposal $proposal, ?Model $by = null, ?string $comment = null): Proposal
    {
        $this->transaction($proposal, function () use ($proposal, $by, $comment) {
            $this->ensureState($proposal, ProposalState::pending(), 'reject');
            $this->recordDecision($proposal, $by, ApprovalDecision::Rejected, $comment);

            $proposal->forceFill(['state' => ProposalState::Rejected->value])->save();
            $proposal->report->tryTransitionTo(CoreStatus::Closed, by: $by, reason: $comment ?? 'Proposal rejected', authorize: false);
        });

        Docket::events()->dispatch(ProposalRejected::class, $proposal, $by);

        return $proposal;
    }

    public function withdraw(Proposal $proposal, ?Model $by = null): Proposal
    {
        $this->ensureAllowed($proposal, $by, 'withdraw');

        $this->transaction($proposal, function () use ($proposal, $by) {
            $this->ensureState($proposal, ProposalState::pending(), 'withdraw');

            $proposal->forceFill(['state' => ProposalState::Withdrawn->value])->save();
            $proposal->report->tryTransitionTo(CoreStatus::Closed, by: $by, reason: 'Proposal withdrawn', authorize: false);
        });

        Docket::events()->dispatch(ProposalWithdrawn::class, $proposal, $by);

        return $proposal;
    }

    /**
     * Apply to the target. With $force, a drifted target is overwritten instead of
     * blocking the proposal.
     *
     * @return bool  false when blocked by a conflict (the proposal is now `conflicted`).
     */
    public function apply(Proposal $proposal, ?Model $by = null, bool $force = false): bool
    {
        if ($force) {
            $this->ensureAllowed($proposal, $by, 'forceApply');
        }

        $conflicts = $this->transaction($proposal, function () use ($proposal, $by, $force) {
            $this->ensureState($proposal, ProposalState::pending(), 'apply');

            throw_unless(
                $proposal->state() === ProposalState::Approved || $proposal->hasRequiredApprovals(),
                InvalidProposalException::class,
                sprintf('Proposal [%s] needs %d approval(s) before it can be applied.', $proposal->getKey(), $proposal->required_approvals),
            );

            // Lock the target so nothing can land between the conflict check and the write.
            $target = $this->freshTarget($proposal, lock: true);

            if (! $force && ($conflicts = $this->conflicts->detect($proposal, $target)) !== []) {
                $proposal->forceFill(['state' => ProposalState::Conflicted->value, 'conflicts' => $conflicts])->save();

                return $conflicts;
            }

            $changes = $proposal->proposedChanges();
            $this->validate($target, $changes);

            $proposal->forceFill([
                'state' => ProposalState::Applied->value,
                'applied_snapshot' => [
                    'attributes' => $changes ? Docket::applierFor($target)->apply($target, $changes, $proposal) : [],
                    'files' => $proposal->file_changes ? $this->files->apply($target, $proposal->file_changes) : [],
                ],
                'conflicts' => null,
                'applied_at' => now(),
            ])->save();

            $proposal->report->tryTransitionTo(CoreStatus::Closed, by: $by, reason: 'Proposal applied', authorize: false);

            return [];
        });

        if ($conflicts !== []) {
            Docket::events()->dispatch(ProposalConflicted::class, $proposal, $conflicts, $by);

            return false;
        }

        Docket::events()->dispatch(ProposalApplied::class, $proposal, $by);

        return true;
    }

    public function revert(Proposal $proposal, ?Model $by = null): Proposal
    {
        $this->ensureAllowed($proposal, $by, 'revert');

        $this->transaction($proposal, function () use ($proposal, $by) {
            $this->ensureState($proposal, [ProposalState::Applied], 'revert');

            $target = $this->freshTarget($proposal, lock: true);
            $snapshot = $proposal->applied_snapshot ?? [];

            if (! empty($snapshot['attributes'])) {
                Docket::applierFor($target)->revert($target, $snapshot['attributes'], $proposal);
            }

            if (! empty($snapshot['files'])) {
                $this->files->revert($target, $snapshot['files']);
            }

            $proposal->forceFill([
                'state' => ProposalState::Reverted->value,
                'reverted_at' => now(),
            ])->save();

            $proposal->report->tryTransitionTo(CoreStatus::Reopened, by: $by, reason: 'Proposal reverted', authorize: false);
        });

        Docket::events()->dispatch(ProposalReverted::class, $proposal, $by);

        return $proposal;
    }

    public function rebase(Proposal $proposal): Proposal
    {
        return $this->transaction($proposal, function () use ($proposal) {
            $this->ensureState($proposal, ProposalState::pending(), 'rebase');

            $target = $this->freshTarget($proposal);

            $changes = collect($proposal->proposedChanges())
                ->reject(fn ($proposed, string $attribute) => $this->conflicts->same($target->getAttribute($attribute), $proposed));

            $original = $this->snapshot($target, $changes->keys()->all(), array_keys($proposal->file_changes ?? []));

            $proposal->forceFill([
                'changes' => $changes->all() ?: null,
                'original' => $original ?: null,
                'conflicts' => null,
                'state' => $proposal->hasRequiredApprovals() ? ProposalState::Approved->value : ProposalState::Submitted->value,
            ])->save();

            return $proposal;
        });
    }

    /**
     * @return array<string, array{from: mixed, to: mixed, changed: bool}>
     */
    public function diff(Proposal $proposal): array
    {
        $original = $proposal->originalSnapshot();

        return collect($proposal->proposedChanges())
            ->map(fn ($to, string $attribute) => [
                'from' => $from = $original[$attribute] ?? null,
                'to' => $to,
                'changed' => ! $this->conflicts->same($from, $to),
            ])
            ->merge(collect($proposal->file_changes ?? [])
                ->map(fn ($operations, string $field) => ['from' => $original[$field] ?? null, 'to' => $operations, 'changed' => true]))
            ->all();
    }

    /**
     * @return array<string, array{original: mixed, current: mixed, proposed: mixed}>
     */
    public function detectConflicts(Proposal $proposal): array
    {
        return $this->conflicts->detect($proposal);
    }

    /**
     * Run a state change on the proposal's connection, holding a lock on its row
     * and with its attributes reloaded, so the state checks see the committed state.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function transaction(Proposal $proposal, Closure $callback): mixed
    {
        return $proposal->getConnection()->transaction(function () use ($proposal, $callback) {
            $current = $proposal->newQueryWithoutScopes()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
            $proposal->setRawAttributes($current->getAttributes(), sync: true);

            return $callback();
        });
    }

    /**
     * @param  array<int, string>  $attributes
     * @param  array<int, string>  $fileFields
     * @return array<string, mixed>
     */
    protected function snapshot(Model $target, array $attributes, array $fileFields): array
    {
        return collect($target->only($attributes))
            ->merge(collect($fileFields)->mapWithKeys(fn (string $field) => [$field => $this->conflicts->snapshotFiles($target, $field)]))
            ->all();
    }

    /**
     * @return Model&Proposable
     */
    protected function freshTarget(Proposal $proposal, bool $lock = false): Model
    {
        $target = $proposal->target()->when($lock, fn ($query) => $query->lockForUpdate())->first()
            ?? throw new InvalidProposalException('The proposal target no longer exists.');

        throw_unless($target instanceof Proposable, NotProposableException::class, sprintf('[%s] does not implement Proposable.', $target::class));

        $proposal->setRelation('target', $target);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $files
     * @param  array<string, mixed>  $original  The snapshot already taken, reused for the file counts.
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function stage(Model&Proposable $target, Report $report, array $files, array $original): array
    {
        $specs = $target->proposableFiles();

        return collect($files)
            ->map(fn ($value, string $field) => $this->stageField($report, $field, $value, (array) ($specs[$field] ?? []), $this->currentAttachmentIds($original[$field] ?? null)))
            ->all();
    }

    /**
     * The ids of the files a field holds now, from its snapshot (one file, or a list).
     *
     * @return array<int, string>
     */
    protected function currentAttachmentIds(mixed $snapshot): array
    {
        $files = is_array($snapshot) && array_key_exists('checksum', $snapshot) ? [$snapshot] : (array) $snapshot;

        return collect($files)->pluck('attachment_id')->filter()->map(fn ($id) => (string) $id)->values()->all();
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<int, string>  $current  Ids of the files the field holds now.
     * @return array<int, array<string, mixed>>
     */
    protected function stageField(Report $report, string $field, mixed $value, array $spec, array $current): array
    {
        $multiple = (bool) ($spec['multiple'] ?? false);

        $operations = $multiple || (is_array($value) && isset($value[0]['op']))
            ? $value
            : [['op' => FileOperation::Replace, 'file' => $value]];

        throw_unless(is_array($operations), InvalidProposalException::class, "File field [{$field}] expects a file or a list of operations.");

        $operations = collect($operations)->map(function (array $operation) use ($field) {
            $op = $operation['op'] ?? FileOperation::Replace;

            $operation['op'] = $op instanceof FileOperation ? $op : (FileOperation::tryFrom((string) $op)
                ?? throw new InvalidProposalException("Unknown file operation [{$op}] for [{$field}]."));

            return $operation;
        });

        if ($multiple && isset($spec['max'])) {
            $resulting = count($current) + $operations->where('op', FileOperation::Add)->count() - $operations->where('op', FileOperation::Remove)->count();

            throw_if($resulting > (int) $spec['max'], InvalidProposalException::class, "File field [{$field}] allows at most {$spec['max']} files.");
        }

        return $operations->map(fn (array $operation) => match ($operation['op']) {
            FileOperation::Replace, FileOperation::Add => [
                'op' => $operation['op']->value,
                'attachment_id' => $this->stageFile($operation['file'] ?? null, $report, $field, $spec)->getKey(),
                'target_collection' => $field,
            ],
            FileOperation::Remove => [
                'op' => FileOperation::Remove->value,
                'attachment_id' => $this->ensureRemovable($operation, $field, $current),
                'target_collection' => $field,
            ],
            FileOperation::Reorder => [
                'op' => FileOperation::Reorder->value,
                'order' => collect((array) ($operation['order'] ?? []))->map(fn ($id) => $id instanceof Model ? $id->getKey() : $id)->all(),
                'target_collection' => $field,
            ],
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<int, string>  $current
     */
    protected function ensureRemovable(array $operation, string $field, array $current): string|int
    {
        $id = ($operation['attachment'] ?? null) instanceof Attachment
            ? $operation['attachment']->getKey()
            : ($operation['attachment_id'] ?? $operation['attachment'] ?? null);

        throw_unless(
            (is_string($id) || is_int($id)) && in_array((string) $id, $current, true),
            InvalidProposalException::class,
            "Attachment [{$id}] is not a current file of [{$field}].",
        );

        return $id;
    }

    /** @param  array<string, mixed>  $spec */
    protected function stageFile(mixed $file, Report $report, string $field, array $spec): Attachment
    {
        throw_unless(
            $file instanceof UploadedFile || is_string($file),
            InvalidProposalException::class,
            "File field [{$field}] requires an UploadedFile or a local path.",
        );

        return Docket::attachments()->store($file, $report, "proposed:{$field}", [
            'disk' => config('docket.proposals.staging_disk') ?: config('docket.attachments.disk', 'local'),
            'state' => AttachmentState::Staged->value,
            'mimes' => $spec['mimes'] ?? null,
            'max_kb' => $spec['max_kb'] ?? null,
            'variants' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $files
     *
     * @phpstan-assert Proposable $target
     */
    protected function ensureProposable(Model $target, array $changes, array $files): void
    {
        throw_unless($target instanceof Proposable, NotProposableException::class, sprintf('[%s] does not implement Proposable (use the HasProposals trait).', $target::class));

        $attribute = collect($changes)->keys()->diff($target->proposableAttributes())->first();
        throw_if($attribute !== null, NotProposableException::class, sprintf('[%s] is not a proposable attribute on %s.', $attribute, $target::class));

        $field = collect($files)->keys()->diff(array_keys($target->proposableFiles()))->first();
        throw_if($field !== null, NotProposableException::class, sprintf('[%s] is not a proposable file field on %s.', $field, $target::class));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function validate(Model&Proposable $target, array $changes): void
    {
        $rules = Arr::only($target->proposalRules(), array_keys($changes));

        if ($rules === []) {
            return;
        }

        $validator = Validator::make($changes, $rules);

        if ($validator->fails()) {
            throw new InvalidProposalException('Proposed changes are invalid: '.implode(' ', $validator->errors()->all()));
        }
    }

    protected function recordDecision(Proposal $proposal, ?Model $by, ApprovalDecision $decision, ?string $comment): void
    {
        $this->ensureCanReview($proposal, $by, $decision === ApprovalDecision::Approved ? 'approve' : 'reject');

        $proposal->approvals()->create([
            'approver_type' => $by->getMorphClass(),
            'approver_id' => $by->getKey(),
            'decision' => $decision->value,
            'comment' => $comment,
            'created_at' => now(),
        ]);
    }

    /**
     * @phpstan-assert !null $by
     */
    protected function ensureCanReview(Proposal $proposal, ?Model $by, string $action): void
    {
        throw_if($by === null, InvalidProposalException::class, sprintf(
            'Reviewing proposal [%s] needs a reviewer; pass `by`.',
            $proposal->getKey(),
        ));

        throw_unless(
            Docket::allows($by, $action, $proposal),
            InvalidProposalException::class,
            sprintf('%s #%s is not authorized to %s proposal [%s].', class_basename($by), $by->getKey(), $action, $proposal->getKey()),
        );

        $target = $proposal->target;

        if ($target instanceof Proposable && ($approvers = $target->proposalApprovers()) !== null) {
            throw_unless(
                collect($approvers)->contains(fn (Model $approver) => $approver->is($by)),
                InvalidProposalException::class,
                sprintf('%s #%s is not allowed to review proposals against %s.', class_basename($by), $by->getKey(), class_basename($target)),
            );
        }
    }

    /**
     * Checks a `Proposal` policy method of the same name when one is defined.
     */
    protected function ensureAllowed(Proposal $proposal, ?Model $by, string $ability): void
    {
        throw_unless(
            Docket::allows($by, $ability, $proposal),
            InvalidProposalException::class,
            sprintf('%s #%s is not authorized to %s proposal [%s].', class_basename((string) $by?->getMorphClass()), $by?->getKey(), $ability, $proposal->getKey()),
        );
    }

    /**
     * @param  array<int, ProposalState>  $allowed
     */
    protected function ensureState(Proposal $proposal, array $allowed, string $action): void
    {
        throw_unless(in_array($proposal->state(), $allowed, true), InvalidProposalException::class, sprintf(
            'Cannot %s proposal [%s] while it is [%s].',
            $action,
            $proposal->getKey(),
            $proposal->state,
        ));
    }
}
