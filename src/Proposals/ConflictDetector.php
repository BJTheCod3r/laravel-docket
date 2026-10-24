<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Proposals;

use BackedEnum;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Models\Proposal;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Compares the target's *current* state against the snapshot taken when the
 * proposal was made. Attributes by value, files by attachment id and checksum.
 */
class ConflictDetector
{
    /**
     * @param  Model|null  $target  An already-loaded (ideally locked) target, to skip re-reading it.
     * @return array<string, array{original: mixed, current: mixed, proposed: mixed}>
     */
    public function detect(Proposal $proposal, ?Model $target = null): array
    {
        // Without a target from the caller, read a fresh row: the cached relation may predate the drift.
        $target ??= $proposal->target()->first();

        if ($target === null) {
            return [];
        }

        $original = $proposal->originalSnapshot();

        $attributes = collect($proposal->proposedChanges())->map(fn ($proposed, string $attribute) => [
            'original' => $original[$attribute] ?? null,
            'current' => $target->getAttribute($attribute),
            'proposed' => $proposed,
        ]);

        $files = collect($proposal->file_changes ?? [])->map(fn ($operations, string $field) => [
            'original' => $original[$field] ?? null,
            'current' => $this->snapshotFiles($target, $field),
            'proposed' => $operations,
        ]);

        return $attributes
            ->merge($files)
            ->reject(fn (array $conflict) => $this->same($conflict['original'], $conflict['current']))
            ->all();
    }

    /** @return array<string, mixed>|array<int, array<string, mixed>>|null */
    public function snapshotFiles(Model $target, string $field): ?array
    {
        $current = Docket::fileBinding($target, $field)->current($target, $field);

        if ($current instanceof Collection) {
            return $current->map(fn (Attachment $a) => $this->fingerprint($a))->values()->all();
        }

        return $current instanceof Attachment ? $this->fingerprint($current) : null;
    }

    /** @return array<string, mixed> */
    protected function fingerprint(Attachment $attachment): array
    {
        return [
            'attachment_id' => $attachment->exists ? $attachment->getKey() : null,
            'checksum' => $attachment->checksum,
        ];
    }

    /**
     * Loose-but-safe equality: normalise through JSON so 1 == "1" == 1.0 and
     * array key order doesn't matter, while null stays distinct from "".
     */
    public function same(mixed $a, mixed $b): bool
    {
        return $this->normalise($a) === $this->normalise($b);
    }

    protected function normalise(mixed $value): mixed
    {
        // Snapshots store dates as JSON strings ("…000000Z"); compare both sides as UTC ISO-8601.
        if ($value instanceof DateTimeInterface || (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value))) {
            return Carbon::parse($value)->utc()->format(DATE_ATOM);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return collect($value)->sortKeys()->map(fn ($v) => $this->normalise($v))->all();
        }

        if (is_object($value)) {
            return json_decode((string) json_encode($value), true);
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        if (is_string($value) && is_numeric($value) && (string) (float) $value === $value) {
            return $value + 0;
        }

        return $value;
    }
}
