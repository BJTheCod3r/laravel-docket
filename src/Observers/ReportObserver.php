<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Observers;

use BJTheCod3r\Docket\Contracts\ReferenceGenerator;
use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Events\ChildReportAdded;
use BJTheCod3r\Docket\Events\ReportAssigned;
use BJTheCod3r\Docket\Events\ReportCreated;
use BJTheCod3r\Docket\Events\ReportEdited;
use BJTheCod3r\Docket\Events\ReportOwnerChanged;
use BJTheCod3r\Docket\Events\ReportUpdated;
use BJTheCod3r\Docket\Exceptions\DirectStatusUpdateException;
use BJTheCod3r\Docket\Exceptions\DocketException;
use BJTheCod3r\Docket\Exceptions\MaxDepthExceededException;
use BJTheCod3r\Docket\Exceptions\ReportLockedException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\MetaIndex;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ReportObserver
{
    /** @var array<int, string> */
    public const REVISIONED = ['title', 'description', 'meta', 'visibility'];

    public function __construct(protected ReferenceGenerator $references) {}

    /**
     * Defaults live here rather than in the builder so Report::create() and factories get them too.
     */
    public function creating(Report $report): void
    {
        $parent = match (true) {
            $report->parent_id === null => null,
            $report->relationLoaded('parent') && $report->parent !== null => $report->parent,
            default => $report->parent()->first(),
        };

        $report->kind ??= config('docket.reports.default_kind', ReportKind::Report->value);
        $kind = Docket::kinds()->find($report->kind);

        $report->visibility ??= $parent->visibility
            ?? $kind->defaultVisibility
            ?? config('docket.reports.default_visibility', Visibility::Internal->value);
        $report->status ??= $kind->defaultStatus ?? Docket::statuses()->default()->key;

        if (empty($report->reference)) {
            $report->reference = $this->references->generate($report);
        }

        if ($report->parent_id !== null) {
            if ($parent !== null) {
                if ($parent->isLocked()) {
                    throw new ReportLockedException("Report [{$parent->reference}] is locked; no further children may be added.");
                }

                $report->depth = $parent->depth + 1;
                $report->root_id = $parent->root_id ?? $parent->getKey();

                $maxDepth = (int) config('docket.reports.max_depth', 5);

                if ($report->depth > $maxDepth) {
                    throw new MaxDepthExceededException("Report nesting exceeds the configured max depth of {$maxDepth}.");
                }
            }
        } else {
            $report->depth = 0;
            $report->root_id = null;
        }

        if (($column = Docket::tenantColumn()) !== null && $report->{$column} === null) {
            $report->{$column} = Docket::currentTenantId();
        }
    }

    public function created(Report $report): void
    {
        $this->syncMetaIndex($report);

        Docket::events()->dispatch(ReportCreated::class, $report, $report->reporter);

        if ($report->parent_id !== null && ($parent = $report->parent) !== null) {
            Docket::events()->dispatch(ChildReportAdded::class, $parent, $report, $report->reporter);
            Docket::rollup()->apply($report, $report->reporter);
        }
    }

    public function updating(Report $report): void
    {
        if ($report->isDirty('status') && ! $report->transitioning) {
            throw new DirectStatusUpdateException(
                'Use $report->transitionTo() to change a report status; direct writes bypass guards, the audit trail and rollup.'
            );
        }

        if ($report->isDirty('parent_id')) {
            throw new DocketException('A report cannot be moved to another parent; root_id and depth are fixed at creation.');
        }

        $report->pendingRevision = collect(self::REVISIONED)
            ->filter(fn (string $field) => $report->isDirty($field))
            ->mapWithKeys(fn (string $field) => [$field => ['from' => $report->getOriginal($field), 'to' => $report->getAttribute($field)]])
            ->all() ?: null;
    }

    public function updated(Report $report): void
    {
        $actor = $report->editContext['by'] ?? $this->authenticatedUser();

        if ($report->pendingRevision !== null) {
            $revision = $report->revisions()->create([
                'editor_type' => $actor?->getMorphClass(),
                'editor_id' => $actor?->getKey(),
                'changes' => $report->pendingRevision,
                'reason' => $report->editContext['reason'] ?? null,
            ]);

            $report->pendingRevision = null;

            if ($report->wasChanged('meta')) {
                $this->syncMetaIndex($report);
            }

            Docket::events()->dispatch(ReportEdited::class, $report, $revision, $actor);
        }

        if ($report->wasChanged(['assignee_type', 'assignee_id'])) {
            Docket::events()->dispatch(
                ReportAssigned::class,
                $report,
                $report->unsetRelation('assignee')->assignee,
                $this->originalMorph($report, 'assignee'),
                $actor,
            );
        }

        if ($report->wasChanged(['owner_type', 'owner_id'])) {
            Docket::events()->dispatch(
                ReportOwnerChanged::class,
                $report,
                $report->unsetRelation('owner')->owner,
                $this->originalMorph($report, 'owner'),
                $actor,
            );
        }

        if (! $report->transitioning) {
            Docket::events()->dispatch(ReportUpdated::class, $report, $actor);
        }
    }

    /**
     * Children go through Eloquent rather than the parent_id foreign key so
     * their attachments, which have no foreign key, take their files with them.
     */
    public function forceDeleting(Report $report): void
    {
        $report->children()->withTrashed()->get()->each->forceDelete();
        $report->attachments()->get()->each->delete();
    }

    public function deleted(Report $report): void
    {
        if (! $report->isForceDeleting()) {
            $report->children()->get()->each->delete();
        }

        if (($parent = $report->parent()->first()) !== null) {
            Docket::rollup()->recalculate($parent, $this->authenticatedUser());
        }
    }

    /**
     * Brings back the replies deleted with this report, not ones deleted before it.
     */
    public function restoring(Report $report): void
    {
        $report->children()->onlyTrashed()->where('deleted_at', '>=', $report->deleted_at)->get()->each->restore();
    }

    public function syncMetaIndex(Report $report): void
    {
        if (! config('docket.meta.index', false)) {
            return;
        }

        $report->metaIndex()->delete();
        $report->metaIndex()->createMany(MetaIndex::rowsFor($report->meta ?? []));
    }

    protected function originalMorph(Report $report, string $relation): ?Model
    {
        $type = $report->getOriginal("{$relation}_type");
        $id = $report->getOriginal("{$relation}_id");

        if ($type === null || $id === null) {
            return null;
        }

        return rescue(fn () => Model::getActualClassNameForMorph($type)::query()->find($id), report: false);
    }

    protected function authenticatedUser(): ?Model
    {
        $user = rescue(fn () => Auth::guard(config('docket.authorization.guard'))->user(), report: false);

        return $user instanceof Model ? $user : null;
    }
}
