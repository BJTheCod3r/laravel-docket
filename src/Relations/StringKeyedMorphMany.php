<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A MorphMany onto a string `*_id` column. Laravel eager-loads integer parent keys
 * as raw integers, which Postgres refuses to compare with varchar; this binds them
 * as strings instead.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
class StringKeyedMorphMany extends MorphMany
{
    /**
     * @template TRelated of Model
     * @template TParent of Model
     *
     * @param  TParent  $parent
     * @param  class-string<TRelated>  $related
     * @return MorphMany<TRelated, TParent>
     */
    public static function on(Model $parent, string $related, string $name, bool $stringKeys = true): MorphMany
    {
        if (! $stringKeys) {
            return $parent->morphMany($related, $name);
        }

        $instance = new $related;

        if (! $instance->getConnectionName()) {
            $instance->setConnection($parent->getConnectionName());
        }

        return new self($instance->newQuery(), $parent, $instance->qualifyColumn("{$name}_type"), $instance->qualifyColumn("{$name}_id"), $parent->getKeyName());
    }

    public static function morphKeysAreStrings(): bool
    {
        return config('docket.morph_key_type') === 'string';
    }

    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }

    protected function getKeys(array $models, $key = null)
    {
        return array_map(fn ($value) => (string) $value, parent::getKeys($models, $key));
    }
}
