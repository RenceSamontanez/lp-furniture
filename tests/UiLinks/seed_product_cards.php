<?php
/**
 * Shared product card walk: SCRATCH products (refuses to run unless the database name ends in _test).
 * Adds a long name, a PHP 128,000.00 price, a sale product that is also a Best Seller, ordered products for the carousel counts,
 * and enough products in one category for the related row. Needs tests/Rbac/seed_screenshots.php first (customer for the orders).
 *   php tests/UiLinks/seed_product_cards.php
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
use App\Models\User;
use Illuminate\Support\Facades\DB;

if (Product::count() > 0) { echo "products already there\n"; exit(0); }

$mk = fn (string $n, string $c, float $p, ?string $d, array $x = []) => Product::create(array_merge([
    'product_name' => $n, 'category' => $c, 'price' => $p, 'stock_quantity' => 5, 'product_status' => 'available',
    'description' => $d, 'is_featured' => true, 'created_at' => now()->subDays(rand(1, 40)),
], $x));

$made = [
    $mk('Narra Dining Table', 'Tables', 18500, 'Solid narra wood dining table seats six. Custom sizes available on request, with a hand-rubbed natural varnish.', ['sale_price' => 14800]),
    $mk('Executive Office Chair', 'Chairs', 7200, 'High-back chair with premium wood frame and cushioned seat.'),
    $mk('Narra Arm Chair With Cushioned Seat, Woven Back And Hand Carved Armrests', 'Chairs', 128000, 'A sturdy narra chair, hand finished, with a woven back and a seat cushion that keeps its shape for years of daily use, plus carved armrests.'),
    $mk('Study Table', 'Tables', 8200, null),
    $mk('Display Cabinet', 'Cabinets', 45900, 'Glass doors and three shelves.'),
    $mk('Bench', 'Chairs', 3500, 'Short.'),
    $mk('Mahogany Four Poster Bed', 'Beds', 98000, 'Queen size frame.'),
    $mk('Rocking Chair', 'Chairs', 5500, 'Classic rocker.', ['sale_price' => 4400]),
    $mk('Wardrobe', 'Cabinets', 22000, 'Four-door wardrobe with mirror.', ['is_featured' => false]),
];
$mk('Hidden Draft Cabinet', 'Cabinets', 9999, 'Not for sale.', ['product_status' => 'unavailable']);

$customer = User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
foreach ([0 => 9, 2 => 6, 1 => 4, 7 => 3, 3 => 1] as $idx => $times) {
    $o = Order::create(['user_id' => $customer->id, 'order_number' => 'LP-CARD-' . $idx, 'total_amount' => 1000, 'delivery_option' => 'pickup', 'order_status' => 'completed', 'production_status' => 'pending']);
    for ($k = 0; $k < $times; $k++) {
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $made[$idx]->id, 'product_name' => $made[$idx]->product_name, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);
    }
}
echo Product::count() . " products\n";
