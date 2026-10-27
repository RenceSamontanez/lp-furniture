<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Feedback;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function home()
    {
        // Featured products (manually curated via is_featured flag)
        $featuredProducts = Product::available()
            ->featured()
            ->take(8)
            ->get();
    
        // Best sellers — ordered by actual purchase count
        // Falls back to newest products if no orders exist yet
        $bestSellers = Product::available()
            ->withCount('orderItems')
            ->orderBy('order_items_count', 'desc')
            ->take(8)
            ->get();
    
        if ($bestSellers->every(fn($p) => $p->order_items_count === 0)) {
            $bestSellers = Product::available()->latest()->take(8)->get();
        }
    
        // Category list for filter pills
        $categories = Product::available()
            ->select('category')
            ->distinct()
            ->whereNotNull('category')
            ->pluck('category');
    
            $galleryImages = [];
            for ($i = 1; $i <= 10; $i++) {
                $path = public_path("images/gallery/gallery-{$i}.jpg");
                $webp = public_path("images/gallery/gallery-{$i}.webp");
            
                if (file_exists($path)) {
                    $ext = 'jpg';
                } elseif (file_exists($webp)) {
                    $ext = 'webp';
                } else {
                    continue; // Skip — no image for this slot
                }
            
                $galleryImages[] = [
                    'url'      => asset("images/gallery/gallery-{$i}.{$ext}"),
                    'index'    => $i,
                    'label'    => $this->galleryLabel($i),
                    'story'    => $this->galleryStory($i),
                    'is_real'  => false, // Set to true when you have a real customer photo
                ];
            }
    
        // Approved reviews for the reviews section
        $approvedFeedbacks = Feedback::approved()
            ->with('user')
            ->latest('created_at')
            ->take(6)
            ->get();
    
        return view('home', compact(
            'featuredProducts',
            'bestSellers',
            'categories',
            'galleryImages',
            'approvedFeedbacks'
        ))->with('heroRails', \App\Support\HeroRails::get());
    }
    
    private function galleryLabel(int $index): string
    {
        $labels = [
            1  => 'Living Room Setup',
            2  => 'Dining Room Setup',
            3  => 'Home Office Setup',
            4  => 'Master Bedroom',
            5  => 'Modern Living Space',
            6  => 'Cozy Dining Area',
            7  => 'Executive Office',
            8  => 'Guest Bedroom',
            9  => 'Open Plan Living',
            10 => 'Study Corner',
        ];
        return $labels[$index] ?? 'Interior Setup';
    }
    
    private function galleryStory(int $index): string
    {
        $stories = [
            1  => 'Where Sunday mornings feel like they belong.',
            2  => 'The table where every dinner becomes a memory.',
            3  => 'A workspace built for serious people.',
            4  => 'Rest that feels like it was made for you.',
            5  => 'A room that finally feels like home.',
            6  => 'Warmth in every corner, comfort in every seat.',
            7  => 'Where decisions are made and ideas are born.',
            8  => 'A space that welcomes everyone who walks in.',
            9  => 'Open, breathing, and completely yours.',
            10 => 'Quiet enough to think. Beautiful enough to stay.',
        ];
        return $stories[$index] ?? 'Crafted for the life you want to live.';
    }
    // ── CATALOG PAGE ──
    public const CATALOG_SORTS = ['newest', 'price_asc', 'price_desc'];

    public function catalog(Request $request)
    {
        $query = Product::available()->with('primaryImage');

        if ($request->filled('category')) {
            $query->byCategory($request->category);
        }

        if ($request->filled('max_price')) {
            $query->where('price', '<=', $request->max_price);
        }

        if ($request->filled('search')) {
            $query->where('product_name', 'like', '%' . $request->search . '%');
        }

        // Sort is server-side and whitelisted. Price sorts use the price the customer actually pays (sale price when on sale).
        $sort = $request->query('sort');
        $sort = is_string($sort) && in_array($sort, self::CATALOG_SORTS, true) ? $sort : 'newest';
        $effective = 'CASE WHEN sale_price IS NOT NULL AND sale_price < price THEN sale_price ELSE price END';
        match ($sort) {
            'price_asc'  => $query->orderByRaw("$effective ASC")->orderByDesc('id'),
            'price_desc' => $query->orderByRaw("$effective DESC")->orderByDesc('id'),
            default      => $query->latest()->orderByDesc('id'),
        };

        $products = $query->get();

        // ?type=door (from Luma's "View more products" button): only the furniture types Luma knows
        // are accepted, so nothing from the address bar reaches a query or the page.
        $type = $request->query('type');
        $type = is_string($type) && \App\Support\Luma\ProductFinder::isType($type) ? $type : null;
        if ($type !== null) {
            $products = $products->filter(fn ($p) => \App\Support\Luma\ProductFinder::isOfType($p, $type))->values();
        }

        // Category index: real categories with the real number of available products in each.
        $categoryCounts = Product::available()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->selectRaw('category, COUNT(*) as total')
            ->groupBy('category')
            ->orderBy('category')
            ->pluck('total', 'category');
        $categories = $categoryCounts->keys();
        $totalCount = $categoryCounts->sum();

        $badges = $this->badgeMap();

        return view('catalog', compact('products', 'categories', 'categoryCounts', 'totalCount', 'type', 'sort', 'badges'));
    }

    /** Best seller / New tags exactly as HeroRails decides them: [product id => tag]. A product in both rails keeps "Best seller". */
    private function badgeMap(): array
    {
        $rails = \App\Support\HeroRails::get();
        $map = [];
        foreach (['fresh', 'best'] as $rail) {
            foreach ($rails[$rail] as $card) {
                $map[$card['id']] = $card['tag'];
            }
        }

        return $map;
    }

    // ── PRODUCT DETAIL ──
    public function show($id)
    {
        $product = Product::findOrFail($id);

        $relatedProducts = Product::available()
            ->where('category', $product->category)
            ->where('id', '!=', $product->id)
            ->take(4)
            ->get();

        return view('product.show', compact('product', 'relatedProducts'));
    }
    public function quickView($id)
    {
        $product = Product::with('images')->available()->findOrFail($id);

        // Main photo first, then the extras in sort_order. A product with no photo at all shows the site's neutral placeholder.
        $gallery = $product->gallery_images;
        if (!$gallery) {
            $gallery = [\App\Support\ProductPhotos::placeholderUrl()];
        }

        $price = $product->on_sale ? $product->formatted_sale_price : $product->formatted_price;

        return response()->json([
            'id'               => $product->id,
            'name'             => $product->product_name,
            'category'         => $product->category,
            'description'      => $product->description,
            'price'            => $product->formatted_price,
            'sale_price'       => $product->formatted_sale_price,
            'price_display'    => $price,
            'on_sale'          => $product->on_sale,
            'discount_percent' => $product->discount_percent,
            'badge'            => $this->badgeMap()[$product->id] ?? null,
            'material'         => filled($product->material) ? $product->material : null,
            'finish'           => filled($product->finish) ? $product->finish : null,
            'dimensions'       => $product->dimensions_label,
            'size_cm'          => $product->size_cm,
            'size_inches'      => $product->size_inches,
            'gallery_images'   => $gallery,
            'detail_url'       => route('product.show', $product->id),
        ]);
    }
}
