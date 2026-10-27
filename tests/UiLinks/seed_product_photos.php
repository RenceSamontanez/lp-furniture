<?php
/**
 * Product photos browser walk: SCRATCH products in the old style (rows pointing at the committed files in public/images/products),
 * imported with the real command into the product-photos folder, plus one product with no photo and one old order whose item
 * only has a legacy snapshot. Refuses to run unless the database name ends in _test.
 *
 *   php tests/UiLinks/seed_product_photos.php          (needs php tests/Rbac/seed_screenshots.php first)
 *   php tests/UiLinks/seed_product_photos.php --clean  (removes the imported scratch copies and rows)
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
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

if (in_array('--clean', $argv, true)) {
    foreach (ProductImage::pluck('image_path') as $p) { Storage::disk('public')->delete($p); }
    echo "cleaned files\n"; exit(0);
}
if (Product::count() > 0) { echo "products already there\n"; exit(0); }

$mk = fn (string $n, string $cat, float $price, array $extra = []) => Product::create($extra + ['product_name' => $n, 'category' => $cat, 'price' => $price, 'stock_quantity' => 5,
    'product_status' => 'available', 'material' => 'Narra', 'finish' => 'Natural varnish', 'dimensions' => '180 x 90 x 76 cm',
    'description' => 'Solid wood, made to order in our San Juan workshop.']);
$row = fn (Product $p, string $f, bool $main, int $s) => ProductImage::create(['product_id' => $p->id, 'image_path' => $f, 'sort_order' => $s, 'is_primary' => $main]);

$narra = $mk('Narra Dining Table', 'Tables', 18500, ['product_image' => 'U3dh6Dzh0m6llNJzAifX.jpg']);
$row($narra, 'narra-dining-table-front.jpg', true, 1); $row($narra, 'narra-dining-table-back.jpg', false, 2);
foreach ([['Executive Office Chair', 'Chairs', 7200, 'executive-office-chair.jpg'], ['King Bed Frame', 'Beds', 28000, 'king-bed-frame.jpg'],
          ['Rocking Chair', 'Chairs', 5400, 'rocking-chair.jpg'], ['Sala Set 5-piece', 'Sofas', 38000, 'sala-set-piece.jpg']] as [$n, $c, $pr, $f]) {
    $row($mk($n, $c, $pr), $f, true, 1);
}
$none = $mk('Photoless Stool (no photo)', 'Chairs', 1800);

Artisan::call('products:import-builtin-photos', ['--force' => true]);
echo Artisan::output();

// old order: the product was deleted long ago, only the snapshot survives (a file that still exists in the old folder)
$customer = User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
$o = Order::create(['user_id' => $customer->id, 'order_number' => 'LP-OLD-PHOTO', 'total_amount' => 7200, 'delivery_option' => 'pickup', 'order_status' => 'completed', 'production_status' => 'pending']);
DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => null, 'product_name' => 'Old Wardrobe Cabinet', 'product_image' => 'wardrobe-cabinet.jpg', 'quantity' => 1, 'unit_price' => 7200, 'subtotal' => 7200]);
foreach ([Product::where('product_name', 'Executive Office Chair')->value('id'), $none->id] as $pid) {
    DB::table('cart_items')->insert(['user_id' => $customer->id, 'product_id' => $pid, 'quantity' => 1]);
}
echo "order id {$o->id}, no-photo product id {$none->id}, narra id {$narra->id}\n";
file_put_contents($root . '/storage/app/product-photos-walk.json', json_encode(['order_id' => $o->id, 'none_id' => $none->id, 'narra_id' => $narra->id, 'cart_product' => Product::where('product_name', 'Executive Office Chair')->value('id')]));
