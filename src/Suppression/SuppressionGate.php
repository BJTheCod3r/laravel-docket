<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Suppression;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Enums\MuteDirection;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Mute;
use BJTheCod3r\Docket\Notifications\DigestStore;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class SuppressionGate
{
    /**
     * Active `about` mutes on the report, its root, its subject, any of its services, or globally.
     *
     * @return Collection<int, Mute>
     */
    public function aboutMutes(DocketEvent $event): Collection
    {
        $report = $event->report();

        // Built from the columns, so the root and subject never need loading.
        $scopes = collect([
            [$report->getMorphClass(), $report->getKey()],
            $report->root_id !== null ? [$report->getMorphClass(), $report->root_id] : null,
            $report->subject_type !== null ? [$report->subject_type, $report->subject_id] : null,
        ])
            ->merge($report->services)
            ->filter()
            ->all();

        return $this->activeMutes(MuteDirection::About, $scopes, includeGlobal: true);
    }

    /**
     * Active `for` mutes on each recipient, or on a group it reports via `docketMuteGroups()`,
     * in one query, keyed by DigestStore::recipientKey().
     *
     * @param  iterable<Model>  $recipients
     * @return array<string, Collection<int, Mute>>
     */
    public function forMutesByRecipient(iterable $recipients): array
    {
        $scopes = collect($recipients)->mapWithKeys(fn (Model $recipient) => [DigestStore::recipientKey($recipient) => $this->forScopes($recipient)]);

        if ($scopes->isEmpty()) {
            return [];
        }

        $mutes = $this->activeMutes(MuteDirection::For, $scopes->flatten(1)->all(), includeGlobal: false);

        return $scopes
            ->map(function (array $recipientScopes) use ($mutes) {
                $keys = array_map(fn (Model $scope) => DigestStore::recipientKey($scope), $recipientScopes);

                return $mutes->filter(fn (Mute $mute) => in_array(DigestStore::morphKey((string) $mute->scope_type, (string) $mute->scope_id), $keys, true))->values();
            })
            ->all();
    }

    /**
     * @return array<int, Model>
     */
    protected function forScopes(Model $recipient): array
    {
        $groups = method_exists($recipient, 'docketMuteGroups') ? $recipient->docketMuteGroups() : [];

        return collect(is_iterable($groups) ? $groups : [])
            ->filter(fn ($group) => $group instanceof Model)
            ->prepend($recipient)
            ->values()
            ->all();
    }

    public function activeMuteFor(?Model $scope): ?Mute
    {
        return $this->query()->active()->scopedTo($scope)->orderByDesc('starts_at')->first();
    }

    public function lift(?Model $scope): int
    {
        return $this->query()->active()->scopedTo($scope)->update(['ends_at' => Carbon::now()]);
    }

    /**
     * @param  array<int, Model|array{0: string, 1: string|int|null}>  $scopes  Models, or [morph type, key] pairs.
     * @return Collection<int, Mute>
     */
    public function activeMutes(MuteDirection $direction, array $scopes, bool $includeGlobal): Collection
    {
        if ($scopes === [] && ! $includeGlobal) {
            return new Collection;
        }

        return $this->query()
            ->active()
            ->direction($direction)
            ->where(function (Builder $query) use ($scopes, $includeGlobal) {
                $query->when($includeGlobal, fn (Builder $q) => $q->orWhereNull('scope_type'));

                foreach ($scopes as $scope) {
                    [$type, $id] = $scope instanceof Model ? [$scope->getMorphClass(), $scope->getKey()] : $scope;

                    $query->orWhere(fn (Builder $q) => $q->where('scope_type', $type)->where('scope_id', (string) $id));
                }
            })
            ->get();
    }

    /**
     * A channel-agnostic mute that would cover the event but for its priority: the event punches through it.
     *
     * @param  Collection<int, Mute>  $mutes
     */
    public function bypassedMute(Collection $mutes, DocketEvent $event): ?Mute
    {
        $report = $event->relevantReport();

        return $mutes->first(fn (Mute $mute) => $mute->channels === null
            && $mute->covers($event::key(), $report->kind)
            && ! $mute->covers($event::key(), $report->kind, $report->priority));
    }

    /**
     * Without a channel only channel-agnostic mutes match; with one, only mutes listing that channel.
     * Drop mutes win over defer mutes.
     *
     * @param  Collection<int, Mute>  $mutes
     */
    public function coveringMute(Collection $mutes, DocketEvent $event, ?string $channel = null): ?Mute
    {
        $report = $event->relevantReport();

        $covering = $mutes->filter(fn (Mute $mute) => ($mute->channels === null) === ($channel === null)
            && $mute->covers($event::key(), $report->kind, $report->priority, $channel));

        return $covering->first(fn (Mute $m) => $m->drops()) ?? $covering->first();
    }

    /** @return Builder<Mute> */
    protected function query(): Builder
    {
        /** @var class-string<Mute> $class */
        $class = Docket::model('mute');

        return $class::query();
    }
}
