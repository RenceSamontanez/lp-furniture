<?php
/** Checkout steps walk: SCRATCH rows only. A verified customer with NO phone, one product in the cart. Login: steps@example.test / Walk-Pass-123 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

if (App\Models\Province::count() === 0) { Artisan::call('places:import'); }
$u = new User();
$u->forceFill([
    'first_name' => 'Rence', 'last_name' => 'Samontanez', 'email' => 'steps@example.test', 'password' => 'Walk-Pass-123',
    'role' => 'customer', 'account_status' => 'active', 'is_validated' => true, 'email_verified_at' => now(),
])->save();
$p = Product::create(['product_name' => 'study table with shelves', 'category' => 'tables', 'material' => 'lawaan', 'finish' => 'white paint', 'price' => 11000, 'stock_quantity' => 5, 'product_status' => 'available']);
DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
echo "ok\n";
