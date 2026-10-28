<?php
/**
 * Back-buttons walks: SCRATCH rows only.
 *   php tests/UiLinks/seed_back_buttons.php            ensures flow@example.test (phone + saved address, one product in the cart) and two unpaid orders
 *                                                      (Stripe, GCash) for it; prints {"stripe":id,"gcash":id} as the last line.
 *                                                      Needs seed_checkout_steps.php first (steps@example.test = no phone, for the early warning).
 *   php tests/UiLinks/seed_back_buttons.php cart       puts one product back into flow@example.test's cart (after a walk placed an order).
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$p = Product::firstOrFail();
$p->forceFill(['stock_quantity' => 50, 'product_status' => 'available'])->save();   // every walk places orders; keep the stand-in product in stock
$u = User::where('email', 'flow@example.test')->first();
if (!$u) {
    $lipa = CityMunicipality::where('name', 'Lipa City')->whereHas('province', fn ($q) => $q->where('name', 'Batangas'))->firstOrFail();
    $brgy = Barangay::where('city_municipality_id', $lipa->id)->firstOrFail();
    $u = new User();
    $u->forceFill([
        'first_name' => 'Maria', 'last_name' => 'Flow', 'email' => 'flow@example.test', 'password' => 'Walk-Pass-123',
        'role' => 'customer', 'account_status' => 'active', 'is_validated' => true, 'email_verified_at' => now(),
        'phone_number' => '09171234567', 'phone_e164' => '+639171234567', 'phone_country' => 'PH',
        'address_province_id' => $lipa->province_id, 'address_city_id' => $lipa->id, 'address_barangay_id' => $brgy->id,
        'address_street' => 'Blk 5 Lot 12, Sampaguita St.', 'address_postal_code' => '4217',
    ])->save();
}
if (($argv[1] ?? '') === 'cart' || !DB::table('cart_items')->where('user_id', $u->id)->exists()) {
    DB::table('cart_items')->where('user_id', $u->id)->delete();
    DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
}
$out = [];
foreach (['stripe', 'gcash'] as $i => $method) {
    $o = Order::where('user_id', $u->id)->where('order_number', 'LP-2026-8' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT))->first();
    if (!$o) {
        $o = Order::create(['user_id' => $u->id, 'order_number' => 'LP-2026-8' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'total_amount' => 11000,
            'delivery_option' => 'pickup', 'delivery_fee' => 0, 'order_status' => 'pending', 'production_status' => 'pending']);
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'product_name' => $p->product_name, 'quantity' => 1, 'unit_price' => 11000, 'subtotal' => 11000]);
        Payment::create(['order_id' => $o->id, 'user_id' => $u->id, 'amount' => 11000, 'payment_method' => $method, 'payment_type' => 'full', 'payment_status' => 'pending']);
    }
    $out[$method] = $o->id;
}
echo json_encode($out), "\n";
