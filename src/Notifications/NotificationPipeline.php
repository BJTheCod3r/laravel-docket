<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Contracts\DocketEvent;
use BJTheCod3r\Docket\Contracts\RecipientResolver;
use BJTheCod3r\Docket\Enums\MuteBehaviour;
use BJTheCod3r\Docket\Events\NotificationSuppressed;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Jobs\SendDigest;
use BJTheCod3r\Docket\Models\Mute;
use BJTheCod3r\Docket\Models\NotificationSetting;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Notifications\Recipients\AncestorOwnerResolver;
use BJTheCod3r\Docket\Notifications\Recipients\AssigneeResolver;
use BJTheCod3r\Docket\Notifications\Recipients\OwnerResolver;
use BJTheCod3r\Docket\Notifications\Recipients\ReporterResolver;
use BJTheCod3r\Docket\Notifications\Recipients\ServiceStakeholderResolver;
use BJTheCod3r\Docket\Notifications\Recipients\SubscriberResolver;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use BJTheCod3r\Docket\Support\EventKeyMap;
use BJTheCod3r\Docket\Suppression\QuietHours;
use BJTheCod3r\Docket\Suppression\SuppressionGate;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use WeakMap;

/**
 * 1. `about` mutes                    → drop stops here, before recipients are even resolved
 * 2. resolve + dedupe recipients
 * 3. per-recipient `for` mutes
 * 4. channels, then per-channel mutes
 * 5. quiet hours                      → defer (or drop)
 * 6. a pending defer mute             → defer
 * 7. digest                           → defer
 * 8. cooldown                         → drop
 * 9. dispatch
 *
 * Mutes and settings for all recipients are loaded once per event, not per recipient.
 */
class NotificationPipeline
{
    /** @var array<int, class-string<RecipientResolver>> */
    protected array $resolvers = [
        OwnerResolver::class,
        AncestorOwnerResolver::class,
        AssigneeResolver::class,
        ReporterResolver::class,
        SubscriberResolver::class,
        ServiceStakeholderResolver::class,
    ];

    protected bool $disabled = false;

    /**
     * Events raised inside withoutNotifications(); they dispatch after commit,
     * by which time the flag may already be restored.
     *
     * @var WeakMap<DocketEvent, true>
     */
    protected WeakMap $silenced;

    public function __construct(
        protected Container $container,
        protected SuppressionGate $gate,
        protected ChannelResolver $channels,
        protected QuietHours $quietHours,
        protected DigestStore $digests,
        protected Cache $cache,
    ) {
        $this->silenced = new WeakMap;
    }

    public function withoutNotifications(callable $callback): mixed
    {
        $previous = $this->disabled;
        $this->disabled = true;

        try {
            return $callback();
        } finally {
            $this->disabled = $previous;
        }
    }

    public function capture(DocketEvent $event): void
    {
        if ($this->disabled) {
            $this->silenced[$event] = true;
        }
    }

    public function handle(DocketEvent $event): void
    {
        $key = $event::key();
        $report = $event->relevantReport();

        if ($this->disabled || isset($this->silenced[$event]) || ! config('docket.notifications.enabled', true)) {
            $this->suppress($event, null, 'disabled');

            return;
        }

        if (in_array($key, (array) config('docket.notifications.ignore', []), true)) {
            $this->suppress($event, null, 'ignored');

            return;
        }

        if (Docket::kinds()->find($report->kind)?->notifies === false) {
            $this->suppress($event, null, 'kind');

            return;
        }

        $event->report()->loadMissing('services.owner');

        $deferUntil = null;
        $aboutMutes = $this->gate->aboutMutes($event);

        if ($about = $this->gate->coveringMute($aboutMutes, $event)) {
            if ($about->suppresses()) {
                $this->suppress($event, null, 'muted', ['mute' => $about->getKey()]);

                return;
            }

            $deferUntil = $about->ends_at;
        }

        $recipients = $this->recipients($event);
        $models = collect($recipients)->map(fn (array $pair) => $pair[0])->whereInstanceOf(Model::class);
        $settings = $this->settingsFor($models->all());
        $forMutes = $this->gate->forMutesByRecipient($models->all());

        foreach ($recipients as $recipientKey => [$recipient, $subscription]) {
            $this->deliverTo($event, $recipient, $subscription, $settings[$recipientKey] ?? null, $forMutes[$recipientKey] ?? new Collection, $deferUntil, $aboutMutes);
        }
    }

    /**
     * Steps 3–9 for a single recipient.
     *
     * @param  Collection<int, Mute>  $forMutes
     * @param  Collection<int, Mute>  $aboutMutes
     */
    protected function deliverTo(
        DocketEvent $event,
        Model|AnonymousNotifiable $recipient,
        ?Subscription $subscription,
        ?NotificationSetting $settings,
        Collection $forMutes,
        ?Carbon $until,
        Collection $aboutMutes,
    ): void {
        $key = $event::key();

        if ($for = $this->gate->coveringMute($forMutes, $event)) {
            if ($for->suppresses()) {
                $this->suppress($event, $recipient, 'muted_for', ['mute' => $for->getKey()]);

                return;
            }

            $until = $until?->max($for->ends_at) ?? $for->ends_at;
        }

        $channels = $this->channels->resolve($recipient, $key, $subscription, $settings?->preferencesFor($key) ?? []);

        if ($channels === []) {
            $this->suppress($event, $recipient, 'no_channels');

            return;
        }

        foreach ($channels as $channel) {
            $mute = $this->gate->coveringMute($aboutMutes, $event, $channel) ?? $this->gate->coveringMute($forMutes, $event, $channel);

            if ($mute === null) {
                continue;
            }

            $channels = array_values(array_diff($channels, [$channel]));

            $mute->suppresses()
                ? $this->suppress($event, $recipient, 'muted', ['mute' => $mute->getKey(), 'channels' => [$channel]])
                : $this->defer($event, $recipient, [$channel], $until?->max($mute->ends_at) ?? $mute->ends_at, 'muted');
        }

        if ($channels === []) {
            return;
        }

        $immediate = $channels;

        if ($recipient instanceof Model && ($quietUntil = $this->quietHours->deferUntil($settings, $recipient))) {
            $quietChannels = $settings?->quiet_channels;
            $deferred = $quietChannels === null ? $channels : collect($channels)->intersect($quietChannels)->values()->all();
            $immediate = collect($channels)->diff($deferred)->values()->all();

            if ($deferred !== []) {
                config('docket.notifications.suppression.quiet_hours_behaviour', MuteBehaviour::Defer->value) === MuteBehaviour::Drop->value
                    ? $this->suppress($event, $recipient, 'quiet_hours', ['channels' => $deferred])
                    : $this->defer($event, $recipient, $deferred, $until?->max($quietUntil) ?? $quietUntil, 'quiet_hours');
            }
        }

        if ($immediate === []) {
            return;
        }

        if ($until !== null) {
            $this->defer($event, $recipient, $immediate, $until, 'muted');

            return;
        }

        if ($digestWindow = $this->digestWindow($settings)) {
            $this->defer($event, $recipient, $immediate, $digestWindow, 'digest');

            return;
        }

        if ($this->onCooldown($recipient, $event)) {
            $this->suppress($event, $recipient, 'cooldown');

            return;
        }

        $notification = $this->notificationFor($event, $immediate);
        $notification->bypassedMute = ($bypassed = $this->gate->bypassedMute($aboutMutes, $event) ?? $this->gate->bypassedMute($forMutes, $event))
            ? ['id' => $bypassed->getKey(), 'reason' => $bypassed->reason, 'ends_at' => $bypassed->ends_at?->toIso8601String()]
            : null;

        $this->send($recipient, $notification);
    }

    /**
     * Each recipient once, keyed by DigestStore::recipientKey(). A recipient who also
     * subscribed keeps their subscription (and its channel choice) whichever resolver found them first.
     *
     * @return array<string, array{0: Model|AnonymousNotifiable, 1: Subscription|null}>
     */
    public function recipients(DocketEvent $event): array
    {
        $actor = $event->actor();
        $actorKey = $actor ? DigestStore::recipientKey($actor) : null;
        $notifyActor = (bool) config('docket.notifications.notify_actor', false);

        $resolvers = $this->allResolvers();
        $subscribers = collect($resolvers)->whereInstanceOf(SubscriberResolver::class)->first();

        return collect($resolvers)
            ->flatMap(fn (RecipientResolver $resolver) => collect($resolver->resolve($event)))
            ->filter(fn ($recipient) => $recipient instanceof Model || $recipient instanceof AnonymousNotifiable)
            ->keyBy(fn (Model|AnonymousNotifiable $recipient) => DigestStore::recipientKey($recipient))
            ->reject(fn ($recipient, string $key) => ! $notifyActor && $key === $actorKey)
            ->map(fn (Model|AnonymousNotifiable $recipient, string $key) => [$recipient, $subscribers?->subscriptionFor($key)])
            ->all();
    }

    /**
     * Settings rows for all model recipients in one query, keyed like recipients().
     *
     * @param  array<array-key, Model>  $models
     * @return array<string, NotificationSetting>
     */
    protected function settingsFor(array $models): array
    {
        if ($models === []) {
            return [];
        }

        /** @var class-string<NotificationSetting> $class */
        $class = Docket::model('setting');

        return $class::query()
            ->where(fn (Builder $query) => collect($models)
                ->groupBy(fn (Model $model) => $model->getMorphClass())
                ->each(fn ($group, string $type) => $query->orWhere(fn (Builder $q) => $q
                    ->where('notifiable_type', $type)
                    ->whereIn('notifiable_id', $group->map(fn (Model $model) => StringKeyedMorphMany::morphKeysAreStrings() ? (string) $model->getKey() : $model->getKey())->all()))))
            ->get()
            ->keyBy(fn (NotificationSetting $setting) => DigestStore::morphKey($setting->notifiable_type, $setting->notifiable_id))
            ->all();
    }

    /**
     * @return array<int, RecipientResolver>
     */
    protected function allResolvers(): array
    {
        return collect($this->resolvers)
            ->map(fn (string $class) => $this->container->make($class))
            ->merge(Docket::recipientResolvers())
            ->all();
    }

    /** @param  array<int, string>  $channels */
    public function notificationFor(DocketEvent $event, array $channels): DocketNotification
    {
        $class = $this->notificationClass($event::key());

        return new $class($event, $channels);
    }

    /**
     * @return class-string<DocketNotification>
     */
    public function notificationClass(string $eventKey): string
    {
        return EventKeyMap::lookup((array) config('docket.notifications.classes', []), $eventKey, ReportNotification::class);
    }

    protected function send(Model|AnonymousNotifiable $recipient, DocketNotification $notification): void
    {
        Notification::send($recipient, $notification);
    }

    /**
     * @param  array<int, string>  $channels
     */
    protected function defer(DocketEvent $event, Model|AnonymousNotifiable $recipient, array $channels, Carbon $until, string $reason): void
    {
        $report = $event->report();

        [$until, $schedule] = $this->digests->push($recipient, [
            'event_key' => $event::key(),
            'report_id' => $report->getKey(),
            'reference' => $report->reference,
            'title' => $report->title,
            'kind' => $report->kind,
            'status' => $report->status,
            'channels' => $channels,
            'reason' => $reason,
            'at' => Carbon::now()->toIso8601String(),
        ], $until, window: $reason === 'digest');

        if ($schedule) {
            SendDigest::dispatch($recipient, $until)->delay($until);
        }

        $this->suppress($event, $recipient, 'deferred', [
            'until' => $until->toIso8601String(),
            'because' => $reason,
            'channels' => $channels,
        ]);
    }

    protected function digestWindow(?NotificationSetting $settings): ?Carbon
    {
        $enabled = $settings->digest_enabled ?? (bool) config('docket.notifications.digest.enabled', false);

        if (! $enabled) {
            return null;
        }

        $minutes = $settings->digest_window_minutes ?? (int) config('docket.notifications.digest.window_minutes', 15);

        return Carbon::now()->addMinutes(max(1, $minutes));
    }

    protected function onCooldown(Model|AnonymousNotifiable $recipient, DocketEvent $event): bool
    {
        $seconds = (int) config('docket.notifications.cooldown_seconds', 0);

        if ($seconds <= 0) {
            return false;
        }

        $report = $event->report();
        $key = sprintf(
            'docket:cooldown:%s:%s:%s',
            DigestStore::recipientKey($recipient),
            $report->root_id ?? $report->getKey(),
            $event::key(),
        );

        // add() only writes when the key is absent, atomically: two simultaneous events can't both pass.
        return ! $this->cache->add($key, true, $seconds);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function suppress(DocketEvent $event, Model|AnonymousNotifiable|null $recipient, string $reason, array $context = []): void
    {
        $this->container->make(Dispatcher::class)->dispatch(new NotificationSuppressed($event, $recipient, $reason, $context));
    }
}
