<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Attachments\FilesystemDriver;
use BJTheCod3r\Docket\Database\Factories\AttachmentFactory;
use BJTheCod3r\Docket\Enums\AttachmentState;
use BJTheCod3r\Docket\Exceptions\AttachmentException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use BJTheCod3r\Docket\Models\Concerns\ScopesMorphs;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property string $attachable_type
 * @property string $attachable_id
 * @property string $collection
 * @property string $driver
 * @property string|null $disk
 * @property string|null $path
 * @property string|null $external_type
 * @property string|null $external_id
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string|null $checksum
 * @property array<string, array{disk: string, path: string}>|null $variants
 * @property array<string, mixed>|null $meta
 * @property int $order
 * @property string $state
 */
class Attachment extends Model
{
    use DocketModel;
    use ScopesMorphs;

    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;
    use Prunable;

    protected string $docketTable = 'attachments';

    protected $guarded = [];

    protected $casts = [
        'variants' => 'array',
        'meta' => 'array',
        'size' => 'integer',
        'order' => 'integer',
    ];

    protected static function newFactory(): AttachmentFactory
    {
        return AttachmentFactory::new();
    }

    protected static function booted(): void
    {
        static::deleting(fn (Attachment $attachment) => Docket::attachments()->driver($attachment->driver)->delete($attachment));
    }

    /**
     * Record a stored file against its owner. Drivers call this after writing the bytes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createFor(Model $owner, string $collection, array $attributes): Attachment
    {
        /** @var class-string<Attachment> $class */
        $class = Docket::model('attachment');

        return $class::query()->create(array_merge([
            'attachable_type' => $owner->getMorphClass(),
            'attachable_id' => (string) $owner->getKey(),
            'collection' => $collection,
            'driver' => config('docket.attachments.driver', 'filesystem'),
            'state' => AttachmentState::Live->value,
            'order' => 0,
        ], $attributes));
    }

    /**
     * Re-point this row at a new owner and collection; promote() uses it once the bytes have moved.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function moveTo(Model $owner, string $collection, array $attributes = []): static
    {
        $this->forceFill(array_merge([
            'attachable_type' => $owner->getMorphClass(),
            'attachable_id' => (string) $owner->getKey(),
            'collection' => $collection,
        ], $attributes))->save();

        return $this;
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function url(?string $variant = null): string
    {
        return Docket::attachments()->driver($this->driver)->url($this, $variant);
    }

    public function temporaryUrl(DateTimeInterface $expires, ?string $variant = null): string
    {
        return Docket::attachments()->driver($this->driver)->temporaryUrl($this, $expires, $variant);
    }

    /** @return resource|null */
    public function stream(?string $variant = null)
    {
        return Docket::attachments()->driver($this->driver)->stream($this, $variant);
    }

    /**
     * An HTTP response for the file. The filesystem driver supports Range
     * requests on local disks; other drivers stream the bytes.
     */
    public function response(?string $variant = null, string $disposition = 'inline'): Response
    {
        $driver = Docket::attachments()->driver($this->driver);

        if ($driver instanceof FilesystemDriver) {
            return $driver->response($this, $variant, $disposition);
        }

        $stream = $driver->stream($this, $variant) ?? throw new AttachmentException("Attachment [{$this->getKey()}] has no readable bytes.");

        return new StreamedResponse(fn () => fpassthru($stream), 200, [
            'Content-Type' => $this->mime,
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $this->name, Str::ascii($this->name)),
        ]);
    }

    public function state(): AttachmentState
    {
        return AttachmentState::from($this->state);
    }

    public function isStaged(): bool
    {
        return $this->state() === AttachmentState::Staged;
    }

    public function isLive(): bool
    {
        return $this->state() === AttachmentState::Live;
    }

    public function isArchived(): bool
    {
        return $this->state() === AttachmentState::Archived;
    }

    public function markArchived(): static
    {
        return tap($this, fn ($model) => $model->forceFill(['state' => AttachmentState::Archived->value])->save());
    }

    public function hasVariant(string $variant): bool
    {
        return isset($this->variants[$variant]);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCollection(Builder $query, string $collection): Builder
    {
        return $query->where('collection', $collection);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeState(Builder $query, AttachmentState|string $state): Builder
    {
        return $query->where('state', $state instanceof AttachmentState ? $state->value : $state);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->state(AttachmentState::Live);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStaged(Builder $query): Builder
    {
        return $query->state(AttachmentState::Staged);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->state(AttachmentState::Archived);
    }

    /**
     * Staged files whose proposal is no longer pending (rejected, withdrawn or gone).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAbandoned(Builder $query): Builder
    {
        /** @var class-string<Proposal> $proposal */
        $proposal = Docket::model('proposal');

        /** @var class-string<Report> $report */
        $report = Docket::model('report');

        // A pluck rather than a subquery: attachable_id is a string column and Postgres won't compare it to an integer report_id.
        return $query->staged()
            ->where('attachable_type', (new $report)->getMorphClass())
            ->whereNotIn('attachable_id', $proposal::query()->pending()->pluck('report_id')->map(fn ($id) => (string) $id)->all());
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStaleStaged(Builder $query, int $days): Builder
    {
        return $query->abandoned()->where('updated_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeStaleArchived(Builder $query, int $days): Builder
    {
        return $query->archived()->where('updated_at', '<', Carbon::now()->subDays($days));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFor(Builder $query, Model $owner): Builder
    {
        return $query->whereMorph('attachable', $owner, stringKey: true);
    }

    /**
     * Staged files whose proposal ended without being applied, older than
     * `prune_staged_after_days`; archived files past the revert window.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        $staged = static::stagedRetentionDays();
        $archived = static::archivedRetentionDays();

        return static::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $q) => $q->staleStaged($staged))
            ->orWhere(fn (Builder $q) => $q->staleArchived($archived)));
    }

    public static function stagedRetentionDays(): int
    {
        return (int) config('docket.proposals.prune_staged_after_days', 7);
    }

    public static function archivedRetentionDays(): int
    {
        return (int) config('docket.proposals.prune_archived_after_days', 90);
    }
}
