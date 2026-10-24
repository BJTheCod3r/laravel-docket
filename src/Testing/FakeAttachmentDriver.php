<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Testing;

use BJTheCod3r\Docket\Attachments\AttachmentManager;
use BJTheCod3r\Docket\Contracts\AttachmentDriver;
use BJTheCod3r\Docket\Enums\AttachmentState;
use BJTheCod3r\Docket\Exceptions\AttachmentException;
use BJTheCod3r\Docket\Models\Attachment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Keeps bytes in memory so upload and file-proposal flows can be tested
 * without touching a disk. Register with Docket::attachmentDriver('fake', …)
 * and set docket.attachments.driver = 'fake'.
 */
class FakeAttachmentDriver implements AttachmentDriver
{
    /** @var array<string, string> path => contents */
    public array $files = [];

    /** @var array<int, string> */
    public array $deleted = [];

    public function __construct(protected AttachmentManager $manager) {}

    public function store(UploadedFile|string $file, Model $owner, string $collection, array $options = []): Attachment
    {
        [$size, $mime, $name] = $this->manager->inspect($file);
        $local = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $path = 'fake/'.$collection.'/'.Str::ulid();

        $this->files[$path] = is_string($local) && File::isFile($local) ? File::get($local) : '';

        return Attachment::createFor($owner, $collection, [
            'driver' => 'fake',
            'disk' => 'fake',
            'path' => $path,
            'name' => $options['name'] ?? $name,
            'mime' => $mime,
            'size' => $size,
            'checksum' => 'sha256:'.hash('sha256', $this->files[$path]),
            'order' => (int) ($options['order'] ?? 0),
            'meta' => $options['meta'] ?? null,
            'state' => $options['state'] ?? AttachmentState::Live->value,
        ]);
    }

    public function promote(Attachment $attachment, Model $owner, string $collection): Attachment
    {
        return $attachment->moveTo($owner, $collection, ['state' => AttachmentState::Live->value]);
    }

    public function url(Attachment $attachment, ?string $variant = null): string
    {
        return 'fake://'.$attachment->path.($variant ? "?variant={$variant}" : '');
    }

    public function temporaryUrl(Attachment $attachment, DateTimeInterface $expires, ?string $variant = null): string
    {
        return $this->url($attachment, $variant).($variant ? '&' : '?').'expires='.$expires->getTimestamp();
    }

    public function stream(Attachment $attachment, ?string $variant = null)
    {
        $stream = fopen('php://memory', 'r+');

        throw_unless(is_resource($stream), AttachmentException::class, 'Could not open an in-memory stream.');

        fwrite($stream, $this->files[$attachment->path] ?? '');
        rewind($stream);

        return $stream;
    }

    public function delete(Attachment $attachment): void
    {
        unset($this->files[$attachment->path]);
        $this->deleted[] = (string) $attachment->path;
    }

    public function has(Attachment $attachment): bool
    {
        return isset($this->files[$attachment->path]);
    }
}
