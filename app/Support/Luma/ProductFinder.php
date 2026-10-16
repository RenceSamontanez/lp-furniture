<?php

namespace App\Support\Luma;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * Luma's view of the live catalog: which furniture type a question is about, the available products of
 * that type, their current prices (the sale price while a sale is on) and the cards and links built from them.
 * Only real rows are used; nothing here makes up a product or a number.
 */
final class ProductFinder
{
    /** The furniture type named in the message (a key of config('luma.types')), or null. Longer names win. */
    public static function detectType(string $message): ?string
    {
        $words = Text::words($message);
        $types = array_keys(config('luma.types', []));
        usort($types, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($types as $type) {
            if (Text::containsSequence($words, Text::words($type))) {
                return $type;
            }
        }

        return null;
    }

    public static function isType(?string $type): bool
    {
        return $type !== null && array_key_exists($type, config('luma.types', []));
    }

    public static function label(string $type, string $lang): string
    {
        return config("luma.types.{$type}." . ($lang === Language::TL ? 'tl' : 'en'), $type);
    }

    /**
     * Available products of a type (all available products when no type), featured first, then newest.
     *
     * @return Collection<int, Product>
     */
    public static function available(?string $type = null): Collection
    {
        $products = Product::available()->orderByDesc('is_featured')->orderByDesc('id')->get();

        return $type === null ? $products : $products->filter(fn (Product $p) => self::isOfType($p, $type))->values();
    }

    /** True when the product's category or name says it is this type ("4-Door Wardrobe" is a wardrobe, not a door). */
    public static function isOfType(Product $product, string $type): bool
    {
        $needle = Text::words($type);
        $name   = preg_replace('/\b\d+\s*-?\s*(door|drawer)s?\b/i', ' ', (string) $product->product_name);

        return Text::containsSequence(Text::words((string) $product->category), $needle)
            || Text::containsSequence(Text::words((string) $name), $needle);
    }

    /** What the customer pays now: the sale price while a sale is on, otherwise the price. */
    public static function price(Product $product): float
    {
        return (float) ($product->on_sale ? $product->sale_price : $product->price);
    }

    /** "PHP 5,000" (cents only when there are some) */
    public static function money(float $amount): string
    {
        return 'PHP ' . number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array{id: int, name: string, price: float, price_label: string, old_price_label: ?string, image: string, url: string}>
     */
    public static function cards(Collection $products, ?int $limit = null): array
    {
        $limit ??= (int) config('luma.max_cards', 4);

        return $products->take($limit)->map(fn (Product $p) => [
            'id'              => $p->id,
            'name'            => (string) $p->product_name,
            'price'           => self::price($p),
            'price_label'     => self::money(self::price($p)),
            'old_price_label' => $p->on_sale ? self::money((float) $p->price) : null,
            'image'           => self::relative($p->image_url),
            'url'             => route('product.show', $p->id, false),
        ])->values()->all();
    }

    /**
     * The cheapest and the most expensive of the products by current price.
     *
     * @param  Collection<int, Product>  $products
     * @return array{count: int, min: float, max: float, cheapest: Product, dearest: Product}|null
     */
    public static function range(Collection $products): ?array
    {
        if ($products->isEmpty()) {
            return null;
        }

        $sorted = $products->sortBy(fn (Product $p) => [self::price($p), $p->id])->values();

        return [
            'count'    => $sorted->count(),
            'min'      => self::price($sorted->first()),
            'max'      => self::price($sorted->last()),
            'cheapest' => $sorted->first(),
            'dearest'  => $sorted->last(),
        ];
    }

    /** Catalog page for a type (and optionally a top price), as a same-site path. */
    public static function catalogUrl(?string $type = null, ?int $maxPrice = null): string
    {
        $query = array_filter([
            'type'      => self::isType($type) ? $type : null,
            'max_price' => $maxPrice ?: null,
        ]);

        return route('catalog', $query, false);
    }

    /** A same-site path for local images, so the card works on any host; other hosts are left as they are. */
    private static function relative(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host && $host === parse_url(config('app.url'), PHP_URL_HOST)) {
            $path  = parse_url($url, PHP_URL_PATH) ?: '/';
            $query = parse_url($url, PHP_URL_QUERY);

            return $path . ($query ? '?' . $query : '');
        }

        return $url;
    }
}
