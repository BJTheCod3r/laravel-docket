<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Suppression;

use BJTheCod3r\Docket\Models\NotificationSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class QuietHours
{
    /**
     * When inside the window, the moment it ends (UTC); otherwise null.
     */
    public function deferUntil(?NotificationSetting $settings, ?Model $notifiable = null, ?Carbon $now = null): ?Carbon
    {
        if ($settings === null || ! $settings->hasQuietHours()) {
            return null;
        }

        $timezone = $this->timezone($settings, $notifiable);
        $now = ($now ?? Carbon::now())->copy()->setTimezone($timezone);

        $from = $now->copy()->setTimeFromTimeString((string) $settings->quiet_from);
        $to = $now->copy()->setTimeFromTimeString((string) $settings->quiet_to);

        if ($from->lte($to)) {
            return $now->gte($from) && $now->lt($to) ? $to->utc() : null;
        }

        if ($now->gte($from)) {
            return $to->addDay()->utc();
        }

        if ($now->lt($to)) {
            return $to->utc();
        }

        return null;
    }

    public function timezone(?NotificationSetting $settings, ?Model $notifiable = null): string
    {
        if ($settings?->timezone) {
            return $settings->timezone;
        }

        if ($notifiable !== null && method_exists($notifiable, 'preferredDocketTimezone')) {
            $tz = $notifiable->preferredDocketTimezone();

            if (is_string($tz) && $tz !== '') {
                return $tz;
            }
        }

        return (string) (config('docket.notifications.suppression.timezone_fallback') ?: config('app.timezone', 'UTC'));
    }
}
