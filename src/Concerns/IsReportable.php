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
trait IsReportable
{
    /** @return MorphMany<Report, $this> */
    public function subjectReports(): MorphMany
    {
        return StringKeyedMorphMany::on($this, Docket::model('report'), 'subject');
    }

    /**
     * Alias of subjectReports(); models with their own reports() relation (e.g. Service) may override it.
     *
     * @return MorphMany<Report, $this>
     */
    public function reports(): MorphMany
    {
        return $this->subjectReports();
    }

    /** @return MorphMany<Report, $this> */
    public function rootReports(): MorphMany
    {
        return $this->subjectReports()->roots();
    }

    /** @return MorphMany<Report, $this> */
    public function unresolvedReports(): MorphMany
    {
        return $this->subjectReports()->unresolved();
    }

    public function report(?string $body = null): ReportBuilder
    {
        $builder = Docket::report()->about($this);

        if ($body !== null) {
            $builder->body($body);
        }

        return $builder;
    }

    /**
     * Who is responsible for reports about this model. Override to customise;
     * by default an `owner` relation is used when the model defines one.
     */
    public function docketOwner(): ?Model
    {
        $owner = $this->getRelationValue('owner');

        return $owner instanceof Model ? $owner : null;
    }
}
