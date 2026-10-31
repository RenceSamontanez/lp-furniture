<?php

namespace App\Models;

use App\Support\MaterialLibrary;
use Illuminate\Database\Eloquent\Model;

/** What one unit of a product uses. Saved per product, so a later change to the library never rewrites it. */
class ProductMaterialEstimate extends Model
{
    protected $fillable = ['product_id', 'type_name', 'wood', 'varnish', 'lacquer', 'nails', 'sand', 'glue', 'is_edited'];

    protected $casts = [
        'is_edited' => 'boolean',
        'wood' => 'float', 'varnish' => 'float', 'lacquer' => 'float', 'nails' => 'float', 'sand' => 'float', 'glue' => 'float',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /** @return array<string, float> */
    public function values(): array
    {
        $out = [];
        foreach (array_keys(MaterialLibrary::FIELDS) as $k) {
            $out[$k] = (float) $this->{$k};
        }

        return $out;
    }
}
