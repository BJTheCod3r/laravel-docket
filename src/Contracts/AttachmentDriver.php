<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Attachment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

interface AttachmentDriver
{
    /**
     * @param  UploadedFile|string  $file  An upload, or an absolute path on the local filesystem.
     * @param  array<string, mixed>  $options  disk, state, order, meta, name
     */
    public function store(UploadedFile|string $file, Model $owner, string $collection, array $options = []): Attachment;

    /** Move an attachment to a new owner/collection — a staged proposal file becomes the live avatar. */
    public function promote(Attachment $attachment, Model $owner, string $collection): Attachment;

    public function url(Attachment $attachment, ?string $variant = null): string;

    public function temporaryUrl(Attachment $attachment, DateTimeInterface $expires, ?string $variant = null): string;

    /** @return resource|null */
    public function stream(Attachment $attachment, ?string $variant = null);

    public function delete(Attachment $attachment): void;
}
