<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Exceptions\AttachmentException;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Jobs\GenerateVariants;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Testing\FakeAttachmentDriver;
use BJTheCod3r\Docket\Tests\Fixtures\CopyVariantGenerator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => Storage::fake('local'));

it('stores attachments through the filesystem driver with a checksum', function () {
    $report = Docket::report()->body('Damaged on arrival')
        ->attach([UploadedFile::fake()->image('photo.jpg', 100, 80), UploadedFile::fake()->create('note.txt', 1, 'text/plain')])
        ->create();

    expect($report->attachments)->toHaveCount(2);

    $photo = $report->attachments->firstWhere('name', 'photo.jpg');

    expect($photo->driver)->toBe('filesystem')
        ->and($photo->disk)->toBe('local')
        ->and($photo->path)->toStartWith('docket/attachments/'.now()->format('Y/m').'/')
        ->and($photo->mime)->toBe('image/jpeg')
        ->and($photo->size)->toBeGreaterThan(0)
        ->and($photo->checksum)->toStartWith('sha256:')
        ->and($photo->meta)->toMatchArray(['width' => 100, 'height' => 80])
        ->and($photo->isLive())->toBeTrue()
        ->and($photo->isImage())->toBeTrue()
        ->and(Storage::disk('local')->exists($photo->path))->toBeTrue()
        ->and($photo->url())->toContain($photo->path)
        ->and(is_resource($photo->stream()))->toBeTrue();
});

it('stores a file from a local path', function () {
    $file = sys_get_temp_dir().'/docket-'.uniqid().'.txt';
    file_put_contents($file, 'from disk');

    $attachment = Docket::report()->body('x')->create()->attach($file);

    expect($attachment->name)->toBe(basename($file))
        ->and($attachment->mime)->toBe('text/plain')
        ->and($attachment->path)->toEndWith('.txt')
        ->and(Storage::disk('local')->get($attachment->path))->toBe('from disk')
        ->and($attachment->checksum)->toBe('sha256:'.hash('sha256', 'from disk'));

    @unlink($file);
});

it('validates size and mime type', function () {
    config()->set('docket.attachments.max_kb', 1);
    $report = Docket::report()->body('x')->create();

    expect(fn () => $report->attach(UploadedFile::fake()->create('big.pdf', 5, 'application/pdf')))
        ->toThrow(AttachmentException::class, 'exceeds');

    config()->set('docket.attachments.max_kb', 1024);

    expect(fn () => $report->attach(UploadedFile::fake()->create('evil.exe', 1, 'application/x-msdownload')))
        ->toThrow(AttachmentException::class, 'disallowed');
});

it('deletes the bytes with the row', function () {
    $report = Docket::report()->body('x')->create();
    $attachment = $report->attach(UploadedFile::fake()->image('a.png'), 'evidence');
    $path = $attachment->path;

    expect($report->attachmentsIn('evidence'))->toHaveCount(1);

    $attachment->delete();

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($report->attachments()->count())->toBe(0);
});

it('generates variants through the bound generator', function () {
    app()->singleton(\BJTheCod3r\Docket\Contracts\VariantGenerator::class, CopyVariantGenerator::class);
    config()->set('docket.attachments.variants.attachments', ['thumb' => ['width' => 32]]);

    $report = Docket::report()->body('x')->create();
    $attachment = $report->attach(UploadedFile::fake()->image('a.png'))->fresh();
    $thumb = $attachment->variants['thumb']['path'];

    expect($attachment->hasVariant('thumb'))->toBeTrue()
        ->and($attachment->url('thumb'))->toContain('-thumb')
        ->and(Storage::disk('local')->exists($thumb))->toBeTrue();

    $attachment->delete();
    expect(Storage::disk('local')->exists($thumb))->toBeFalse();
});

it('keeps existing variants on promote and queues generation on the attachments queue', function () {
    app()->singleton(\BJTheCod3r\Docket\Contracts\VariantGenerator::class, CopyVariantGenerator::class);
    config()->set('docket.attachments.variants', ['attachments' => ['thumb' => []], 'gallery' => ['thumb' => []]]);

    $report = Docket::report()->body('x')->create();
    $attachment = $report->attach(UploadedFile::fake()->image('a.png'))->fresh();

    Bus::fake([GenerateVariants::class]);
    config()->set('docket.attachments.queue_variants', 'media');

    $promoted = Docket::attachments()->promote($attachment, Docket::report()->body('y')->create(), 'gallery');
    Bus::assertNotDispatched(GenerateVariants::class);
    expect(Storage::disk('local')->exists($promoted->variants['thumb']['path']))->toBeTrue();

    $report->attach(UploadedFile::fake()->image('b.png'));
    Bus::assertDispatched(GenerateVariants::class, fn (GenerateVariants $job) => $job->queue === 'media');
});

it('queues no variant jobs without a generator', function () {
    Bus::fake([GenerateVariants::class]);
    config()->set('docket.attachments.variants.attachments', ['thumb' => ['width' => 32]]);

    Docket::report()->body('x')->create()->attach(UploadedFile::fake()->image('a.png'));

    Bus::assertNotDispatched(GenerateVariants::class);
});

it('falls back to the original for a missing variant', function () {
    $report = Docket::report()->body('x')->create();
    $attachment = $report->attach(UploadedFile::fake()->create('doc.pdf', 1, 'application/pdf'));

    expect($attachment->url('thumb'))->toBe($attachment->url());
});

it('serves downloads with range support and streams other drivers', function () {
    Storage::fake('local');
    $report = Docket::report()->body('x')->attach(UploadedFile::fake()->createWithContent('notes.txt', 'hello world'))->create();
    $attachment = $report->attachments->first();

    $request = \Illuminate\Http\Request::create('/', 'GET', server: ['HTTP_RANGE' => 'bytes=0-4']);
    $response = $attachment->response(disposition: 'attachment')->prepare($request);

    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class)
        ->and($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('Content-Range'))->toBe('bytes 0-4/11')
        ->and($response->headers->get('Content-Disposition'))->toContain('attachment; filename=notes.txt');

    Docket::attachmentDriver('fake', FakeAttachmentDriver::class);
    config()->set('docket.attachments.driver', 'fake');
    $faked = Docket::report()->body('y')->attach(UploadedFile::fake()->createWithContent('a.txt', 'bytes'))->create()->attachments->first();

    $streamed = $faked->response();
    ob_start();
    $streamed->sendContent();

    expect($streamed)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class)
        ->and(ob_get_clean())->toBe('bytes');
});

it('supports a custom driver such as the fake', function () {
    Docket::attachmentDriver('fake', FakeAttachmentDriver::class);
    config()->set('docket.attachments.driver', 'fake');

    $report = Docket::report()->body('x')->attach(UploadedFile::fake()->image('a.png'))->create();
    $attachment = $report->attachments->first();

    /** @var FakeAttachmentDriver $driver */
    $driver = Docket::attachments()->driver('fake');

    expect($attachment->driver)->toBe('fake')
        ->and($driver->has($attachment))->toBeTrue()
        ->and($attachment->url())->toStartWith('fake://')
        ->and($driver->temporaryUrl($attachment, now()))->toMatch('#^fake://[^?&]+\?expires=\d+$#')
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    $attachment->delete();
    expect($driver->deleted)->toHaveCount(1);
});

it('can index rows for bytes owned elsewhere', function () {
    $report = Docket::report()->body('x')->create();

    $attachment = Attachment::createFor($report, 'attachments', [
        'driver' => 'filesystem',
        'external_type' => 'media',
        'external_id' => '42',
        'name' => 'x.png',
        'mime' => 'image/png',
        'size' => 10,
    ]);

    expect($attachment->attachable->is($report))->toBeTrue()->and($attachment->external_id)->toBe('42');
});

it('eager loads string morph relations from integer-keyed models', function () {
    $user = $this->user();
    $order = \BJTheCod3r\Docket\Tests\Fixtures\Order::create(['number' => 'A-1']);
    $user->attach(UploadedFile::fake()->image('a.png'), 'avatar');
    Docket::report()->about($order)->body('x')->create();

    expect(\BJTheCod3r\Docket\Tests\Fixtures\User::with('attachments')->find($user->id)->attachments)->toHaveCount(1)
        ->and(\BJTheCod3r\Docket\Tests\Fixtures\Order::with('reports')->find($order->id)->reports)->toHaveCount(1);
});

it('shows only live attachments on a report', function () {
    $report = Docket::report()->body('x')->visibility('public')->create();
    $live = $report->attach(UploadedFile::fake()->image('live.png'));
    $report->attach(UploadedFile::fake()->image('old.png'))->markArchived();

    $shown = app(\BJTheCod3r\Docket\Actions\ShowReport::class)->handle($report->reference, null);

    expect($shown->attachments->pluck('id')->all())->toBe([$live->getKey()]);
});
