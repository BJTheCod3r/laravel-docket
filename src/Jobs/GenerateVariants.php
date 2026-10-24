<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Jobs;

use BJTheCod3r\Docket\Attachments\AttachmentManager;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateVariants implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Attachment $attachment)
    {
        $this->afterCommit();

        if (is_string($queue = config('docket.attachments.queue_variants'))) {
            $this->onQueue($queue);
        }
    }

    public function handle(AttachmentManager $manager): void
    {
        $manager->generateVariants($this->attachment);
    }
}
