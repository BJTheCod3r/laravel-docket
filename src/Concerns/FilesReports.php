<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Concerns;

use BJTheCod3r\Docket\Builders\ReportBuilder;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use BJTheCod3r\Docket\Relations\StringKeyedMorphMany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @mixin Model
 */
trait FilesReports
{
    /** @return MorphMany<Report, $this> */
    public function filedReports(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('report'), 'reporter', StringKeyedMorphMany::morphKeysAreStrings());
    }

    public function fileReport(?Model $about = null): ReportBuilder
    {
        $builder = Docket::report()->from($this);

        if ($about !== null) {
            $builder->about($about);
        }

        return $builder;
    }
}
