<?php
/**
 * Customer typography walk: SCRATCH rows only (refuses to run unless the database name ends in _test).
 * Run after seed_order_tracking.php: gives the demo customer a cart line so the cart and checkout pages have something to show.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

$user = App\Models\User::where('email', 'ot-customer@example.test')->firstOrFail();
$product = App\Models\Product::firstOrFail();
App\Models\CartItem::firstOrCreate(['user_id' => $user->id, 'product_id' => $product->id], ['quantity' => 2]);
echo "cart ok for user {$user->id}, product {$product->id}\n";
