<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Builders\ReportBuilder;
use BJTheCod3r\Docket\Concerns\HasAttachments;
use BJTheCod3r\Docket\Concerns\Subscribable;
use BJTheCod3r\Docket\Contracts\Status;
use BJTheCod3r\Docket\Database\Factories\ReportFactory;
use BJTheCod3r\Docket\Enums\ReportKind;
use BJTheCod3r\Docket\Enums\StatusCategory;
use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Events\ReportClosed;
use BJTheCod3r\Docket\Events\ReportReopened;
use BJTheCod3r\Docket\Events\ReportResolved;
use BJTheCod3r\Docket\Events\StatusChanged;
use BJTheCod3r\Docket\Exceptions\InvalidTransitionException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Models\Concerns\ScopesMorphs;
use BJTheCod3r\Docket\Observers\ReportObserver;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use BJTheCod3r\Docket\Statuses\StatusDefinition;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * @property int|string $id
 * @property string $reference
 * @property int|string|null $parent_id
 * @property int|string|null $root_id
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property string|null $reporter_type
 * @property int|string|null $reporter_id
 * @property string|null $subject_type
 * @property int|string|null $subject_id
 * @property string|null $assignee_type
 * @property int|string|null $assignee_id
 * @property string|null $tenant_id
 * @property int $depth
 * @property string $kind
 * @property string $visibility
 * @property string $status
 * @property string|null $previous_status
 * @property Carbon|null $status_changed_at
 * @property string|null $title
 * @property string|null $description
 * @property array<string, mixed>|null $meta
 * @property int|null $priority
 * @property Carbon|null $due_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $locked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 */
class Report extends Model
{
    use DocketModel;
    use ScopesMorphs;
    use HasAttachments;
    use Subscribable;

    /** @use HasFactory<ReportFactory> */
    use HasFactory;
    use SoftDeletes;

    protected string $docketTable = 'reports';

    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
        'depth' => 'integer',
        'priority' => 'integer',
        'status_changed_at' => 'datetime',
        'due_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    /**
     * Set by transitionTo() so the observer knows a status change is sanctioned.
     *
     * @internal
     */
    public bool $transitioning = false;

    /**
     * Transient editor context consumed by the observer when writing revisions.
     *
     * @internal
     *
     * @var array{by: ?Model, reason: ?string}|null
     */
    public ?array $editContext = null;

    /**
     * Changes captured in `updating`, written as a Revision in `updated`.
     *
     * @internal
     *
     * @var array<string, array{from: mixed, to: mixed}>|null
     */
    public ?array $pendingRevision = null;

    protected static function newFactory(): ReportFactory
    {
        return ReportFactory::new();
    }

    /**
     * Registered here rather than in the service provider so every subclass
     * (config('docket.models.report')) inherits the observer.
     */
    protected static function booted(): void
    {
        static::observe(ReportObserver::class);

        static::addGlobalScope('docket_tenant', function (Builder $query) {
            if (($column = Docket::tenantColumn()) !== null && ($tenant = Docket::currentTenantId()) !== null) {
                $query->where($query->qualifyColumn($column), $tenant);
            }
        });
    }

    /** @return BelongsTo<static, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(static::class, 'parent_id');
    }

    /** @return HasMany<static, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(static::class, 'parent_id')->orderBy('created_at');
    }

    /** @return BelongsTo<static, $this> */
    public function root(): BelongsTo
    {
        return $this->belongsTo(static::class, 'root_id');
    }

    /**
     * Every report in the thread beneath this root (only meaningful on roots).
     *
     * @return HasMany<static, $this>
     */
    public function descendants(): HasMany
    {
        return $this->hasMany(static::class, 'root_id')->orderBy('depth')->orderBy('created_at');
    }

    /** @return MorphTo<Model, $this> */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function reporter(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function assignee(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsToMany<Service, $this> */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(
            Docket::model('service'),
            Docket::table('report_service'),
            'report_id',
            'service_id',
        )->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<StatusTransition, $this> */
    public function transitions(): HasMany
    {
        return $this->hasMany(Docket::model('transition'), 'report_id')->orderBy('created_at');
    }

    /** @return HasMany<Revision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(Docket::model('revision'), 'report_id')->orderBy('created_at');
    }

    /** @return HasOne<Proposal, $this> */
    public function proposal(): HasOne
    {
        return $this->hasOne(Docket::model('proposal'), 'report_id');
    }

    /** @return HasMany<MetaIndex, $this> */
    public function metaIndex(): HasMany
    {
        return $this->hasMany(Docket::model('meta_index'), 'report_id');
    }

    public function statusCategory(): StatusCategory
    {
        return Docket::statuses()->category($this->status);
    }

    public function isOpen(): bool
    {
        return $this->statusCategory() === StatusCategory::Open;
    }

    public function isActive(): bool
    {
        return $this->statusCategory() === StatusCategory::Active;
    }

    public function isResolved(): bool
    {
        return $this->statusCategory() === StatusCategory::Resolved;
    }

    public function isClosed(): bool
    {
        return $this->statusCategory() === StatusCategory::Closed;
    }

    /** Resolved or closed, unless the status overrides it with `is_terminal`. */
    public function isTerminal(): bool
    {
        return Docket::statuses()->find($this->status)?->isTerminal() ?? false;
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function canTransitionTo(string|Status $to, ?Model $by = null): bool
    {
        return $this->checkTransition($to, $by) === true;
    }

    /**
     * @return true|string  true, or the reason the transition is not allowed.
     */
    public function checkTransition(string|Status $to, ?Model $by = null, bool $viaRollup = false, bool $authorize = true): true|string
    {
        $to = $to instanceof Status ? $to->key() : $to;

        if (! Docket::statuses()->has($to)) {
            return "Status [{$to}] is not registered.";
        }

        if ($to === $this->status) {
            return "Report is already [{$to}].";
        }

        if (! $viaRollup && ! Docket::statuses()->canTransition($this->status, $to)) {
            return "Report [{$this->reference}] cannot transition from [{$this->status}] to [{$to}].";
        }

        // Rollup and other package-driven transitions aren't the actor's own request, so their policy doesn't apply.
        if ($authorize && ! $viaRollup && ! Docket::allows($by, 'transition', $this, [$to])) {
            return "Not authorized to transition report [{$this->reference}] to [{$to}].";
        }

        return Docket::guards()->check($this, $to, $by);
    }

    /**
     * Move the report to a new status. This is the only sanctioned way to change `status`.
     * Pass `authorize: false` when the package or your own code moves it on the actor's behalf.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InvalidTransitionException
     */
    public function transitionTo(
        string|Status $to,
        ?Model $by = null,
        ?string $reason = null,
        array $meta = [],
        bool $viaRollup = false,
        bool $authorize = true,
    ): static {
        $to = $to instanceof Status ? $to->key() : $to;

        $check = $this->checkTransition($to, $by, $viaRollup, $authorize);

        if ($check !== true) {
            throw new InvalidTransitionException($check);
        }

        $from = $this->status;
        $fromDefinition = Docket::statuses()->find($from);
        $fromCategory = $fromDefinition?->category;
        $definition = Docket::statuses()->get($to);
        $now = Carbon::now();

        $this->getConnection()->transaction(function () use ($to, $from, $fromCategory, $definition, $by, $reason, $meta, $viaRollup, $now) {
            // The guards above checked $from; refuse if another process moved the row since it was loaded.
            $current = $this->newQueryWithoutScopes()->whereKey($this->getKey())->lockForUpdate()->value('status');

            if ($current !== $from) {
                throw new InvalidTransitionException("Report [{$this->reference}] changed to [{$current}] since it was loaded; refresh and retry.");
            }

            $this->transitioning = true;

            try {
                $this->forceFill([
                    'previous_status' => $from,
                    'status' => $to,
                    'status_changed_at' => $now,
                    'resolved_at' => $definition->category === StatusCategory::Resolved ? $now : ($definition->isTerminal() ? $this->resolved_at : null),
                    'closed_at' => $definition->category === StatusCategory::Closed ? $now : null,
                ])->save();
            } finally {
                $this->transitioning = false;
            }

            $this->transitions()->create([
                'from' => $from,
                'to' => $to,
                'from_category' => $fromCategory?->value,
                'to_category' => $definition->category->value,
                'actor_type' => $by?->getMorphClass(),
                'actor_id' => $by?->getKey(),
                'reason' => $reason,
                'meta' => $meta ?: null,
                'via_rollup' => $viaRollup,
                'created_at' => $now,
            ]);
        });

        Docket::events()->dispatch(StatusChanged::class, $this, $from, $to, $by, $reason, $viaRollup);

        $toCategory = $definition->category;

        if ($toCategory === StatusCategory::Resolved && $fromCategory !== StatusCategory::Resolved) {
            Docket::events()->dispatch(ReportResolved::class, $this, $by);
        } elseif ($toCategory === StatusCategory::Closed && $fromCategory !== StatusCategory::Closed) {
            Docket::events()->dispatch(ReportClosed::class, $this, $by);
        } elseif (($fromDefinition?->isTerminal() ?? false) && ! $definition->isTerminal()) {
            Docket::events()->dispatch(ReportReopened::class, $this, $by);
        }

        Docket::rollup()->apply($this, $by);

        return $this;
    }

    /**
     * Like transitionTo() but returns false instead of throwing.
     *
     * @param  array<string, mixed>  $meta
     */
    public function tryTransitionTo(
        string|Status $to,
        ?Model $by = null,
        ?string $reason = null,
        array $meta = [],
        bool $viaRollup = false,
        bool $authorize = true,
    ): bool {
        try {
            $this->transitionTo($to, $by, $reason, $meta, $viaRollup, $authorize);

            return true;
        } catch (InvalidTransitionException) {
            return false;
        }
    }

    public function newChild(): ReportBuilder
    {
        return Docket::report()->parent($this);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function comment(string $body, ?Model $by = null, Visibility|string|null $visibility = null, array $meta = []): Report
    {
        return $this->reply($body, ReportKind::Comment, null, $by, $visibility, $meta);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function reply(
        string $body,
        string|ReportKind $kind = ReportKind::Comment,
        ?string $status = null,
        ?Model $by = null,
        Visibility|string|null $visibility = null,
        array $meta = [],
    ): Report {
        return $this->newChild()
            ->kind($kind)
            ->body($body)
            ->meta($meta)
            ->when($by, fn (ReportBuilder $b) => $b->from($by))
            ->when($status, fn (ReportBuilder $b, string $status) => $b->status($status))
            ->when($visibility, fn (ReportBuilder $b, Visibility|string $visibility) => $b->visibility($visibility))
            ->create();
    }

    /** @return Collection<int, static> */
    public function fullThread(): Collection
    {
        return static::query()->thread($this->root_id ?? $this->getKey())->get();
    }

    /**
     * Owners walking up the parent chain, nearest first.
     *
     * @return Collection<int, Model>
     */
    public function ancestorOwners(int $depth = 1): Collection
    {
        $owners = new Collection;
        $current = $this;

        for ($i = 0; $i < $depth; $i++) {
            $current = $current->parent;

            if ($current === null) {
                break;
            }

            if ($current->owner !== null) {
                $owners->push($current->owner);
            }
        }

        return $owners;
    }

    public function lock(): static
    {
        return tap($this, fn ($model) => $model->forceFill(['locked_at' => Carbon::now()])->save());
    }

    public function unlock(): static
    {
        return tap($this, fn ($model) => $model->forceFill(['locked_at' => null])->save());
    }

    /**
     * Update editable attributes with an explicit editor so the revision is attributed.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function edit(array $attributes, ?Model $by = null, ?string $reason = null): static
    {
        return $this->saveAs($by, $reason, fn () => $this->fill($attributes)->save());
    }

    public function assignTo(?Model $assignee, ?Model $by = null): static
    {
        return $this->saveAs($by, null, fn () => $this->forceFill([
            'assignee_type' => $assignee?->getMorphClass(),
            'assignee_id' => $assignee?->getKey(),
        ])->save());
    }

    /**
     * Save with an attributed editor, in one transaction with the revision and meta index the observer writes.
     *
     * @param  Closure(): mixed  $save
     */
    protected function saveAs(?Model $by, ?string $reason, Closure $save): static
    {
        $this->editContext = ['by' => $by, 'reason' => $reason];

        try {
            $this->getConnection()->transaction($save);
        } finally {
            $this->editContext = null;
        }

        return $this;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCategory(Builder $query, StatusCategory|string $category): Builder
    {
        return $query->whereIn('status', Docket::statuses()->inCategory($category)->keys()->all());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->category(StatusCategory::Open);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->category(StatusCategory::Active);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->category(StatusCategory::Resolved);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeClosed(Builder $query): Builder
    {
        return $query->category(StatusCategory::Closed);
    }

    /**
     * Open or active.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', Docket::statuses()->all()->reject(fn (StatusDefinition $s) => $s->isTerminal())->keys()->all());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeThread(Builder $query, Model|string|int $root): Builder
    {
        $rootId = $root instanceof Model ? $root->getKey() : $root;

        return $query
            ->where(fn (Builder $q) => $q->whereKey($rootId)->orWhere('root_id', $rootId))
            ->orderBy('depth')
            ->orderBy('created_at');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByReferenceOrKey(Builder $query, string|int $identifier): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('reference', (string) $identifier)
            ->when($this->couldBeKey($identifier), fn (Builder $q) => $q->orWhere($this->getQualifiedKeyName(), $identifier)));
    }

    /**
     * @param  Builder<static>  $query
     * @param  string|ReportKind|array<int, string|ReportKind>  $kind
     * @return Builder<static>
     */
    public function scopeKind(Builder $query, string|ReportKind|array $kind): Builder
    {
        return $query->whereIn('kind', array_map(fn ($k) => $k instanceof ReportKind ? $k->value : $k, Arr::wrap($kind)));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibility(Builder $query, Visibility|string $visibility): Builder
    {
        return $query->where('visibility', $visibility instanceof Visibility ? $visibility->value : $visibility);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOwner(Builder $query, Model $owner): Builder
    {
        return $query->whereMorph('owner', $owner, stringKey: StringKeyedMorphMany::morphKeysAreStrings());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query->whereMorph('subject', $subject, stringKey: true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForReporter(Builder $query, Model $reporter): Builder
    {
        return $query->whereMorph('reporter', $reporter, stringKey: StringKeyedMorphMany::morphKeysAreStrings());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAssignedTo(Builder $query, Model $assignee): Builder
    {
        return $query->whereMorph('assignee', $assignee, stringKey: StringKeyedMorphMany::morphKeysAreStrings());
    }

    /**
     * Filter on a key inside `meta`. Uses the meta index table when enabled,
     * otherwise a native JSON path query.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhereMeta(Builder $query, string $key, mixed $operator = null, mixed $value = null): Builder
    {
        if (func_num_args() === 3) {
            [$operator, $value] = ['=', $operator];
        }

        if (config('docket.meta.index', false)) {
            // Null values aren't indexed, so "is null" means "has no row for the key", as a JSON path query treats it.
            if ($value === null) {
                return in_array($operator, ['!=', '<>'], true)
                    ? $query->whereHas('metaIndex', fn (Builder $q) => $q->where('key', $key))
                    : $query->whereDoesntHave('metaIndex', fn (Builder $q) => $q->where('key', $key));
            }

            return $query->whereHas('metaIndex', function (Builder $q) use ($key, $operator, $value) {
                $q->where('key', $key);

                match (true) {
                    is_bool($value) => $q->where('value_bool', $operator, $value),
                    is_int($value), is_float($value) => $q->where('value_number', $operator, $value),
                    default => $q->where('value_string', $operator, $value),
                };
            });
        }

        return $query->where("meta->{$key}", $operator, $value);
    }

    /**
     * Unresolved reports untouched for N days.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStale(Builder $query, int $days = 7): Builder
    {
        return $query->unresolved()->where('updated_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->unresolved()->whereNotNull('due_at')->where('due_at', '<', Carbon::now());
    }

    /**
     * Public reports, plus internal ones the user is involved in — or every
     * report when the user passes the `docket.view-internal` gate ability.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?Model $user): Builder
    {
        if ($user === null) {
            return $query->visibility(Visibility::Public);
        }

        if (Gate::has('docket.view-internal') && Gate::forUser($user)->allows('docket.view-internal')) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($user) {
            $q->where('visibility', Visibility::Public->value)
                ->orWhere(fn (Builder $inner) => $inner->forOwner($user))
                ->orWhere(fn (Builder $inner) => $inner->forReporter($user))
                ->orWhere(fn (Builder $inner) => $inner->assignedTo($user));
        });
    }
}
