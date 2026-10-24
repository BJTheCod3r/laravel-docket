<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Events\ReportAssigned;
use BJTheCod3r\Docket\Events\ReportEdited;
use BJTheCod3r\Docket\Events\ReportOwnerChanged;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Support\Facades\Event;

it('records a revision when editable fields change', function () {
    $editor = $this->user('Editor');
    $report = Docket::report()->title('Old')->body('x')->meta(['a' => 1])->create();

    $report->edit(['title' => 'New', 'meta' => ['a' => 2]], by: $editor, reason: 'Typo');

    $revision = $report->revisions()->first();

    expect($report->revisions)->toHaveCount(1)
        ->and($revision->changes)->toEqual(['title' => ['from' => 'Old', 'to' => 'New'], 'meta' => ['from' => ['a' => 1], 'to' => ['a' => 2]]])
        ->and($revision->reason)->toBe('Typo')
        ->and($revision->editor->is($editor))->toBeTrue()
        ->and($revision->changedFields())->toEqualCanonicalizing(['title', 'meta']);
});

it('attributes revisions to the authenticated user by default', function () {
    $editor = $this->user('Auth');
    $this->actingAs($editor);

    $report = Docket::report()->body('x')->create();
    $report->update(['description' => 'y']);

    expect($report->revisions()->first()->editor->is($editor))->toBeTrue();
});

it('fires ReportEdited with the revision, and assignment / owner events', function () {
    Event::fake([ReportEdited::class, ReportAssigned::class, ReportOwnerChanged::class]);

    $agent = $this->user('Agent');
    $team = $this->team();
    $report = Docket::report()->body('x')->create();

    $report->edit(['title' => 'T']);
    $report->assignTo($agent);
    $report->edit(['owner_type' => $team->getMorphClass(), 'owner_id' => $team->id]);

    Event::assertDispatched(ReportEdited::class, fn (ReportEdited $e) => $e->revision->changedFields() === ['title']);
    Event::assertDispatched(ReportAssigned::class, fn (ReportAssigned $e) => $e->assignee->is($agent) && $e->previousAssignee === null);
    Event::assertDispatched(ReportOwnerChanged::class, fn (ReportOwnerChanged $e) => $e->owner->is($team));
});

it('does not write a revision for untouched fields', function () {
    $report = Docket::report()->body('x')->create();
    $report->update(['priority' => 2]);

    expect($report->revisions)->toHaveCount(0);
});

it('maintains a meta index when enabled', function () {
    config()->set('docket.meta.index', true);

    $report = Docket::report()->body('x')->meta(['env' => 'prod', 'build' => 42, 'hot' => true, 'nested' => ['a' => 1]])->create();

    expect($report->metaIndex)->toHaveCount(3)
        ->and(Report::whereMeta('env', 'prod')->count())->toBe(1)
        ->and(Report::whereMeta('build', '>', 40)->count())->toBe(1)
        ->and(Report::whereMeta('hot', true)->count())->toBe(1)
        ->and(Report::whereMeta('env', 'staging')->count())->toBe(0);

    $report->edit(['meta' => ['env' => 'staging']]);

    expect($report->fresh()->metaIndex)->toHaveCount(1)
        ->and(Report::whereMeta('env', 'staging')->count())->toBe(1);
});

it('indexes long values by key and matches booleans and nulls like the JSON query', function () {
    config()->set('docket.meta.index', true);

    $long = Docket::report()->body('x')->meta(['trace' => str_repeat('x', 300), 'urgent' => true])->create();
    $plain = Docket::report()->body('y')->meta(['urgent' => false])->create();

    expect(Report::whereMeta('urgent', '!=', true)->pluck('id')->all())->toBe([$plain->getKey()])
        ->and(Report::whereMeta('trace', null)->pluck('id')->all())->toBe([$plain->getKey()])
        ->and(Report::whereMeta('trace', '!=', null)->pluck('id')->all())->toBe([$long->getKey()]);
});
