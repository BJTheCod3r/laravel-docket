<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Models\Service;
use BJTheCod3r\Docket\Notifications\ReportNotification;
use BJTheCod3r\Docket\Notifications\StatusChangedNotification;
use BJTheCod3r\Docket\Tests\Fixtures\Order;
use PHPUnit\Framework\AssertionFailedError;

it('records reports, transitions, notifications and proposals', function () {
    Docket::fake();

    $owner = $this->user('Owner');
    $other = $this->user('Other');
    $order = Order::create(['number' => 'A1']);

    $report = Docket::report()->about($order)->to($owner)->body('x')->create();
    $report->transitionTo('closed');

    Docket::assertReported();
    Docket::assertReported(fn (Report $r) => $r->subject->is($order) && $r->kind === 'report');
    Docket::assertReportedTimes(1);
    Docket::assertNotReported(fn (Report $r) => $r->kind === 'comment');
    Docket::assertStatusChanged($report, from: 'open', to: 'closed');
    Docket::assertDispatched('docket.report.closed');
    Docket::assertNotDispatched('docket.report.reopened');
    Docket::assertNotified($owner, ReportNotification::class);
    Docket::assertNotified($owner, StatusChangedNotification::class);
    Docket::assertNotNotified($other);
    Docket::assertNotNotified($owner, \BJTheCod3r\Docket\Notifications\ProposalNotification::class);

    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 1]);
    Docket::assertProposalNotApplied($proposal);
    $proposal->approve(by: $owner);
    Docket::assertProposalApplied($proposal);

    expect(fn () => Docket::assertNothingReported())->toThrow(AssertionFailedError::class);
    expect(fn () => Docket::assertStatusNotChanged($report))->toThrow(AssertionFailedError::class);
});

it('records suppressions and conflicts', function () {
    Docket::fake();
    config()->set('docket.proposals.auto_apply_on_approval', false);

    $owner = $this->user('Owner');
    $service = Service::factory()->create();
    Docket::mute($service)->for(hours: 1);

    Docket::report()->services([$service])->to($owner)->body('x')->create();

    Docket::assertSuppressed(reason: 'muted');
    Docket::assertNothingNotified();

    $product = $this->product();
    $proposal = $product->proposeChanges(changes: ['price' => 1]);
    $proposal->approve(by: $owner);
    $product->update(['price' => 7]);
    $proposal->apply();

    Docket::assertProposalConflicted($proposal);
});

it('starts empty', function () {
    Docket::fake();

    Docket::assertNothingReported();
    Docket::assertNothingNotified();
});
