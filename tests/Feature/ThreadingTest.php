<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Enums\Visibility;
use BJTheCod3r\Docket\Events\ChildReportAdded;
use BJTheCod3r\Docket\Exceptions\DocketException;
use BJTheCod3r\Docket\Exceptions\MaxDepthExceededException;
use BJTheCod3r\Docket\Exceptions\ReportLockedException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

it('threads comments beneath a root report', function () {
    $agent = $this->user('Agent');
    $root = Docket::report()->body('Checkout down')->create();

    $comment = $root->comment('Reproduced on staging.', by: $agent);
    $nested = $comment->comment('Also on prod.');

    expect($comment->kind)->toBe('comment')
        ->and($comment->parent_id)->toBe($root->getKey())
        ->and($comment->root_id)->toBe($root->getKey())
        ->and($comment->depth)->toBe(1)
        ->and($comment->reporter->is($agent))->toBeTrue()
        ->and($nested->root_id)->toBe($root->getKey())
        ->and($nested->depth)->toBe(2)
        ->and($root->children)->toHaveCount(1)
        ->and($root->descendants)->toHaveCount(2)
        ->and($root->fullThread())->toHaveCount(3)
        ->and(Report::thread($root)->count())->toBe(3)
        ->and(Report::roots()->count())->toBe(1);
});

it('inherits subject, owner and visibility from the parent', function () {
    $team = $this->team();
    $order = \BJTheCod3r\Docket\Tests\Fixtures\Order::create(['number' => 'A9']);
    $root = Docket::report()->about($order)->to($team)->visibility(Visibility::Public)->body('x')->create();

    $child = $root->reply('resolved it', kind: 'resolution');

    expect($child->subject->is($order))->toBeTrue()
        ->and($child->owner->is($team))->toBeTrue()
        ->and($child->visibility)->toBe('public');
});

it('fires ChildReportAdded on the parent', function () {
    Event::fake([ChildReportAdded::class]);

    $root = Docket::report()->body('x')->create();
    $child = $root->comment('y');

    Event::assertDispatched(ChildReportAdded::class, fn (ChildReportAdded $e) => $e->report()->is($root) && $e->child->is($child));
});

it('guards against runaway nesting', function () {
    config()->set('docket.reports.max_depth', 1);

    $root = Docket::report()->body('x')->create();
    $child = $root->comment('y');

    $child->comment('z');
})->throws(MaxDepthExceededException::class);

it('refuses children on a locked report', function () {
    $root = Docket::report()->body('x')->create()->lock();

    expect($root->isLocked())->toBeTrue();

    $root->comment('nope');
})->throws(ReportLockedException::class);

it('walks ancestor owners nearest first', function () {
    $teamA = $this->team('A');
    $teamB = $this->team('B');

    $root = Docket::report()->to($teamA)->body('x')->create();
    $mid = $root->newChild()->to($teamB)->body('y')->create();
    $leaf = $mid->comment('z');

    $owners = $leaf->ancestorOwners(2);

    expect($owners)->toHaveCount(2)
        ->and($owners[0]->is($teamB))->toBeTrue()
        ->and($owners[1]->is($teamA))->toBeTrue();
});

it('soft deletes and restores replies with their report', function () {
    $root = Docket::report()->body('x')->create();
    $comment = $root->comment('a');
    $nested = $comment->comment('b');
    $removedEarlier = $root->comment('c');

    $removedEarlier->delete();
    $this->travel(1)->minute();

    $root->delete();

    expect(Report::count())->toBe(0)
        ->and(Report::onlyTrashed()->count())->toBe(4);

    $this->travel(1)->minute();
    $root->restore();

    expect(Report::pluck('id')->sort()->values()->all())
        ->toBe(collect([$root, $comment, $nested])->map->getKey()->sort()->values()->all())
        ->and($removedEarlier->fresh()->trashed())->toBeTrue();

    $root->forceDelete();

    expect(Report::withTrashed()->count())->toBe(0);
});

it('removes the whole thread and its files on force delete', function () {
    Storage::fake('local');

    $root = Docket::report()->body('x')->create();
    $comment = $root->comment('y');
    $nested = $comment->comment('z');
    $comment->delete();

    $files = collect([$root, $comment->fresh(), $nested->fresh()])
        ->map(fn (Report $report) => $report->attach(UploadedFile::fake()->create('a.txt', 1, 'text/plain'))->path);

    $root->forceDelete();

    expect(Report::withTrashed()->count())->toBe(0)
        ->and(Attachment::count())->toBe(0)
        ->and($files->filter(fn (string $path) => Storage::disk('local')->exists($path)))->toBeEmpty();
});

it('refuses to move a report to another parent', function () {
    $first = Docket::report()->body('x')->create();
    $second = Docket::report()->body('y')->create();
    $reply = $first->comment('z');

    expect(fn () => $reply->update(['parent_id' => $second->getKey()]))->toThrow(DocketException::class);
});

it('refuses a parent it cannot resolve', function () {
    $root = Docket::report()->body('x')->create();
    $root->delete();

    Report::create(['description' => 'orphan', 'parent_id' => $root->getKey()]);
})->throws(DocketException::class, 'does not exist');

it('fixes root_id and depth at creation', function () {
    $root = Docket::report()->body('x')->create();
    $child = $root->comment('y');

    $child->update(['depth' => 0]);
})->throws(DocketException::class, 'fixed at creation');

it('rejects an unregistered status at creation', function () {
    Report::create(['description' => 'x', 'status' => 'bogus']);
})->throws(\BJTheCod3r\Docket\Exceptions\InvalidStatusException::class);
