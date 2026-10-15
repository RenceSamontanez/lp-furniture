<?php

namespace App\Models\Concerns;

use App\Exceptions\ImmutableRecordException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent builder for models using ImmutableRecord: reads and inserts
 * work normally, every write-after-insert throws.
 */
class ImmutableBuilder extends Builder
{
    private function refuse(string $what): never
    {
        throw new ImmutableRecordException(
            class_basename($this->getModel()) . " is immutable: {$what}() is not allowed."
        );
    }

    public function update(array $values)
    {
        $this->refuse('update');
    }

    public function upsert(array $values, $uniqueBy, $update = null)
    {
        $this->refuse('upsert');
    }

    public function updateOrInsert(array $attributes, $values = [])
    {
        $this->refuse('updateOrInsert');
    }

    public function increment($column, $amount = 1, array $extra = [])
    {
        $this->refuse('increment');
    }

    public function decrement($column, $amount = 1, array $extra = [])
    {
        $this->refuse('decrement');
    }

    public function delete()
    {
        $this->refuse('delete');
    }

    public function forceDelete()
    {
        $this->refuse('forceDelete');
    }

    public function truncate()
    {
        $this->refuse('truncate');
    }
}
