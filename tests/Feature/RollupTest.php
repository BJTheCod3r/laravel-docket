<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;

it('rolls the severest child status up to the parent by default', function () {
    $root = Docket::report()->body('incident')->create();
    $a = $root->reply('check a failing', kind: 'report');
    $b = $root->reply('check b failing', kind: 'report');

    $a->transitionTo('closed');
    expect($root->fresh()->status)->toBe('open');

    $b->transitionTo('in_progress');
    expect($root->fresh()->status)->toBe('in_progress');

    $b->transitionTo('closed');
    expect($root->fresh()->status)->toBe('closed');

    $transition = $root->transitions()->latest('created_at')->first();
    expect($transition->via_rollup)->toBeTrue();
});

it('mirrors the latest resolution child', function () {
    $root = Docket::report()->body('x')->create();

    $root->reply('rolled back the deploy', kind: 'resolution', status: 'closed');

    expect($root->fresh()->status)->toBe('closed')
        ->and($root->fresh()->isClosed())->toBeTrue();
});

it('never lets comments affect the parent', function () {
    $root = Docket::report()->body('x')->create();
    $comment = $root->comment('note');

    $comment->transitionTo('closed');

    expect($root->fresh()->status)->toBe('open');
});

it('does not let an open comment hold its parent open', function () {
    $root = Docket::report()->body('x')->create();
    $root->comment('note');
    $check = $root->reply('check failing', kind: 'report');

    $check->transitionTo('closed');

    expect($root->fresh()->status)->toBe('closed');
});

it('recalculates the parent when a child is deleted', function () {
    $root = Docket::report()->body('incident')->create();
    $a = $root->reply('check a', kind: 'report');
    $b = $root->reply('check b', kind: 'report');

    $a->transitionTo('closed');
    expect($root->fresh()->status)->toBe('open');

    $b->delete();

    expect($root->fresh()->status)->toBe('closed');
});

it('supports the all_terminal strategy', function () {
    config()->set('docket.rollup.default', 'all_terminal');

    $root = Docket::report()->body('x')->create();
    $a = $root->reply('a', kind: 'report');
    $b = $root->reply('b', kind: 'report');

    $a->transitionTo('closed');
    expect($root->fresh()->status)->toBe('open');

    $b->transitionTo('closed');
    expect($root->fresh()->status)->toBe('closed');
});

it('rolls up through multiple levels without looping', function () {
    $root = Docket::report()->body('x')->create();
    $mid = $root->reply('mid', kind: 'report');
    $leaf = $mid->reply('leaf', kind: 'report');

    $leaf->transitionTo('closed');

    expect($mid->fresh()->status)->toBe('closed')
        ->and($root->fresh()->status)->toBe('closed');

    $leaf->transitionTo('reopened');

    expect($mid->fresh()->status)->toBe('reopened')
        ->and($root->fresh()->status)->toBe('reopened');
});

it('can be switched off for bulk work', function () {
    $root = Docket::report()->body('x')->create();
    $child = $root->reply('a', kind: 'report');

    Docket::rollup()->withoutRollup(fn () => $child->transitionTo('closed'));

    expect($root->fresh()->status)->toBe('open');
});

it('accepts a custom strategy class', function () {
    config()->set('docket.rollup.default', AlwaysInProgress::class);

    $root = Docket::report()->body('x')->create();
    $root->reply('a', kind: 'report')->transitionTo('closed');

    expect($root->fresh()->status)->toBe('in_progress');
});

class AlwaysInProgress implements \BJTheCod3r\Docket\Contracts\RollupStrategy
{
    public function resolve(Report $parent, Report $child, \Illuminate\Database\Eloquent\Collection $children): ?string
    {
        return 'in_progress';
    }
}

it('rolls up even when the replying user may not transition the parent', function () {
    \Illuminate\Support\Facades\Gate::policy(Report::class, RollupTransitionDenyPolicy::class);
    $customer = \BJTheCod3r\Docket\Tests\Fixtures\User::create(['name' => 'Customer', 'email' => 'customer@example.com']);
    $ticket = Docket::report()->body('x')->create();

    $ticket->reply('Works now, thanks', kind: 'resolution', status: 'closed', by: $customer);

    expect($ticket->fresh()->isClosed())->toBeTrue();
});

it('updates the parent object the caller holds when a reply rolls it up', function () {
    $ticket = Docket::report()->body('x')->create();

    $ticket->reply('Fixed', kind: 'resolution', status: 'closed');

    expect($ticket->status)->toBe('closed');

    $ticket->transitionTo('reopened');
    expect($ticket->fresh()->status)->toBe('reopened');
});

class RollupTransitionDenyPolicy
{
    public function transition($user, Report $report, string $to): bool
    {
        return false;
    }
}
