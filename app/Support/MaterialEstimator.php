<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\Inventory;
use App\Models\MaterialEstimate;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductMaterialEstimate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Works out how much of each inventory material an order uses, and picks the starter furniture type for a product.
 * The matcher below is the single source: the product form gets the same lists as JSON (see matcherData()).
 */
final class MaterialEstimator
{
    /**
     * First match wins: a case-insensitive match on the product name that starts at the beginning of a word (a trailing space is
     * added, so "tv " matches "TV Stand"). Starting at a word keeps "Rocking Chair" from matching "king" and "Outdoor" from matching "door".
     */
    public const KEYWORDS = [
        'king' => 'Bed, King', 'queen' => 'Bed, Queen', 'bunk' => 'Bunk Bed', 'crib' => 'Crib / Baby Bed',
        'headboard' => 'Headboard', 'bedside' => 'Bedside Table', 'wardrobe' => 'Wardrobe (4-Door)',
        'dresser' => 'Dresser / Drawer Chest', 'vanity' => 'Vanity Table', 'tv ' => 'Cabinet / TV Stand',
        'console' => 'Console Table', 'china' => 'Display / China Cabinet', 'kitchen' => 'Kitchen Cabinet (Set)',
        'shoe' => 'Shoe Rack', 'bookcase' => 'Shelf / Bookcase', 'dining set' => 'Dining Set (Table + 6 Chairs)',
        'coffee' => 'Coffee Table', 'study' => 'Desk / Study Table', 'desk' => 'Desk / Study Table',
        'reception' => 'Reception / Counter Desk', 'bar stool' => 'Bar Stool', 'bar counter' => 'Bar Counter',
        'rocking' => 'Rocking Chair', 'office chair' => 'Office Chair (Wood Frame)', 'stool' => 'Stool',
        'bench' => 'Bench', 'ottoman' => 'Ottoman / Footstool', 'sala' => 'Sala Set (5-Piece)',
        'loveseat' => 'Sofa, 2-Seater', 'altar' => 'Altar', 'window' => 'Window Frame', 'door' => 'Door',
    ];

    public const CATEGORY_FALLBACK = [
        'Bed' => 'Bed, Queen', 'Cabinet' => 'Cabinet / TV Stand', 'Chair' => 'Chair', 'Sofa' => 'Sofa, 3-Seater (Frame)',
        'Table' => 'Dining Table', 'Altar' => 'Altar', 'Door' => 'Door', 'Shelf' => 'Shelf / Bookcase', 'Desk' => 'Desk / Study Table',
    ];

    /** What a custom request's furniture type means in the library (anything else, including "Other", is Other / Unknown). */
    public const CUSTOM_TYPES = [
        'Chair' => 'Chair', 'Table' => 'Dining Table', 'Desk' => 'Desk / Study Table', 'Bed' => 'Bed, Queen',
        'Sofa' => 'Sofa, 3-Seater (Frame)', 'Cabinet' => 'Cabinet / TV Stand', 'Shelf / Bookcase' => 'Shelf / Bookcase',
        'Door' => 'Door', 'Wardrobe' => 'Wardrobe (4-Door)', 'TV stand' => 'Cabinet / TV Stand', 'Bench' => 'Bench', 'Stool' => 'Stool',
    ];

    public const FACTOR_MIN = 0.6;
    public const FACTOR_MAX = 1.8;

    /* ───────────── the starter matcher (shared with the product form) ───────────── */

    public static function matchType(?string $name, ?string $category = null): string
    {
        $n = mb_strtolower((string) $name) . ' ';
        foreach (self::KEYWORDS as $needle => $type) {
            if (preg_match('/(?<![a-z])' . preg_quote($needle, '/') . '/u', $n)) {
                return $type;
            }
        }

        return self::CATEGORY_FALLBACK[$category ?? ''] ?? MaterialLibrary::OTHER;
    }

    /** The same rules and the library numbers, for the product form's script. */
    public static function matcherData(): array
    {
        return [
            'keywords' => self::KEYWORDS,
            'categories' => self::CATEGORY_FALLBACK,
            'other' => MaterialLibrary::OTHER,
            'types' => MaterialEstimate::orderBy('sort_order')->orderBy('name')->get()
                ->mapWithKeys(fn (MaterialEstimate $t) => [$t->name => $t->values()])->all(),
        ];
    }

    /** The library values for a type name (the row if it exists, else the built-in numbers, else Other / Unknown). */
    public static function typeValues(string $type): array
    {
        $row = MaterialEstimate::where('name', $type)->first();
        if ($row) {
            return $row->values();
        }

        return MaterialLibrary::values($type) ?? (MaterialEstimate::where('name', MaterialLibrary::OTHER)->first()?->values() ?? MaterialLibrary::values(MaterialLibrary::OTHER));
    }

    public static function createProductEstimate(Product $product, ?string $type = null, ?array $values = null): ProductMaterialEstimate
    {
        $type = $type ?: self::matchType($product->product_name, $product->category);
        $starter = self::typeValues($type);
        $values = $values ?? $starter;
        $edited = false;
        $clean = [];
        foreach ($starter as $k => $v) {
            $clean[$k] = round(max(0, (float) ($values[$k] ?? $v)), 2);
            if (abs($clean[$k] - (float) $v) > 0.004) {
                $edited = true;
            }
        }

        return ProductMaterialEstimate::updateOrCreate(['product_id' => $product->id], ['type_name' => $type, 'is_edited' => $edited] + $clean);
    }

    /** A product always has an estimate: made from the starter on first use if the admin never touched the section. */
    public static function forProduct(Product $product): ProductMaterialEstimate
    {
        return $product->materialEstimate ?? self::createProductEstimate($product);
    }

    /* ───────────── wood to inventory row ───────────── */

    private static function woodKey(?string $wood): string
    {
        $k = mb_strtolower(trim((string) $wood));
        $k = preg_replace('/^wood\s*\((.*)\)$/u', '$1', $k);
        $k = preg_replace('/\s+wood$/u', '', $k);

        return str_replace('gemelina', 'gmelina', trim($k));      // Gmelina = Gemelina
    }

    /** The "Wood (X)" inventory row for a wood name, or null when the shop does not stock it. */
    public static function woodRow(?string $wood): ?Inventory
    {
        $key = self::woodKey($wood);
        if ($key === '') {
            return null;
        }
        foreach (Inventory::where('material_name', 'like', 'Wood (%')->orderBy('id')->get() as $row) {
            if (self::woodKey($row->material_name) === $key) {
                return $row;
            }
        }

        return null;
    }

    private static function consumableRow(string $key): ?Inventory
    {
        $name = MaterialLibrary::CONSUMABLE_ROWS[$key];
        $row = Inventory::whereRaw('LOWER(material_name) = ?', [mb_strtolower($name)])->first();
        if (! $row && Cache::add('materials-missing-row:' . $key, 1, 86400)) {
            Log::warning("Materials: no inventory row named \"{$name}\"; that consumable is not deducted.");
        }

        return $row;
    }

    /* ───────────── the plan for one order ───────────── */

    /**
     * @return array{lines: array<int, array{inventory: Inventory, quantity: float, label: string}>, unmapped: string[], customer_wood: bool}
     */
    public static function planFor(Order $order): array
    {
        $totals = [];       // inventory id => [Inventory, qty]
        $unmapped = [];
        $customerWood = false;

        $add = function (?Inventory $row, float $qty) use (&$totals) {
            if ($row && $qty > 0) {
                $totals[$row->id] ??= [$row, 0.0];
                $totals[$row->id][1] += $qty;
            }
        };

        if ($order->design_agreement_id !== null) {
            $request = $order->design_request_id ? CustomDesignRequest::find($order->design_request_id) : null;
            $type = self::customType($request);
            $values = self::typeValues($type);
            $factor = self::sizeFactor($request, $type);
            $customerWood = $request && $request->wood_supply === WoodChoices::SUPPLY_CUSTOMER;

            if (! $customerWood) {
                $wood = $request?->preferred_material;
                if ($wood === WoodChoices::SPECIAL) {
                    $wood = $request->wood_special_details;
                }
                $row = self::woodRow($wood);
                if ($row) {
                    $add($row, $values['wood'] * $factor);
                } else {
                    $unmapped[] = trim((string) $wood) !== '' ? trim((string) $wood) : 'Unknown';
                }
            }
            foreach (array_keys(MaterialLibrary::CONSUMABLE_ROWS) as $k) {
                $add(self::consumableRow($k), $values[$k] * $factor);
            }
        } else {
            foreach ($order->items()->whereNotNull('product_id')->get() as $item) {
                $product = Product::find($item->product_id);
                if (! $product) {
                    continue;
                }
                $est = self::forProduct($product)->values();
                $qty = max(1, (int) $item->quantity);
                $row = self::woodRow($product->material);
                if ($row) {
                    $add($row, $est['wood'] * $qty);
                } else {
                    $unmapped[] = trim((string) $product->material) !== '' ? trim((string) $product->material) : 'Unknown';
                }
                foreach (array_keys(MaterialLibrary::CONSUMABLE_ROWS) as $k) {
                    $add(self::consumableRow($k), $est[$k] * $qty);
                }
            }
        }

        $lines = [];
        foreach ($totals as [$row, $qty]) {
            $lines[] = ['inventory' => $row, 'quantity' => round($qty, 2), 'label' => $row->material_name];
        }

        return ['lines' => $lines, 'unmapped' => array_values(array_unique($unmapped)), 'customer_wood' => $customerWood];
    }

    public static function customType(?CustomDesignRequest $request): string
    {
        $mapped = self::CUSTOM_TYPES[$request?->furniture_type ?? ''] ?? MaterialLibrary::OTHER;

        return MaterialEstimate::where('name', $mapped)->exists() ? $mapped : MaterialLibrary::OTHER;
    }

    /** requested volume / the type's reference volume, kept between 0.6 and 1.8; 1 when either size is missing. */
    public static function sizeFactor(?CustomDesignRequest $request, string $type): float
    {
        $ref = MaterialEstimate::where('name', $type)->first();
        $d = $request?->dimensions_cm;
        if (! $ref || ! $ref->hasReferenceSize() || ! $d || $d['length'] <= 0 || $d['width'] <= 0 || $d['height'] <= 0) {
            return 1.0;
        }
        $ratio = ($d['length'] * $d['width'] * $d['height']) / ($ref->ref_length_cm * $ref->ref_width_cm * $ref->ref_height_cm);

        return max(self::FACTOR_MIN, min(self::FACTOR_MAX, $ratio));
    }
}
