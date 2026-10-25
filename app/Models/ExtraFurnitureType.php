<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A quick-pick chip in the customer's "Something else" panel (see the extra_furniture_types migration). */
class ExtraFurnitureType extends Model
{
    public const NAME_MAX = 60;
    public const CHIPS_SHOWN = 8;

    protected $fillable = ['name', 'name_key', 'sort_order', 'is_active', 'source_idea_id'];

    protected $casts = ['is_active' => 'boolean'];

    public static function keyFor(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    /** The names the customer page offers, in the admin's order. */
    public static function chips(): array
    {
        return static::where('is_active', true)->orderBy('sort_order')->orderBy('id')
            ->limit(self::CHIPS_SHOWN)->pluck('name')->all();
    }

    /** True when a name is already a built-in tile or an extra type (any case). */
    public static function isTaken(string $name, ?int $exceptId = null): bool
    {
        $key = self::keyFor($name);
        foreach (\App\Support\FurnitureTypes::CHOICES as $value => $label) {
            if ($key === self::keyFor($value) || $key === self::keyFor($label)) {
                return true;
            }
        }

        return static::where('name_key', $key)->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }
}
