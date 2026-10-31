<?php
/**
 * Materials walk: SCRATCH rows only (refuses unless the database name ends in _test).
 *   php tests/UiLinks/seed_materials.php
 * Creates admin mat-admin@example.test, the ten inventory rows of the prototype (Gemelina is short by 40), the nine products with
 * their starter estimates, a few orders with deduction / restore / restock history for Reports > Materials. The furniture-type
 * library comes from the migration. Run after any phpunit run (phpunit rebuilds the scratch database).
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\Inventory;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\User;
use App\Support\MaterialDeduction;
use App\Support\MaterialEstimator;
use App\Support\MaterialStock;

\Artisan::call('materials:seed-estimates');

foreach ([['mat-admin@example.test', 'Walk', 'Admin', 'admin'], ['mat-customer@example.test', 'Maria', 'Reyes', 'customer']] as [$email, $f, $l, $role]) {
    $u = User::firstOrNew(['email' => $email]);
    if (!$u->exists) {
        $u->forceFill(['first_name' => $f, 'last_name' => $l, 'password' => 'Walk-Pass-123', 'role' => $role, 'is_validated' => true, 'account_status' => 'active', 'email_verified_at' => now(), 'phone_number' => '09175550123'])->save();
    }
}
$customer = User::where('email', 'mat-customer@example.test')->first();

$materials = [
    ['Wood (Narra)', 'sqft', 412, 100], ['Wood (Mahogany)', 'sqft', 96, 100], ['Wood (Lawaan)', 'sqft', 301, 100], ['Wood (Gemelina)', 'sqft', 0, 80],
    ['Wood (Acacia)', 'sqft', 188, 80], ['Barnis / Varnish', 'liters', 11, 50], ['Nails and Screws', 'pieces', 120, 500], ['White Lacquer', 'liters', 18, 15],
    ['Sandpaper', 'pieces', 74, 50], ['Wood Glue', 'liters', 2, 10],
];
foreach ($materials as [$n, $u, $q, $t]) {
    if (!Inventory::where('material_name', $n)->exists()) {
        MaterialStock::create(['material_name' => $n, 'unit' => $u, 'material_quantity' => $q, 'low_stock_threshold' => $t]);
    }
}
$gem = Inventory::where('material_name', 'Wood (Gemelina)')->first();
if ((float) $gem->material_quantity >= 0) { MaterialStock::change($gem->id, -40 - (float) $gem->material_quantity, 'consumed'); }

$products = [
    ['King Bed Frame', 'Bed', 'Narra', 38500], ['4-Door Wardrobe Cabinet', 'Cabinet', 'Lawaan', 32000], ['TV Console Cabinet', 'Cabinet', 'Mahogany', 14500],
    ['Executive Office Chair', 'Chair', 'Mahogany', 9800], ['Rocking Chair', 'Chair', 'Bamboo-wood', 7200], ['3-Seater Sofa Frame', 'Sofa', 'Acacia', 18900],
    ['Sala Set 5-piece', 'Sofa', 'Narra', 64000], ['Narra Dining Table', 'Table', 'Narra', 26500], ['Study Table with Shelves', 'Table', 'Lawaan', 12800],
];
foreach ($products as [$n, $c, $w, $p]) {
    if (!Product::where('product_name', $n)->exists()) {
        Product::create(['product_name' => $n, 'category' => $c, 'material' => $w, 'finish' => 'Natural varnish', 'price' => $p, 'stock_quantity' => 5, 'product_status' => 'available']);
    }
}
\Artisan::call('materials:seed-estimates');

// history: a few confirmed orders (one cancelled and put back), a restock, a recount
if (!Order::where('order_number', 'LP-1058')->exists()) {
    $make = function (string $number, string $productName, int $qty) use ($customer) {
        $o = Order::create(['user_id' => $customer->id, 'order_number' => $number, 'total_amount' => 1000, 'delivery_option' => 'pickup', 'delivery_fee' => 0, 'order_status' => 'confirmed']);
        $p = Product::where('product_name', $productName)->first();
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'product_name' => $p->product_name, 'quantity' => $qty, 'unit_price' => 500, 'subtotal' => 500 * $qty]);
        MaterialDeduction::deduct($o);
        return $o;
    };
    $make('LP-1055', '4-Door Wardrobe Cabinet', 1);
    $make('LP-1058', 'King Bed Frame', 1);
    $cancelled = $make('LP-1049', 'Study Table with Shelves', 1);
    MaterialDeduction::restore($cancelled);
    $cancelled->forceFill(['order_status' => 'cancelled'])->save();
    MaterialStock::change(Inventory::where('material_name', 'Wood (Acacia)')->value('id'), 100, 'restocked', null, null, 'Restocked');
    MaterialStock::change(Inventory::where('material_name', 'Nails and Screws')->value('id'), 1000, 'restocked');
    MaterialStock::correct(Inventory::where('material_name', 'Wood Glue')->value('id'), 2);
    // spread the history over the last weeks so the weekly chart has several columns
    foreach (InventoryMovement::orderBy('id')->get() as $i => $m) {
        InventoryMovement::whereKey($m->id)->update(['created_at' => now()->subDays(($i * 2) % 26)]);
    }
}
echo "ok\n";
