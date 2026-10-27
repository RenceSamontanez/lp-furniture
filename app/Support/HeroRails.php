<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * The two product rails on the home page hero (desktop only).
 *
 * Best sellers reuse ProductController::home()'s definition (available products ordered by how many order items they
 * have) but only keep products that were actually ordered: the home page falls back to newest products when nothing was
 * ever ordered, and a rail tagged "Best seller" must not show products that never sold. "Hot · New" is the newest
 * available products. Visibility is the catalog's rule (Product::available()). One query per rail; images are
 * eager-loaded, so there is no per-product query.
 */
class HeroRails
{
    public const LIMIT = 8;

    /** A rail with fewer cards than this is not shown (it would not scroll honestly). */
    public const MIN_TO_SHOW = 3;

    /** @return array{best: Collection, fresh: Collection} each a collection of card arrays, empty when the rail is not shown */
    public static function get(): array
    {
        $best = Product::available()
            ->with('primaryImage')
            ->withCount('orderItems')
            ->orderByDesc('order_items_count')
            ->orderByDesc('id')
            ->take(self::LIMIT)
            ->get()
            ->filter(fn (Product $p) => $p->order_items_count > 0);

        $fresh = Product::available()
            ->with('primaryImage')
            ->latest()
            ->orderByDesc('id')
            ->take(self::LIMIT)
            ->get();

        return [
            'best'  => self::cards($best, 'Best seller'),
            'fresh' => self::cards($fresh, 'New'),
        ];
    }

    private static function cards(Collection $products, string $tag): Collection
    {
        $cards = $products->map(fn (Product $p) => [
            'id'    => $p->id,
            'name'  => $p->product_name,
            'price' => $p->on_sale ? $p->formatted_sale_price : $p->formatted_price,
            'image' => $p->image_url,
            'url'   => route('product.show', $p->id),
            'tag'   => $tag,
        ])->values();

        return $cards->count() >= self::MIN_TO_SHOW ? $cards : collect();
    }
}
