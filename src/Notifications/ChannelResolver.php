<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Notifications;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Subscription;
use BJTheCod3r\Docket\Support\EventKeyMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Arr;

/**
 * explicit subscription channels
 *   -> notifiable's stored preferences
 *   -> notifiable's preferredDocketChannels() method
 *   -> config defaults for the event key
 *   -> config defaults for '*'
 *
 * An explicit deny always removes a channel, whichever source supplied it.
 */
class ChannelResolver
{
    /**
     * @param  array<string, bool>|null  $preferences  Stored channel => enabled for this event, when the caller already has them.
     * @return array<int, string>
     */
    public function resolve(Model|AnonymousNotifiable $notifiable, string $eventKey, ?Subscription $subscription = null, ?array $preferences = null): array
    {
        if ($notifiable instanceof AnonymousNotifiable) {
            return array_keys($notifiable->routes);
        }

        $preferences = collect($preferences ?? Docket::preferences($notifiable)->preferencesFor($eventKey));
        $allowed = $preferences->filter()->keys()->all();
        $denied = $preferences->reject()->keys()->all();

        $preferred = method_exists($notifiable, 'preferredDocketChannels')
            ? $notifiable->preferredDocketChannels($eventKey)
            : null;

        $channels = match (true) {
            $subscription?->channels !== null => $subscription->channels,
            $allowed !== [] => $allowed,
            is_array($preferred) && $preferred !== [] => $preferred,
            default => $this->defaults($eventKey),
        };

        return collect($channels)->unique()->diff($denied)->values()->all();
    }

    /**
     * @return array<int, string>
     */
    public function defaults(string $eventKey): array
    {
        $match = EventKeyMap::lookup((array) config('docket.notifications.defaults', []), $eventKey, ['channels' => ['database']]);

        return Arr::wrap($match['channels'] ?? []);
    }
}
