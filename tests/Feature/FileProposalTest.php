<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Enums\FileOperation;
use BJTheCod3r\Docket\Enums\ProposalState;
use BJTheCod3r\Docket\Exceptions\InvalidProposalException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Testing\FakeAttachmentDriver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('staging');
    Storage::fake('public');
    config()->set('docket.proposals.staging_disk', 'staging');
});

it('stages a proposed avatar privately and promotes it on apply', function () {
    $user = $this->user();
    $current = $user->attach(UploadedFile::fake()->image('old.png', 10, 10), 'avatar');

    $proposal = $user->proposeChanges(
        changes: ['name' => 'B. Ajani'],
        files: ['avatar' => UploadedFile::fake()->image('new.png', 20, 20)],
        by: $user,
        reason: 'New headshot',
    );

    $staged = Attachment::find($proposal->file_changes['avatar'][0]['attachment_id']);

    expect($staged->isStaged())->toBeTrue()
        ->and($staged->disk)->toBe('staging')
        ->and($staged->collection)->toBe('proposed:avatar')
        ->and($staged->attachable->is($proposal->report))->toBeTrue()
        ->and($proposal->originalSnapshot()['avatar'])->toBe(['attachment_id' => $current->getKey(), 'checksum' => $current->checksum])
        ->and(Storage::disk('staging')->exists($staged->path))->toBeTrue();

    $proposal->approve(by: $this->user('Manager'));

    $staged->refresh();
    $live = $user->firstAttachmentIn('avatar');

    expect($proposal->state())->toBe(ProposalState::Applied)
        ->and($user->fresh()->name)->toBe('B. Ajani')
        ->and($live->is($staged))->toBeTrue()
        ->and($staged->isLive())->toBeTrue()
        ->and($staged->disk)->toBe('local')
        ->and($staged->collection)->toBe('avatar')
        ->and(Storage::disk('local')->exists($staged->path))->toBeTrue()
        ->and(Storage::disk('staging')->exists($staged->path))->toBeFalse()
        ->and($current->fresh()->isArchived())->toBeTrue();

    $proposal->revert();

    expect($user->firstAttachmentIn('avatar')->is($current))->toBeTrue()
        ->and($current->fresh()->isLive())->toBeTrue()
        ->and($staged->fresh()->isArchived())->toBeTrue()
        ->and($user->fresh()->name)->toBe('Ada');
});

it('detects file conflicts by checksum', function () {
    config()->set('docket.proposals.auto_apply_on_approval', false);
    $user = $this->user();
    $user->attach(UploadedFile::fake()->image('old.png', 10, 10), 'avatar');

    $proposal = $user->proposeChanges(files: ['avatar' => UploadedFile::fake()->image('new.png', 20, 20)]);
    $proposal->approve(by: $this->user('Manager'));

    // someone swaps the avatar while the proposal sits in review
    $user->attach(UploadedFile::fake()->image('other.png', 30, 30), 'avatar');
    $user->attachments()->collection('avatar')->live()->oldest()->first()->markArchived();

    expect($proposal->hasConflicts())->toBeTrue()
        ->and(array_keys($proposal->detectConflicts()))->toBe(['avatar'])
        ->and($proposal->apply())->toBeFalse()
        ->and($proposal->state())->toBe(ProposalState::Conflicted);

    $proposal->rebase();
    expect($proposal->apply())->toBeTrue();
});

it('validates proposed files against the field spec', function () {
    $this->user()->proposeChanges(files: ['avatar' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')]);
})->throws(\BJTheCod3r\Docket\Exceptions\AttachmentException::class, 'disallowed type');

it('rejects file fields that are not proposable', function () {
    $this->user()->proposeChanges(files: ['banner' => UploadedFile::fake()->image('x.png')]);
})->throws(\BJTheCod3r\Docket\Exceptions\NotProposableException::class);

it('handles add, remove and reorder on a multi-file collection', function () {
    $artist = $this->artist();
    $a = $artist->attach(UploadedFile::fake()->image('a.png'), 'artwork', ['order' => 0]);
    $b = $artist->attach(UploadedFile::fake()->image('b.png'), 'artwork', ['order' => 1]);

    $proposal = $artist->proposeChanges(files: [
        'artwork' => [
            ['op' => FileOperation::Add, 'file' => UploadedFile::fake()->image('c.png')],
            ['op' => 'remove', 'attachment' => $a],
            ['op' => 'reorder', 'order' => [$b->getKey()]],
        ],
    ], by: $this->user('Curator'));

    $proposal->approve(by: $this->user('Manager'));

    $live = $artist->attachmentsIn('artwork');

    expect($proposal->state())->toBe(ProposalState::Applied)
        ->and($live)->toHaveCount(2)
        ->and($live->pluck('name')->all())->toBe(['b.png', 'c.png'])
        ->and($a->fresh()->isArchived())->toBeTrue();

    $proposal->revert();

    $restored = $artist->attachmentsIn('artwork');
    expect($restored->pluck('name')->all())->toBe(['a.png', 'b.png']);
});

it('enforces the max count on multi-file fields', function () {
    $artist = $this->artist();
    $artist->attach([UploadedFile::fake()->image('a.png'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.png')], 'artwork');

    $artist->proposeChanges(files: ['artwork' => [['op' => 'add', 'file' => UploadedFile::fake()->image('d.png')]]]);
})->throws(InvalidProposalException::class, 'at most 3');

it('prunes staged and archived attachments past their windows', function () {
    $user = $this->user();
    $old = $user->attach(UploadedFile::fake()->image('old.png'), 'avatar');
    $proposal = $user->proposeChanges(files: ['avatar' => UploadedFile::fake()->image('new.png')]);
    $stagedId = $proposal->file_changes['avatar'][0]['attachment_id'];
    $proposal->withdraw();

    $abandoned = $user->proposeChanges(files: ['avatar' => UploadedFile::fake()->image('never.png')]);
    $abandonedId = $abandoned->file_changes['avatar'][0]['attachment_id'];
    $abandoned->approve(by: $this->user('M')); // applied; $old archived

    Carbon::setTestNow(now()->addDays(8));
    $this->artisan('docket:prune')->assertSuccessful();

    expect(Attachment::find($stagedId))->toBeNull()
        ->and(Attachment::find($abandonedId))->not->toBeNull()
        ->and($old->fresh()->isArchived())->toBeTrue();

    Carbon::setTestNow(now()->addDays(90));
    $this->artisan('docket:prune')->assertSuccessful();

    expect(Attachment::find($old->getKey()))->toBeNull()
        ->and(Storage::disk('local')->exists($old->path))->toBeFalse();

    Carbon::setTestNow();
});

it('keeps staged files of proposals still in review', function () {
    $user = $this->user();
    $proposal = $user->proposeChanges(files: ['avatar' => UploadedFile::fake()->image('new.png')]);
    $stagedId = $proposal->file_changes['avatar'][0]['attachment_id'];

    Carbon::setTestNow(now()->addDays(30));
    $this->artisan('docket:prune')->assertSuccessful();

    expect(Attachment::find($stagedId))->not->toBeNull()
        ->and((new Attachment)->prunable()->count())->toBe(0);

    Carbon::setTestNow();
});

it('runs file proposals through the fake driver without touching a disk', function () {
    Docket::attachmentDriver('fake', FakeAttachmentDriver::class);
    config()->set('docket.attachments.driver', 'fake');

    $user = $this->user();
    $current = $user->attach(UploadedFile::fake()->image('old.png'), 'avatar');
    $proposal = $user->proposeChanges(files: ['avatar' => UploadedFile::fake()->image('new.png')]);

    $proposal->approve(by: $this->user('Manager'));

    expect($proposal->state())->toBe(ProposalState::Applied)
        ->and($user->attachmentsIn('avatar')->sole()->name)->toBe('new.png')
        ->and($current->fresh()->isArchived())->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(Storage::disk('staging')->allFiles())->toBe([]);

    $proposal->revert();
    expect($user->attachmentsIn('avatar')->sole()->is($current))->toBeTrue();
});

it('removes a file given by attachment_id', function () {
    $artist = $this->artist();
    $a = $artist->attach(UploadedFile::fake()->image('a.png'), 'artwork');

    $proposal = $artist->proposeChanges(files: ['artwork' => [['op' => 'remove', 'attachment_id' => $a->getKey()]]]);
    $proposal->approve(by: $this->user('Manager'));

    expect($artist->attachmentsIn('artwork'))->toHaveCount(0);
});

it('checks every staged file exists before moving any of them', function () {
    $artist = $this->artist();
    $proposal = $artist->proposeChanges(files: ['artwork' => [
        ['op' => 'add', 'file' => UploadedFile::fake()->image('a.png')],
        ['op' => 'add', 'file' => UploadedFile::fake()->image('b.png')],
    ]]);
    [$first, $second] = collect($proposal->file_changes['artwork'])->pluck('attachment_id');
    Attachment::find($second)->delete();

    expect(fn () => $proposal->approve(by: $this->user('Manager')))->toThrow(InvalidProposalException::class, 'no longer exists')
        ->and($staged = Attachment::find($first))->not->toBeNull()
        ->and(Storage::disk($staged->disk)->exists($staged->path))->toBeTrue();
});

it('refuses to remove a file that belongs to another model', function () {
    $artist = $this->artist();
    $other = $this->artist('Wizkid');
    $theirs = $other->attach(UploadedFile::fake()->image('theirs.png'), 'artwork');

    $artist->proposeChanges(files: ['artwork' => [['op' => 'remove', 'attachment' => $theirs]]]);
})->throws(InvalidProposalException::class, 'is not a current file of [artwork]');
