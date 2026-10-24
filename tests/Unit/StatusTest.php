<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Enums\StatusCategory;
use BJTheCod3r\Docket\Statuses\CoreStatus;
use BJTheCod3r\Docket\Statuses\StatusDefinition;

it('ranks categories from least to most resolved', function () {
    expect(StatusCategory::Open->rank())->toBeLessThan(StatusCategory::Active->rank())
        ->and(StatusCategory::Active->rank())->toBeLessThan(StatusCategory::Resolved->rank())
        ->and(StatusCategory::Resolved->rank())->toBeLessThan(StatusCategory::Closed->rank())
        ->and(StatusCategory::Open->isTerminal())->toBeFalse()
        ->and(StatusCategory::Closed->isTerminal())->toBeTrue()
        ->and(StatusCategory::fromMixed('resolved'))->toBe(StatusCategory::Resolved);
});

it('builds definitions from enums and arrays', function () {
    $core = StatusDefinition::fromStatus(CoreStatus::Closed);
    $custom = StatusDefinition::fromArray('awaiting_vendor', ['category' => 'active', 'transitions' => ['closed']]);
    $wild = StatusDefinition::fromArray('anything', ['category' => 'open']);

    expect($core->isTerminal())->toBeTrue()
        ->and($core->allowsTransitionTo('reopened'))->toBeTrue()
        ->and($core->allowsTransitionTo('open'))->toBeFalse()
        ->and($custom->label)->toBe('Awaiting vendor')
        ->and($custom->isTerminal())->toBeFalse()
        ->and($custom->withTransitions(['closed', 'open'])->transitions)->toBe(['closed', 'open'])
        ->and($wild->allowsTransitionTo('whatever'))->toBeTrue()
        ->and($core->toArray()['category'])->toBe('closed')
        ->and(json_encode($core))->toContain('"key":"closed"');
});
