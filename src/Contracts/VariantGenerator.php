<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Contracts;

use BJTheCod3r\Docket\Models\Attachment;

interface VariantGenerator
{
    /**
     * Generate a named variant (e.g. "thumb") for the attachment and record it
     * in $attachment->variants. Return null when the attachment isn't eligible.
     *
     * @param  array{width?: int, height?: int, fit?: string, quality?: int}  $spec
     */
    public function generate(Attachment $attachment, string $variant, array $spec): ?Attachment;
}
