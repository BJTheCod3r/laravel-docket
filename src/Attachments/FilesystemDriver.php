<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Attachments;

use BJTheCod3r\Docket\Contracts\AttachmentDriver;
use BJTheCod3r\Docket\Enums\AttachmentState;
use BJTheCod3r\Docket\Exceptions\AttachmentException;
use BJTheCod3r\Docket\Models\Attachment;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class FilesystemDriver implements AttachmentDriver
{
    public function __construct(protected AttachmentManager $manager) {}

    public function store(UploadedFile|string $file, Model $owner, string $collection, array $options = []): Attachment
    {
        $disk = (string) ($options['disk'] ?? config('docket.attachments.disk', 'local'));
        [$size, $mime, $name] = $this->manager->inspect($file);

        $localPath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $extension = $file instanceof UploadedFile
            ? ($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin')
            : (File::extension($file) ?: 'bin');

        $path = Storage::disk($disk)->putFileAs($this->directory($collection), $file, (string) Str::ulid().'.'.strtolower($extension))
            ?: throw new AttachmentException("Could not store [{$name}] on disk [{$disk}].");

        $meta = (array) ($options['meta'] ?? []);

        if (Str::startsWith($mime, 'image/') && is_string($localPath) && ($dimensions = @getimagesize($localPath)) !== false) {
            $meta += ['width' => $dimensions[0], 'height' => $dimensions[1]];
        }

        return Attachment::createFor($owner, $collection, [
            'driver' => 'filesystem',
            'disk' => $disk,
            'path' => $path,
            'name' => $options['name'] ?? $name,
            'mime' => $mime,
            'size' => $size,
            'checksum' => is_string($localPath) ? 'sha256:'.File::hash($localPath, 'sha256') : null,
            'meta' => $meta ?: null,
            'order' => (int) ($options['order'] ?? 0),
            'state' => $options['state'] ?? AttachmentState::Live->value,
        ]);
    }

    public function promote(Attachment $attachment, Model $owner, string $collection): Attachment
    {
        $liveDisk = (string) config('docket.attachments.disk', 'local');
        $attributes = ['state' => AttachmentState::Live->value];
        $fromDisk = $attachment->disk ?? throw new AttachmentException("Attachment [{$attachment->getKey()}] has no disk.");

        $directory = $this->directory($collection);
        $newPath = $directory.'/'.basename((string) $attachment->path);

        if ($attachment->disk !== $liveDisk || $attachment->path !== $newPath) {
            $this->move($fromDisk, (string) $attachment->path, $liveDisk, $newPath);

            $attributes['variants'] = collect($attachment->variants)->map(function (array $variant) use ($directory, $liveDisk) {
                $variantPath = $directory.'/'.basename($variant['path']);
                $this->move($variant['disk'], $variant['path'], $liveDisk, $variantPath);

                return ['disk' => $liveDisk, 'path' => $variantPath];
            })->all() ?: null;

            $attributes['disk'] = $liveDisk;
            $attributes['path'] = $newPath;
        }

        return $attachment->moveTo($owner, $collection, $attributes);
    }

    public function url(Attachment $attachment, ?string $variant = null): string
    {
        [$disk, $path] = $this->locate($attachment, $variant);

        return Storage::disk($disk)->url($path);
    }

    public function temporaryUrl(Attachment $attachment, DateTimeInterface $expires, ?string $variant = null): string
    {
        [$disk, $path] = $this->locate($attachment, $variant);

        return Storage::disk($disk)->temporaryUrl($path, Carbon::instance($expires));
    }

    public function stream(Attachment $attachment, ?string $variant = null)
    {
        [$disk, $path] = $this->locate($attachment, $variant);

        return Storage::disk($disk)->readStream($path);
    }

    /**
     * Files on a local disk get a BinaryFileResponse, which honours Range requests; other disks stream.
     */
    public function response(Attachment $attachment, ?string $variant = null, string $disposition = 'inline'): Response
    {
        [$disk, $path] = $this->locate($attachment, $variant);
        $filesystem = Storage::disk($disk);

        if ($filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            return (new BinaryFileResponse($filesystem->path($path), 200, ['Content-Type' => $attachment->mime], false))
                ->setContentDisposition($disposition, $attachment->name, Str::ascii($attachment->name));
        }

        return $filesystem->response($path, $attachment->name, ['Content-Type' => $attachment->mime], $disposition);
    }

    public function delete(Attachment $attachment): void
    {
        if ($attachment->disk && $attachment->path) {
            Storage::disk($attachment->disk)->delete($attachment->path);
        }

        collect($attachment->variants)->each(fn (array $variant) => Storage::disk($variant['disk'])->delete($variant['path']));
    }

    public function directory(string $collection): string
    {
        $now = Carbon::now();

        return trim(strtr((string) config('docket.attachments.path', 'docket/{collection}/{Y}/{m}'), [
            '{collection}' => str_replace(':', '/', $collection),
            '{Y}' => $now->format('Y'),
            '{m}' => $now->format('m'),
            '{d}' => $now->format('d'),
        ]), '/');
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function locate(Attachment $attachment, ?string $variant): array
    {
        if ($variant !== null && isset($attachment->variants[$variant])) {
            return [$attachment->variants[$variant]['disk'], $attachment->variants[$variant]['path']];
        }

        return [(string) $attachment->disk, (string) $attachment->path];
    }

    protected function move(string $fromDisk, string $fromPath, string $toDisk, string $toPath): void
    {
        $source = Storage::disk($fromDisk);

        if (($fromDisk === $toDisk && $fromPath === $toPath) || ! $source->exists($fromPath)) {
            return;
        }

        if ($fromDisk === $toDisk) {
            $source->move($fromPath, $toPath);

            return;
        }

        $stream = $source->readStream($fromPath);

        throw_unless(is_resource($stream), AttachmentException::class, "Could not read [{$fromDisk}:{$fromPath}].");

        Storage::disk($toDisk)->writeStream($toPath, $stream);
        fclose($stream);

        $source->delete($fromPath);
    }
}
