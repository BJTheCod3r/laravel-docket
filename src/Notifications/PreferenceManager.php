<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\NotificationSetting;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use BJTheCod3r\Docket\Suppression\MuteBuilder;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class PreferenceManager
{
    public function __construct(
        protected Model $notifiable,
        protected ChannelResolver $channels,
    ) {}

    /**
     * @param  array<int, string>|string  $channels
     */
    public function allow(string $eventKey, array|string $channels): static
    {
        return $this->set($eventKey, $channels, enabled: true);
    }

    /**
     * @param  array<int, string>|string  $channels
     */
    public function deny(string $eventKey, array|string $channels): static
    {
        return $this->set($eventKey, $channels, enabled: false);
    }

    public function reset(?string $eventKey = null): static
    {
        $settings = $this->findSettings();

        if ($settings === null) {
            return $this;
        }

        $preferences = $settings->preferences ?? [];

        if ($eventKey === null) {
            $preferences = [];
        } else {
            unset($preferences[$eventKey]);
        }

        $settings->forceFill(['preferences' => $preferences ?: null])->save();

        return $this;
    }

    public function allows(string $eventKey, string $channel): bool
    {
        return in_array($channel, $this->channelsFor($eventKey), true);
    }

    /**
     * The channels this notifiable would receive the event on, ignoring subscriptions.
     *
     * @return array<int, string>
     */
    public function channelsFor(string $eventKey): array
    {
        return $this->channels->resolve($this->notifiable, $eventKey);
    }

    /**
     * @return array<string, bool>
     */
    public function preferencesFor(string $eventKey): array
    {
        return $this->findSettings()?->preferencesFor($eventKey) ?? [];
    }

    /** @param  array<int, string>|null  $channels  null = all channels are quiet */
    public function quietHours(string $from, string $to, ?string $timezone = null, ?array $channels = null): static
    {
        $settings = $this->settings();

        $settings->forceFill([
            'quiet_from' => $from,
            'quiet_to' => $to,
            'timezone' => $timezone ?? $settings->timezone,
            'quiet_channels' => $channels,
        ])->save();

        return $this;
    }

    public function digest(bool $enabled = true, ?int $windowMinutes = null): static
    {
        $this->settings()->forceFill([
            'digest_enabled' => $enabled,
            'digest_window_minutes' => $windowMinutes,
        ])->save();

        return $this;
    }

    /**
     * Suppress everything delivered to this notifiable until a moment ("I'm on leave").
     */
    public function mute(DateTimeInterface|string|null $until = null, ?string $reason = null): MuteBuilder
    {
        $builder = MuteBuilder::deliveredTo($this->notifiable);

        if ($reason !== null) {
            $builder->reason($reason);
        }

        if ($until !== null) {
            $builder->until($until);
        }

        return $builder;
    }

    /** The notifiable's settings row, created on first use. */
    public function settings(): NotificationSetting
    {
        /** @var class-string<NotificationSetting> $class */
        $class = Docket::model('setting');

        return $class::query()->firstOrCreate([
            'notifiable_type' => $this->notifiable->getMorphClass(),
            'notifiable_id' => StringKeyedMorphMany::morphKeysAreStrings() ? (string) $this->notifiable->getKey() : $this->notifiable->getKey(),
        ]);
    }

    public function findSettings(): ?NotificationSetting
    {
        /** @var class-string<NotificationSetting> $class */
        $class = Docket::model('setting');

        return $class::query()->for($this->notifiable)->first();
    }

    /**
     * @param  array<int, string>|string  $channels
     */
    protected function set(string $eventKey, array|string $channels, bool $enabled): static
    {
        $settings = $this->settings();
        $preferences = $settings->preferences ?? [];

        foreach (Arr::wrap($channels) as $channel) {
            $preferences[$eventKey][$channel] = $enabled;
        }

        $settings->forceFill(['preferences' => $preferences])->save();

        return $this;
    }
}
