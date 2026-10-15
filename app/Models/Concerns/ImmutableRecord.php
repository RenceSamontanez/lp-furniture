<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;

/**
 * Makes a model insert-only at the application layer.
 *
 * Two layers, both throwing ImmutableRecordException:
 *   1. model events — any $model->save() on an existing row (even with
 *      no changes), ->update(), ->touch(), ->delete(), ->forceDelete();
 *   2. an Eloquent builder that refuses update()/delete()/upsert()/
 *      increment()/decrement()/forceDelete()/truncate() — so
 *      Model::query()->where(...)->update([...]) can't slip past the
 *      model events.
 *
 * What this cannot stop is raw SQL or DB::table(...). That is why the
 * agreement also carries RESTRICT foreign keys, has no update/delete
 * routes, and stores a SHA-256 of its terms that is re-checked
 * whenever the agreement is read.
 */
trait ImmutableRecord
{
    public static function bootImmutableRecord(): void
    {
        static::updating(function ($model) {
            throw new ImmutableRecordException(
                class_basename($model) . ' #' . $model->getKey() . ' is immutable and cannot be updated.'
            );
        });

        static::deleting(function ($model) {
            throw new ImmutableRecordException(
                class_basename($model) . ' #' . $model->getKey() . ' is immutable and cannot be deleted.'
            );
        });
    }

    /**
     * Eloquent only fires the "updating" event when attributes are
     * dirty, so a no-change save()/touch() would slip past the events.
     * Refuse every save() of an already-stored row outright; inserting
     * a new row (exists === false) is unaffected.
     */
    public function save(array $options = [])
    {
        if ($this->exists) {
            throw new ImmutableRecordException(
                class_basename($this) . ' #' . $this->getKey() . ' is immutable and cannot be saved again.'
            );
        }

        return parent::save($options);
    }

    public function newEloquentBuilder($query)
    {
        return new ImmutableBuilder($query);
    }
}
