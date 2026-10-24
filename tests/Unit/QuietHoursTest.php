<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Models\NotificationSetting;
use BJTheCod3r\Docket\Suppression\QuietHours;
use Illuminate\Support\Carbon;

function settings(string $from, string $to, ?string $tz = 'UTC'): NotificationSetting
{
    return new NotificationSetting(['quiet_from' => $from, 'quiet_to' => $to, 'timezone' => $tz]);
}

it('handles overnight windows', function () {
    $quiet = new QuietHours;

    expect($quiet->deferUntil(settings('22:00', '07:00'), now: Carbon::parse('2026-01-01 23:00:00'))?->toDateTimeString())->toBe('2026-01-02 07:00:00')
        ->and($quiet->deferUntil(settings('22:00', '07:00'), now: Carbon::parse('2026-01-02 03:00:00'))?->toDateTimeString())->toBe('2026-01-02 07:00:00')
        ->and($quiet->deferUntil(settings('22:00', '07:00'), now: Carbon::parse('2026-01-02 12:00:00')))->toBeNull();
});

it('handles same-day windows and timezones', function () {
    $quiet = new QuietHours;

    expect($quiet->deferUntil(settings('13:00', '14:00'), now: Carbon::parse('2026-01-01 13:30:00'))?->toDateTimeString())->toBe('2026-01-01 14:00:00')
        ->and($quiet->deferUntil(settings('13:00', '14:00'), now: Carbon::parse('2026-01-01 14:00:00')))->toBeNull()
        // 09:00 UTC is 04:00 in New York — inside a 22:00–07:00 window there
        ->and($quiet->deferUntil(settings('22:00', '07:00', 'America/New_York'), now: Carbon::parse('2026-01-01 09:00:00'))?->toDateTimeString())->toBe('2026-01-01 12:00:00');
});

it('is a no-op without quiet hours', function () {
    expect((new QuietHours)->deferUntil(null))->toBeNull()
        ->and((new QuietHours)->deferUntil(new NotificationSetting))->toBeNull();
});
