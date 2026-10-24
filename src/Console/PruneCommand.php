<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Console;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Attachment;
use Illuminate\Console\Command;

class PruneCommand extends Command
{
    protected $signature = 'docket:prune
        {--staged= : Prune staged attachments older than this many days}
        {--archived= : Prune archived attachments older than this many days}
        {--dry-run : Report what would be pruned without deleting}';

    protected $description = 'Prune staged and archived attachments.';

    public function handle(): int
    {
        /** @var class-string<Attachment> $model */
        $model = Docket::model('attachment');

        $staged = (int) ($this->option('staged') ?? $model::stagedRetentionDays());
        $archived = (int) ($this->option('archived') ?? $model::archivedRetentionDays());
        $dryRun = (bool) $this->option('dry-run');

        $stagedQuery = $model::query()->staleStaged($staged);
        $archivedQuery = $model::query()->staleArchived($archived);

        $stagedCount = $stagedQuery->count();
        $archivedCount = $archivedQuery->count();

        if (! $dryRun) {
            $stagedQuery->eachById(fn (Attachment $attachment) => $attachment->delete());
            $archivedQuery->eachById(fn (Attachment $attachment) => $attachment->delete());
        }

        $verb = $dryRun ? 'Would prune' : 'Pruned';
        $this->components->info("{$verb} {$stagedCount} staged and {$archivedCount} archived attachment(s).");

        return self::SUCCESS;
    }
}
