<?php
/** Card-layout walk: SCRATCH only. Adds products with big prices and long names, in three categories, some on sale. */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }
use App\Models\Product;
$rows = [
  ['Narra Arm Chair With Cushioned Seat and Woven Back', 'Chairs', 18500, 'A sturdy narra chair, hand finished, with a woven back and a seat cushion that keeps its shape for years of daily use.'],
  ['Lawaan Dresser', 'Dressers', 124500, null],
  ['Mahogany Four Poster Bed', 'Beds', 98000, 'Queen size frame.'],
  ['Study Table', 'Tables', 8200, null],
  ['Display Cabinet', 'Cabinets', 45900, 'Glass doors and three shelves.'],
  ['Bench', 'Chairs', 3500, null],
];
foreach ($rows as [$n, $c, $p, $d]) {
    Product::create(['product_name' => $n, 'category' => $c, 'price' => $p, 'stock_quantity' => 5, 'product_status' => 'available', 'description' => $d, 'is_featured' => true]);
}
echo Product::count() . " products\n";
