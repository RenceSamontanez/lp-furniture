<?php
/** Text-pass walk: SCRATCH only. Gives the order-tracking customer the demo notifications, a cart item and a verified account. Run after the other seeds. */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }
use Illuminate\Support\Facades\DB;
$u = App\Models\User::where('email', 'ot-customer@example.test')->firstOrFail();
$u->forceFill(['is_validated' => true, 'email_verified_at' => now()])->save();
$demo = App\Models\User::where('email', 'like', 'demo-customer-%')->first();
if ($demo) { DB::table('notifications')->where('user_id', $demo->id)->update(['user_id' => $u->id]); }
$p = App\Models\Product::first();
DB::table('cart_items')->where('user_id', $u->id)->delete();
DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
echo json_encode(['phone' => $u->phone_number, 'id' => $u->id, 'products' => App\Models\Product::count()]) . "\n";
