<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Proposals\ConflictDetector;

it('compares values loosely but safely', function () {
    $detector = app(ConflictDetector::class);

    expect($detector->same(1, '1'))->toBeTrue()
        ->and($detector->same(1.0, 1))->toBeTrue()
        ->and($detector->same(['b' => 1, 'a' => 2], ['a' => 2, 'b' => 1]))->toBeTrue()
        ->and($detector->same(null, ''))->toBeFalse()
        ->and($detector->same('a', 'b'))->toBeFalse()
        ->and($detector->same(\Carbon\Carbon::parse('2026-01-01'), '2026-01-01T00:00:00+00:00'))->toBeTrue();
});

it('compares text that starts with a date as text', function () {
    $detector = app(ConflictDetector::class);

    expect($detector->same('2024-05-01 12:00 outage postmortem', '2024-05-01 12:00 outage postmortem'))->toBeTrue()
        ->and($detector->same('2024-05-01 12:00 outage', '2024-05-01 12:00 incident'))->toBeFalse();
});
