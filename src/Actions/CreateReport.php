<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Actions;

use BJTheCod3r\Docket\Builders\ReportBuilder;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

class CreateReport
{
    /**
     * @param  array<string, mixed>  $data
     * @param  UploadedFile|array<int, UploadedFile>|null  $attachments
     *
     * @throws AuthorizationException
     */
    public function handle(?Model $reporter, array $data, UploadedFile|array|null $attachments = null): Report
    {
        /** @var class-string<Report> $model */
        $model = Docket::model('report');

        Docket::authorize($reporter, 'create', $model);

        return Docket::report()
            ->from($reporter)
            ->title($data['title'] ?? null)
            ->body($data['description'])
            ->meta($data['meta'] ?? [])
            ->priority(isset($data['priority']) ? (int) $data['priority'] : null)
            ->attach($attachments)
            ->when($data['kind'] ?? null, fn (ReportBuilder $b, string $kind) => $b->kind($kind))
            ->when($data['visibility'] ?? null, fn (ReportBuilder $b, string $visibility) => $b->visibility($visibility))
            ->when($data['services'] ?? null, fn (ReportBuilder $b, array $services) => $b->services($services))
            ->when($data['parent'] ?? null, fn (ReportBuilder $b, string $parent) => $b->parent($model::query()->visibleTo($reporter)->byReferenceOrKey($parent)->firstOrFail()))
            ->create();
    }
}
