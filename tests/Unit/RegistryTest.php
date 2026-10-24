<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Events\EventMap;
use BJTheCod3r\Docket\Events\ReportCreated;
use BJTheCod3r\Docket\Events\StatusChanged;
use BJTheCod3r\Docket\Exceptions\DocketException;
use BJTheCod3r\Docket\Exceptions\InvalidKindException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Notifications\ChannelResolver;

it('registers kinds with options', function () {
    $kind = Docket::kind('check_result', ['label' => 'Check result', 'rollup' => 'severest', 'notifies' => false]);

    expect($kind->label)->toBe('Check result')
        ->and(Docket::kinds()->has('check_result'))->toBeTrue()
        ->and(Docket::kinds()->all()->keys()->all())->toContain('report', 'comment', 'resolution', 'proposal', 'check_result')
        ->and(Docket::rollup()->strategyFor('check_result'))->toBeInstanceOf(\BJTheCod3r\Docket\Rollup\Severest::class);

    Docket::kinds()->get('nope');
})->throws(InvalidKindException::class);

it('maps events through config', function () {
    $map = app(EventMap::class);

    expect($map->resolve(StatusChanged::class))->toBe(StatusChanged::class);

    config()->set('docket.events', [StatusChanged::class => OurStatusChanged::class]);
    expect($map->resolve(StatusChanged::class))->toBe(OurStatusChanged::class);

    config()->set('docket.events', [StatusChanged::class => ReportCreated::class]);
    expect(fn () => $map->resolve(StatusChanged::class))->toThrow(DocketException::class, 'must extend');
});

it('resolves default channels with wildcard prefixes', function () {
    config()->set('docket.notifications.defaults', [
        '*' => ['channels' => ['database']],
        'docket.proposal.*' => ['channels' => ['mail']],
        'docket.report.created' => ['channels' => ['database', 'mail']],
    ]);

    $resolver = new ChannelResolver;

    expect($resolver->defaults('docket.report.created'))->toBe(['database', 'mail'])
        ->and($resolver->defaults('docket.proposal.approved'))->toBe(['mail'])
        ->and($resolver->defaults('docket.report.edited'))->toBe(['database']);
});

class OurStatusChanged extends StatusChanged
{
}
