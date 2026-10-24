<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Concerns;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;

/** @mixin Model */
trait HasAttachments
{
    /** @return MorphMany<Attachment, $this> */
    public function attachments(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('attachment'), 'attachable')->orderBy('order')->orderBy('created_at');
    }

    /**
     * @param  UploadedFile|string|array<int, UploadedFile|string>  $files
     * @param  array<string, mixed>  $options
     * @return Attachment|Collection<int, Attachment>
     */
    public function attach(UploadedFile|string|array $files, string $collection = 'attachments', array $options = []): Attachment|Collection
    {
        if (is_array($files)) {
            return Collection::make($files)
                ->map(fn ($file) => Docket::attachments()->store($file, $this, $collection, $options))
                ->values();
        }

        return Docket::attachments()->store($files, $this, $collection, $options);
    }

    /**
     * @return Collection<int, Attachment>
     */
    public function attachmentsIn(string $collection): Collection
    {
        return $this->attachments()->collection($collection)->live()->get();
    }

    public function firstAttachmentIn(string $collection): ?Attachment
    {
        return $this->attachments()->collection($collection)->live()->first();
    }
}
