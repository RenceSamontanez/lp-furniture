<?php
/**
 * Catalog + Quick view: SCRATCH products for the browser walk. Uses the product photos already in public/images/products, plus two
 * generated SVGs (tst_light.svg: almost white, tst_dark.svg: almost black) so the glass panel can be checked over both. Includes
 * products with several photos, one with no photo, one with no material/finish/size, one on sale, some ordered (Best seller).
 * Refuses to run unless the database name ends in _test.
 *   php tests/UiLinks/seed_catalog.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;

if (Product::count() > 0) { echo "products already there\n"; exit(0); }

$dir = $root . '/storage/app/public/product-photos/'; @mkdir($dir, 0777, true);   // scratch SVGs live in the product-photos folder like every other photo
file_put_contents($dir . 'tst_light.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="900" viewBox="0 0 1200 900"><rect width="1200" height="900" fill="#F7F5F1"/><rect x="300" y="330" width="600" height="60" fill="#E4DED3"/><rect x="340" y="390" width="40" height="300" fill="#E4DED3"/><rect x="820" y="390" width="40" height="300" fill="#E4DED3"/></svg>');
file_put_contents($dir . 'tst_dark.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="900" height="1200" viewBox="0 0 900 1200"><rect width="900" height="1200" fill="#120B07"/><rect x="200" y="500" width="500" height="50" fill="#2A1A10"/><rect x="230" y="550" width="36" height="320" fill="#2A1A10"/><rect x="634" y="550" width="36" height="320" fill="#2A1A10"/></svg>');

$mk = function (string $name, string $cat, float $price, ?string $img, array $extra = []) {
    return Product::create($extra + ['product_name' => $name, 'category' => $cat, 'price' => $price, 'stock_quantity' => 5,
        'product_status' => 'available', 'product_image' => $img, 'material' => 'Narra', 'finish' => 'Natural varnish',
        'dimensions' => '180 x 90 x 76 cm', 'description' => 'Solid wood, made to order in our San Juan workshop. Custom sizes are available on request.',
        'created_at' => now()->subDays(random_int(1, 40))]);
};
$gal = function (Product $p, array $files) {
    foreach ($files as $i => $f) {
        ProductImage::create(['product_id' => $p->id, 'image_path' => $f, 'sort_order' => $i, 'is_primary' => $i === 0]);
    }
};

$made = [];
$made[] = $p = $mk('Narra Dining Table', 'Tables', 18500, 'narra-dining-table-front.jpg'); $gal($p, ['narra-dining-table-front.jpg', 'narra-dining-table-back.jpg', 'study-table.jpg']);
$made[] = $mk('Executive Office Chair', 'Chairs', 7200, 'executive-office-chair.jpg');
$made[] = $mk('4-Door Wardrobe Cabinet', 'Cabinets', 22000, 'wardrobe-cabinet.jpg');
$made[] = $mk('King Bed Frame', 'Beds', 28000, 'king-bed-frame.jpg');
$made[] = $mk('TV Console Cabinet', 'Cabinets', 13500, 'tv-console-cabinet.jpg');
$made[] = $mk('Rocking Chair', 'Chairs', 5400, 'rocking-chair.jpg', ['sale_price' => 4500]);
$made[] = $p = $mk('Pale Oak Bench', 'Tables', 6100, 'product-photos/tst_light.svg'); $gal($p, ['product-photos/tst_light.svg', 'product-photos/tst_dark.svg']);
$made[] = $mk('Midnight Console', 'Tables', 15900, 'product-photos/tst_dark.svg');
$made[] = $mk('Sala Set', 'Sofas', 38000, 'sala-set-piece.jpg');
$made[] = $mk('Sofa Frame', 'Sofas', 16500, 'sofa-frame.jpg', ['material' => null, 'finish' => '', 'dimensions' => null, 'description' => null]);
$made[] = $mk('Photoless Stool (no photo)', 'Chairs', 1800, null);
Product::create(['product_name' => 'Hidden Draft Cabinet', 'category' => 'Cabinets', 'price' => 9999, 'stock_quantity' => 3, 'product_status' => 'unavailable', 'product_image' => 'sofa-frame.jpg']);

$customer = User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
foreach ([0 => 5, 2 => 4, 3 => 3, 5 => 2] as $idx => $times) {
    $o = Order::create(['user_id' => $customer->id, 'order_number' => 'LP-CAT-' . $idx, 'total_amount' => 1000, 'delivery_option' => 'pickup', 'order_status' => 'completed', 'production_status' => 'pending']);
    for ($k = 0; $k < $times; $k++) {
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $made[$idx]->id, 'product_name' => $made[$idx]->product_name, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);
    }
}
echo "seeded catalog products\n";
