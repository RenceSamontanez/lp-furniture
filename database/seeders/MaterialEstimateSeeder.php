<?php

namespace Database\Seeders;

use App\Models\MaterialEstimate;
use App\Models\Product;
use App\Models\ProductMaterialEstimate;
use App\Support\MaterialEstimator;
use App\Support\MaterialLibrary;
use Illuminate\Database\Seeder;

/**
 * Seeds the furniture-type library and gives every product an estimate. Safe to re-run:
 * a library row the admin has edited keeps its numbers (only its starter values and missing reference sizes are refreshed),
 * a product that already has an estimate keeps it. Run it with: php artisan materials:seed-estimates
 */
class MaterialEstimateSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedLibrary();
        $this->seedProducts();
    }

    public function seedLibrary(): void
    {
        $order = 0;
        foreach (MaterialLibrary::TYPES as $name => $row) {
            $order++;
            $values = array_combine(array_keys(MaterialLibrary::FIELDS), $row);
            $ref = MaterialLibrary::REF_DIMS[$name] ?? null;

            $type = MaterialEstimate::firstOrNew(['name' => $name]);
            foreach ($values as $k => $v) {
                $type->{'starter_' . $k} = $v;
                if (! $type->exists || ! $type->is_edited) {
                    $type->{$k} = $v;
                }
            }
            if (! $type->exists) {
                $type->is_edited = false;
            }
            $type->sort_order = $order;
            if ($ref && $type->ref_length_cm === null) {
                [$type->ref_length_cm, $type->ref_width_cm, $type->ref_height_cm] = $ref;
            }
            $type->save();
        }
    }

    public function seedProducts(): void
    {
        $named = [];
        foreach (MaterialLibrary::EXISTING_PRODUCTS as $name => $type) {
            $named[mb_strtolower($name)] = $type;
        }

        foreach (Product::query()->doesntHave('materialEstimate')->get() as $product) {
            $type = $named[mb_strtolower((string) $product->product_name)] ?? MaterialEstimator::matchType($product->product_name, $product->category);
            MaterialEstimator::createProductEstimate($product, $type);
        }
    }
}
