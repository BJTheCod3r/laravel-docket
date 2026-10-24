<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Attachments\Bindings;

use BJTheCod3r\Docket\Contracts\FileBinding;
use BJTheCod3r\Docket\Contracts\Proposable;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AttachmentBinding implements FileBinding
{
    public function current(Model $target, string $field): mixed
    {
        $query = $this->query($target, $field);

        return $this->isMultiple($target, $field) ? $query->get() : $query->first();
    }

    public function apply(Model $target, string $field, Attachment $attachment): void
    {
        if ($attachment->attachable_type !== $target->getMorphClass()
            || $attachment->attachable_id !== (string) $target->getKey()
            || $attachment->collection !== $field
            || ! $attachment->isLive()) {
            Docket::attachments()->promote($attachment, $target, $field);
        }

        if (! $this->isMultiple($target, $field)) {
            $this->query($target, $field)->whereKeyNot($attachment->getKey())->get()->each->markArchived();
        }
    }

    public function remove(Model $target, string $field, Attachment $attachment): void
    {
        $attachment->markArchived();
    }

    public function reorder(Model $target, string $field, array $attachmentIds): void
    {
        collect($attachmentIds)->values()->each(fn ($id, int $index) => $this->query($target, $field)->whereKey($id)->update(['order' => $index]));
    }

    /** @return Builder<Attachment> */
    protected function query(Model $target, string $field): Builder
    {
        /** @var class-string<Attachment> $class */
        $class = Docket::model('attachment');

        return $class::query()->for($target)->collection($field)->live()->orderBy('order')->orderBy('created_at');
    }

    protected function isMultiple(Model $target, string $field): bool
    {
        return $target instanceof Proposable && (bool) ($target->proposableFiles()[$field]['multiple'] ?? false);
    }
}
