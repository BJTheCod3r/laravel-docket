<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Attachments;

use BJTheCod3r\Docket\Contracts\AttachmentDriver;
use BJTheCod3r\Docket\Contracts\VariantGenerator;
use BJTheCod3r\Docket\Exceptions\AttachmentException;
use BJTheCod3r\Docket\Jobs\GenerateVariants;
use BJTheCod3r\Docket\Models\Attachment;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AttachmentManager
{
    /** @var array<string, class-string<AttachmentDriver>|Closure> */
    protected array $drivers = [
        'filesystem' => FilesystemDriver::class,
    ];

    /** @var array<string, AttachmentDriver> */
    protected array $resolved = [];

    public function __construct(protected Container $container) {}

    /**
     * @param  class-string<AttachmentDriver>|Closure  $driver
     */
    public function extend(string $key, string|Closure $driver): static
    {
        $this->drivers[$key] = $driver;
        unset($this->resolved[$key]);

        return $this;
    }

    public function driver(?string $key = null): AttachmentDriver
    {
        $key ??= $this->defaultDriverKey();

        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $driver = $this->drivers[$key] ?? throw new AttachmentException("Attachment driver [{$key}] is not registered.");

        $instance = $driver instanceof Closure ? $driver($this->container) : $this->container->make($driver);

        if (! $instance instanceof AttachmentDriver) {
            throw new AttachmentException("Attachment driver [{$key}] must implement ".AttachmentDriver::class.'.');
        }

        return $this->resolved[$key] = $instance;
    }

    protected function defaultDriverKey(): string
    {
        return (string) config('docket.attachments.driver', 'filesystem');
    }

    /** @param  array<string, mixed>  $options  disk, state, order, meta, name, driver, mimes, max_kb, variants (bool) */
    public function store(UploadedFile|string $file, Model $owner, string $collection, array $options = []): Attachment
    {
        if (! config('docket.attachments.enabled', true)) {
            throw new AttachmentException('Attachments are disabled (docket.attachments.enabled).');
        }

        $this->validate($file, $options);

        $driverKey = $options['driver'] ?? $this->defaultDriverKey();

        $attachment = $this->driver($driverKey)->store($file, $owner, $collection, $options);

        if ($attachment->driver !== $driverKey) {
            $attachment->forceFill(['driver' => $driverKey])->save();
        }

        if ($options['variants'] ?? true) {
            $this->scheduleVariants($attachment);
        }

        return $attachment;
    }

    public function promote(Attachment $attachment, Model $owner, string $collection): Attachment
    {
        $promoted = $this->driver($attachment->driver)->promote($attachment, $owner, $collection);

        if (empty($promoted->variants)) {
            $this->scheduleVariants($promoted);
        }

        return $promoted;
    }

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws AttachmentException
     */
    public function validate(UploadedFile|string $file, array $options = []): void
    {
        $maxKb = (int) ($options['max_kb'] ?? config('docket.attachments.max_kb', 10240));
        $mimes = (array) ($options['mimes'] ?? config('docket.attachments.mimes', []));

        [$size, $mime, $name] = $this->inspect($file);

        if ($maxKb > 0 && $size > $maxKb * 1024) {
            throw new AttachmentException("File [{$name}] exceeds the maximum size of {$maxKb} KB.");
        }

        if ($mimes !== [] && ! $this->mimeAllowed($mime, $mimes)) {
            throw new AttachmentException("File [{$name}] has a disallowed type [{$mime}].");
        }
    }

    /**
     * Size in bytes, MIME type and file name, for drivers to record.
     *
     * @return array{0: int, 1: string, 2: string}
     */
    public function inspect(UploadedFile|string $file): array
    {
        if ($file instanceof UploadedFile) {
            return [
                (int) $file->getSize(),
                (string) ($file->getMimeType() ?: $file->getClientMimeType() ?: 'application/octet-stream'),
                (string) $file->getClientOriginalName(),
            ];
        }

        if (! File::isFile($file)) {
            throw new AttachmentException("File [{$file}] does not exist.");
        }

        return [File::size($file), File::mimeType($file) ?: 'application/octet-stream', File::basename($file)];
    }

    /**
     * @param  array<int, string>  $allowed  e.g. ['image/*', 'application/pdf']
     */
    protected function mimeAllowed(string $mime, array $allowed): bool
    {
        return Str::is($allowed, $mime);
    }

    /** @return array<string, array<string, mixed>> */
    protected function variantSpecs(string $collection): array
    {
        return (array) config("docket.attachments.variants.{$collection}", []);
    }

    public function scheduleVariants(Attachment $attachment): void
    {
        if (! $attachment->isImage()
            || $this->variantSpecs($attachment->collection) === []
            || ! $this->container->bound(VariantGenerator::class)) {
            return;
        }

        if (config('docket.attachments.queue_variants', true)) {
            GenerateVariants::dispatch($attachment);
        } else {
            $this->generateVariants($attachment);
        }
    }

    public function generateVariants(Attachment $attachment): Attachment
    {
        if (! $this->container->bound(VariantGenerator::class)) {
            return $attachment;
        }

        $generator = $this->container->make(VariantGenerator::class);

        collect($this->variantSpecs($attachment->collection))
            ->each(fn ($spec, $name) => $generator->generate($attachment, (string) $name, (array) $spec));

        return $attachment->refresh();
    }
}
