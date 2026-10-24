<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Describes where a proposable file field lives on a target model — a Docket
 * attachment collection, a path column, a media library — so the file
 * applier never has to know.
 */
interface FileBinding
{
    /** @return Attachment|Collection<int, Attachment>|null */
    public function current(Model $target, string $field): mixed;

    public function apply(Model $target, string $field, Attachment $attachment): void;

    public function remove(Model $target, string $field, Attachment $attachment): void;

    /** @param  array<int, string|int>  $attachmentIds */
    public function reorder(Model $target, string $field, array $attachmentIds): void;
}
