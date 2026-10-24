<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests\Fixtures;

use BJTheCod3r\Docket\Contracts\VariantGenerator;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Support\Facades\Storage;

class CopyVariantGenerator implements VariantGenerator
{
    public function generate(Attachment $attachment, string $variant, array $spec): ?Attachment
    {
        $path = "{$attachment->path}-{$variant}";
        Storage::disk((string) $attachment->disk)->copy((string) $attachment->path, $path);

        $attachment->forceFill(['variants' => [...$attachment->variants ?? [], $variant => ['disk' => $attachment->disk, 'path' => $path]]])->save();

        return $attachment;
    }
}
