<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Models\Mute;
use Illuminate\Support\Carbon;

it('decides coverage from event keys, kinds, channels and priority', function () {
    $mute = new Mute(['event_keys' => ['docket.report.created'], 'kinds' => ['check_result'], 'channels' => ['mail'], 'min_priority' => 1, 'behaviour' => 'drop', 'direction' => 'about']);

    expect($mute->covers('docket.report.created', 'check_result', 3, 'mail'))->toBeTrue()
        ->and($mute->covers('docket.report.edited', 'check_result'))->toBeFalse()
        ->and($mute->covers('docket.report.created', 'comment'))->toBeFalse()
        ->and($mute->covers('docket.report.created', 'check_result', 1))->toBeFalse()
        ->and($mute->covers('docket.report.created', 'check_result', null, 'slack'))->toBeFalse()
        ->and($mute->drops())->toBeTrue()
        ->and($mute->isGlobal())->toBeTrue();
});

it('knows whether it is active', function () {
    $mute = new Mute(['starts_at' => Carbon::parse('2026-01-01 10:00'), 'ends_at' => Carbon::parse('2026-01-01 12:00')]);
    $open = new Mute(['starts_at' => Carbon::parse('2026-01-01 10:00'), 'ends_at' => null]);

    expect($mute->isActive(Carbon::parse('2026-01-01 11:00')))->toBeTrue()
        ->and($mute->isActive(Carbon::parse('2026-01-01 12:00')))->toBeFalse()
        ->and($open->isActive(Carbon::parse('2030-01-01')))->toBeTrue();
});
