<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Enums\StatusCategory;
use BJTheCod3r\Docket\Events\ReportClosed;
use BJTheCod3r\Docket\Events\ReportReopened;
use BJTheCod3r\Docket\Events\StatusChanged;
use BJTheCod3r\Docket\Exceptions\DirectStatusUpdateException;
use BJTheCod3r\Docket\Exceptions\InvalidStatusException;
use BJTheCod3r\Docket\Exceptions\InvalidTransitionException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Statuses\CoreStatus;
use Illuminate\Support\Facades\Event;

it('transitions along the core map and writes the audit trail', function () {
    $user = $this->user();
    $report = Docket::report()->body('x')->create();

    $report->transitionTo('in_progress', by: $user, reason: 'Picked up by on-call');

    expect($report->status)->toBe('in_progress')
        ->and($report->statusCategory()->value)->toBe('active')
        ->and($report->previous_status)->toBe('open')
        ->and($report->status_changed_at)->not->toBeNull()
        ->and($report->isActive())->toBeTrue();

    $transition = $report->transitions()->first();

    expect($transition->from)->toBe('open')
        ->and($transition->to)->toBe('in_progress')
        ->and($transition->from_category)->toBe('open')
        ->and($transition->to_category)->toBe('active')
        ->and($transition->reason)->toBe('Picked up by on-call')
        ->and($transition->actor->is($user))->toBeTrue();
});

it('rejects transitions the map does not allow', function () {
    $report = Docket::report()->body('x')->create();

    expect($report->canTransitionTo('reopened'))->toBeFalse()
        ->and($report->tryTransitionTo('reopened'))->toBeFalse()
        ->and($report->status)->toBe('open');

    $report->transitionTo('reopened');
})->throws(InvalidTransitionException::class);

it('rejects unknown statuses', function () {
    Docket::report()->body('x')->create()->transitionTo('nonsense');
})->throws(InvalidTransitionException::class, 'not registered');

it('sets and clears the terminal timestamps', function () {
    $report = Docket::report()->body('x')->create();

    $report->transitionTo(CoreStatus::Closed);
    expect($report->closed_at)->not->toBeNull()->and($report->isClosed())->toBeTrue()->and($report->isTerminal())->toBeTrue();

    $report->transitionTo('reopened');
    expect($report->closed_at)->toBeNull()->and($report->isOpen())->toBeTrue();
});

it('fires category-based events', function () {
    Event::fake([StatusChanged::class, ReportClosed::class, ReportReopened::class]);

    $report = Docket::report()->body('x')->create();
    $report->transitionTo('closed');
    $report->transitionTo('reopened');

    Event::assertDispatched(StatusChanged::class, 2);
    Event::assertDispatched(ReportClosed::class);
    Event::assertDispatched(ReportReopened::class);
});

it('composes guards and surfaces their message', function () {
    Docket::guard(to: 'closed', using: function (Report $report) {
        return $report->children()->unresolved()->doesntExist()
            ?: 'Cannot close while child reports are still open.';
    });

    $root = Docket::report()->body('x')->create();
    $root->reply('still looking', kind: 'report');

    expect($root->checkTransition('closed'))->toBe('Cannot close while child reports are still open.')
        ->and($root->canTransitionTo('closed'))->toBeFalse();

    expect(fn () => $root->transitionTo('closed'))
        ->toThrow(InvalidTransitionException::class, 'Cannot close while child reports are still open.');
});

it('refuses direct writes to the status column', function () {
    $report = Docket::report()->body('x')->create();

    $report->update(['status' => 'closed']);
})->throws(DirectStatusUpdateException::class);

it('merges config-defined statuses into the registry', function () {
    config()->set('docket.statuses.custom', [
        'awaiting_vendor' => ['label' => 'Awaiting vendor', 'category' => 'active', 'transitions' => ['in_progress', 'closed'], 'color' => '#f59e0b'],
    ]);
    Docket::statuses()->flush()->allow('in_progress', 'awaiting_vendor');

    $definition = Docket::statuses()->get('awaiting_vendor');

    expect($definition->category)->toBe(StatusCategory::Active)
        ->and($definition->label)->toBe('Awaiting vendor')
        ->and($definition->source)->toBe('config');

    $report = Docket::report()->body('x')->create();
    $report->transitionTo('in_progress')->transitionTo('awaiting_vendor');

    expect($report->statusCategory()->value)->toBe('active')
        ->and($report->isActive())->toBeTrue()
        ->and(Report::active()->count())->toBe(1);
});

it('registers statuses at runtime', function () {
    Docket::statuses()->register(
        key: 'legal_review',
        label: 'Legal review',
        category: StatusCategory::Active,
        transitions: ['closed'],
    );
    Docket::statuses()->allow('open', 'legal_review');

    $report = Docket::report()->body('x')->create();
    $report->transitionTo('legal_review');

    expect($report->isActive())->toBeTrue()
        ->and(Docket::statuses()->canTransition('legal_review', 'closed'))->toBeTrue()
        ->and(Docket::statuses()->canTransition('legal_review', 'open'))->toBeFalse();
});

it('throws for unknown status lookups', function () {
    Docket::statuses()->get('nope');
})->throws(InvalidStatusException::class);

it('uses a kind default status when configured', function () {
    Docket::kind('incident', ['default_status' => 'in_progress']);

    $report = Docket::report()->kind('incident')->body('x')->create();

    expect($report->status)->toBe('in_progress')->and(Docket::kinds()->get('incident')->label)->toBe('Incident');
});

it('checks a policy transition method when one exists', function () {
    \Illuminate\Support\Facades\Gate::policy(Report::class, ReportDenyPolicy::class);

    $user = $this->user();
    $report = Docket::report()->body('x')->create();

    expect($report->canTransitionTo('closed'))->toBeTrue()
        ->and($report->canTransitionTo('closed', $user))->toBeFalse();
});

class ReportDenyPolicy
{
    public function transition($user, Report $report, string $to): bool
    {
        return false;
    }
}

it('refuses a transition from a stale copy of the report', function () {
    $report = Docket::report()->body('x')->create();
    $stale = Report::find($report->getKey());

    $report->transitionTo('closed');

    expect(fn () => $stale->transitionTo('in_progress'))->toThrow(InvalidTransitionException::class, 'since it was loaded')
        ->and($report->fresh()->status)->toBe('closed');
});

it('honours a status that declares itself terminal', function () {
    Docket::statuses()->register('on_hold', category: 'active', isTerminal: true);
    Docket::statuses()->allow('open', 'on_hold');

    $report = Docket::report()->body('x')->create()->transitionTo('on_hold');

    expect($report->isTerminal())->toBeTrue()
        ->and(Report::unresolved()->whereKey($report->getKey())->exists())->toBeFalse();
});

it('only treats a value as a key when it could be one', function () {
    config()->set('docket.key_type', 'uuid');
    $report = new Report;

    expect($report->couldBeKey('DKT-2026-000001'))->toBeFalse()
        ->and($report->couldBeKey((string) \Illuminate\Support\Str::uuid()))->toBeTrue();
});
