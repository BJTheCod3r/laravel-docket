<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

trait ScopesMorphs
{
    /**
     * @param  Builder<static>  $query
     * @param  bool  $stringKey  Cast the key for tables that store morph ids as strings
     *                           (attachments, subscriptions) so Postgres never compares varchar to int.
     * @return Builder<static>
     */
    public function scopeWhereMorph(Builder $query, string $name, ?Model $model, bool $stringKey = false): Builder
    {
        if ($model === null) {
            return $query->whereNull("{$name}_type");
        }

        $key = $model->getKey();

        return $query
            ->where("{$name}_type", $model->getMorphClass())
            ->where("{$name}_id", $stringKey ? (string) $key : $key);
    }
}
