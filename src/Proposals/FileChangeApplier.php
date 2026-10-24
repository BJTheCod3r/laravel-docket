<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Proposals;

use BJTheCod3r\Docket\Contracts\FileBinding;
use BJTheCod3r\Docket\Enums\FileOperation;
use BJTheCod3r\Docket\Exceptions\InvalidProposalException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class FileChangeApplier
{
    /**
     * Every referenced attachment is loaded in one query and checked before any
     * file moves, so a missing one can't leave earlier operations half-applied on disk.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $fileChanges
     * @return array<int, array<string, mixed>>  Ordered log of what was done, for revert().
     */
    public function apply(Model $target, array $fileChanges): array
    {
        $attachments = $this->load(collect($fileChanges)->flatten(1)->pluck('attachment_id'));

        collect($fileChanges)->each(fn (array $operations, string $field) => collect($operations)
            ->reject(fn (array $operation) => $this->operation($operation) === FileOperation::Reorder)
            ->each(fn (array $operation) => $this->ensureApplicable($target, $field, $operation, $attachments)));

        return collect($fileChanges)
            ->flatMap(function (array $operations, string $field) use ($target, $attachments) {
                $binding = Docket::fileBinding($target, $field);

                return collect($operations)->map(fn (array $operation) => match ($this->operation($operation)) {
                    FileOperation::Replace => $this->replace($target, $field, $this->attachmentFor($operation, $attachments), $binding),
                    FileOperation::Add => $this->add($target, $field, $this->attachmentFor($operation, $attachments), $binding),
                    FileOperation::Remove => $this->remove($target, $field, $this->attachmentFor($operation, $attachments), $binding),
                    FileOperation::Reorder => $this->reorder($target, $field, $operation, $binding),
                });
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $log
     */
    public function revert(Model $target, array $log): void
    {
        $attachments = $this->load(collect($log)->flatMap(fn (array $entry) => [$entry['applied_id'] ?? null, $entry['previous']['id'] ?? null]));

        collect($log)->reverse()->each(function (array $entry) use ($target, $attachments) {
            $field = (string) $entry['field'];
            $binding = Docket::fileBinding($target, $field);
            $applied = $attachments->get((string) ($entry['applied_id'] ?? ''));

            switch ($this->operation($entry)) {
                case FileOperation::Replace:
                    $applied?->markArchived();
                    $this->reapply($target, $field, $entry, $binding, $attachments);
                    break;

                case FileOperation::Add:
                    if ($applied !== null) {
                        $binding->remove($target, $field, $applied);
                    }
                    break;

                case FileOperation::Remove:
                    $this->reapply($target, $field, $entry, $binding, $attachments);
                    break;

                case FileOperation::Reorder:
                    $binding->reorder($target, $field, (array) ($entry['previous_order'] ?? []));
                    break;
            }
        });
    }

    /** @param  array<string, mixed>  $operation */
    protected function operation(array $operation): FileOperation
    {
        $op = (string) ($operation['op'] ?? FileOperation::Replace->value);

        return FileOperation::tryFrom($op) ?? throw new InvalidProposalException("Unknown file operation [{$op}].");
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  Collection<array-key, Attachment>  $attachments
     */
    protected function ensureApplicable(Model $target, string $field, array $operation, Collection $attachments): void
    {
        $attachment = $this->attachmentFor($operation, $attachments);

        // A removal must name a live file of this target and field, never someone else's.
        throw_if(
            $this->operation($operation) === FileOperation::Remove
                && ($attachment->attachable_type !== $target->getMorphClass()
                    || $attachment->attachable_id !== (string) $target->getKey()
                    || $attachment->collection !== $field
                    || ! $attachment->isLive()),
            InvalidProposalException::class,
            "Attachment [{$attachment->getKey()}] is not a current file of [{$field}].",
        );
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  Collection<array-key, Attachment>  $attachments
     */
    protected function attachmentFor(array $operation, Collection $attachments): Attachment
    {
        $id = $operation['attachment_id'] ?? null;

        return $attachments->get((string) $id) ?? throw new InvalidProposalException($this->operation($operation) === FileOperation::Remove
            ? 'Attachment to remove no longer exists.'
            : 'Staged attachment ['.($id ?? '?').'] no longer exists; it may have been pruned.');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  Collection<array-key, Attachment>  $attachments
     */
    protected function reapply(Model $target, string $field, array $entry, FileBinding $binding, Collection $attachments): void
    {
        if ($previous = $this->restore($entry['previous'] ?? null, $attachments)) {
            $binding->apply($target, $field, $previous);
        }
    }

    /** @return array<string, mixed> */
    protected function replace(Model $target, string $field, Attachment $staged, FileBinding $binding): array
    {
        $previous = $binding->current($target, $field);

        if ($previous instanceof Collection) {
            $previous = $previous->first();
        }

        $binding->apply($target, $field, $staged);

        return [
            'op' => FileOperation::Replace->value,
            'field' => $field,
            'applied_id' => $staged->getKey(),
            'previous' => $previous instanceof Attachment ? $this->restorePoint($previous) : null,
        ];
    }

    /** @return array<string, mixed> */
    protected function add(Model $target, string $field, Attachment $staged, FileBinding $binding): array
    {
        $binding->apply($target, $field, $staged);

        return ['op' => FileOperation::Add->value, 'field' => $field, 'applied_id' => $staged->getKey()];
    }

    /** @return array<string, mixed> */
    protected function remove(Model $target, string $field, Attachment $attachment, FileBinding $binding): array
    {
        $binding->remove($target, $field, $attachment);

        return ['op' => FileOperation::Remove->value, 'field' => $field, 'previous' => $this->restorePoint($attachment)];
    }

    /**
     * @param  array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    protected function reorder(Model $target, string $field, array $operation, FileBinding $binding): array
    {
        $current = $binding->current($target, $field);
        $previousOrder = $current instanceof Collection ? $current->map->getKey()->all() : [];

        $binding->reorder($target, $field, (array) ($operation['order'] ?? []));

        return ['op' => FileOperation::Reorder->value, 'field' => $field, 'previous_order' => $previousOrder];
    }

    /**
     * @param  Collection<array-key, mixed>  $ids
     * @return Collection<string, Attachment>
     */
    protected function load(Collection $ids): Collection
    {
        /** @var class-string<Attachment> $class */
        $class = Docket::model('attachment');

        return $class::query()->findMany($ids->filter()->unique()->values()->all())
            ->toBase()
            ->keyBy(fn (Attachment $attachment): string => (string) $attachment->getKey());
    }

    /**
     * What revert() needs to bring a file back: its id, or its attributes when the
     * binding described it without a database row.
     *
     * @return array<string, mixed>
     */
    protected function restorePoint(Attachment $attachment): array
    {
        return [
            'id' => $attachment->exists ? $attachment->getKey() : null,
            'attributes' => $attachment->exists ? null : $attachment->getAttributes(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $point
     * @param  Collection<array-key, Attachment>  $attachments
     */
    protected function restore(?array $point, Collection $attachments): ?Attachment
    {
        if (($point['id'] ?? null) !== null) {
            return $attachments->get((string) $point['id']);
        }

        if (is_array($point['attributes'] ?? null)) {
            /** @var class-string<Attachment> $class */
            $class = Docket::model('attachment');

            return (new $class)->setRawAttributes($point['attributes']);
        }

        return null;
    }
}
