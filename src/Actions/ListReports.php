<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Actions;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ListReports
{
    /** @return LengthAwarePaginator<int, Report> */
    public function handle(?Model $viewer, ?string $category = null, ?string $kind = null, int $perPage = 25): LengthAwarePaginator
    {
        /** @var class-string<Report> $model */
        $model = Docket::model('report');

        return $model::query()
            ->roots()
            ->visibleTo($viewer)
            ->when($category, fn (Builder $q, string $category) => $q->category($category))
            ->when($kind, fn (Builder $q, string $kind) => $q->kind($kind))
            ->latest()
            ->paginate($perPage);
    }
}
