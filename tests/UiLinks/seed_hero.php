<?php
/**
 * Landing hero: SCRATCH products for the rails (9 products using the product photos already in public/images/products, some ordered
 * so Best sellers has data, one hidden). Refuses to run unless the database name ends in _test.
 *   php tests/UiLinks/seed_hero.php
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

if (Product::count() > 0) { echo "products already there\n"; exit(0); }

$files = ['executive-office-chair.jpg', 'king-bed-frame.jpg', 'narra-dining-table-front.jpg', 'rocking-chair.jpg', 'sala-set-piece.jpg', 'sofa-frame.jpg', 'study-table.jpg', 'narra-dining-table-back.jpg'];
$names = ['Executive Office Chair', 'King Bed Frame', 'Narra Dining Table', 'Rocking Chair', 'Sala Set', 'Sofa Frame', 'Study Table', 'Narra Table (back view)'];
$made = [];
foreach ($files as $i => $f) {
    $made[] = Product::create(['product_name' => $names[$i], 'category' => 'Furniture', 'price' => 4500 + $i * 1750, 'stock_quantity' => 5,
        'product_status' => 'available', 'product_image' => $f, 'created_at' => now()->subDays(20 - $i)]);
}
Product::create(['product_name' => 'Hidden Draft Cabinet', 'category' => 'Furniture', 'price' => 9999, 'stock_quantity' => 3, 'product_status' => 'unavailable', 'product_image' => 'sofa-frame.jpg']);

$customer = User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
foreach ([0 => 5, 2 => 4, 3 => 3, 5 => 2, 1 => 1] as $idx => $times) {
    $o = Order::create(['user_id' => $customer->id, 'order_number' => 'LP-HERO-' . $idx, 'total_amount' => 1000, 'delivery_option' => 'pickup', 'order_status' => 'completed', 'production_status' => 'pending']);
    for ($k = 0; $k < $times; $k++) {
        DB::table('order_items')->insert(['order_id' => $o->id, 'product_id' => $made[$idx]->id, 'product_name' => $made[$idx]->product_name, 'quantity' => 1, 'unit_price' => 1000, 'subtotal' => 1000]);
    }
}
echo "seeded hero products\n";
