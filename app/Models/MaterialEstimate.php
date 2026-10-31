<?php

namespace App\Models;

use App\Support\MaterialLibrary;
use Illuminate\Database\Eloquent\Model;

/** One row of the furniture-type library: the six material numbers for one piece. */
class MaterialEstimate extends Model
{
    protected $fillable = [
        'name', 'wood', 'varnish', 'lacquer', 'nails', 'sand', 'glue',
        'starter_wood', 'starter_varnish', 'starter_lacquer', 'starter_nails', 'starter_sand', 'starter_glue',
        'is_edited', 'sort_order', 'ref_length_cm', 'ref_width_cm', 'ref_height_cm',
    ];

    protected $casts = [
        'is_edited' => 'boolean',
        'wood' => 'float', 'varnish' => 'float', 'lacquer' => 'float', 'nails' => 'float', 'sand' => 'float', 'glue' => 'float',
        'starter_wood' => 'float', 'starter_varnish' => 'float', 'starter_lacquer' => 'float',
        'starter_nails' => 'float', 'starter_sand' => 'float', 'starter_glue' => 'float',
        'ref_length_cm' => 'float', 'ref_width_cm' => 'float', 'ref_height_cm' => 'float',
    ];

    /** @return array<string, float> */
    public function values(): array
    {
        $out = [];
        foreach (array_keys(MaterialLibrary::FIELDS) as $k) {
            $out[$k] = (float) $this->{$k};
        }

        return $out;
    }

    /** The seeded numbers, or null for a type the admin added (nothing to reset to). */
    public function starterValues(): ?array
    {
        if ($this->starter_wood === null) {
            return null;
        }
        $out = [];
        foreach (array_keys(MaterialLibrary::FIELDS) as $k) {
            $out[$k] = (float) $this->{'starter_' . $k};
        }

        return $out;
    }

    public function isSeeded(): bool
    {
        return $this->starter_wood !== null;
    }

    public function hasReferenceSize(): bool
    {
        return $this->ref_length_cm !== null && $this->ref_width_cm !== null && $this->ref_height_cm !== null
            && $this->ref_length_cm > 0 && $this->ref_width_cm > 0 && $this->ref_height_cm > 0;
    }

    public function productEstimates()
    {
        return $this->hasMany(ProductMaterialEstimate::class, 'type_name', 'name');
    }
}
