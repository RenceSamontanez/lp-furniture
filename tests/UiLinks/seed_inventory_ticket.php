<?php
/**
 * Materials Inventory / New Ticket modal walk: SCRATCH rows only (refuses unless the database name ends in _test).
 *   php tests/UiLinks/seed_inventory_ticket.php
 * Creates admin inv-admin@example.test, a customer (Linda Walker) for the account search, and a few materials including a 340.00 quantity.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\Inventory;
use App\Models\User;

foreach ([['inv-admin@example.test', 'Walk', 'Admin', 'admin'], ['linda.walker@example.test', 'Linda', 'Walker', 'customer']] as [$email, $f, $l, $role]) {
    $u = User::firstOrNew(['email' => $email]);
    if (!$u->exists) {
        $u->forceFill(['first_name' => $f, 'last_name' => $l, 'password' => 'Walk-Pass-123', 'role' => $role, 'is_validated' => true, 'account_status' => 'active', 'email_verified_at' => now(), 'phone_number' => '09175550123'])->save();
    }
}
foreach ([['Narra Wood Planks', 340, 'pcs', 50], ['Wood Varnish', 4, 'liters', 5], ['Nails', 0, 'kg', 2], ['Plywood Sheets', 1250.5, 'sheets', 100]] as [$n, $q, $u, $t]) {
    if (!Inventory::where('material_name', $n)->exists()) {
        Inventory::create(['material_name' => $n, 'material_quantity' => $q, 'unit' => $u, 'low_stock_threshold' => $t,
            'inventory_status' => $q <= 0 ? 'out_of_stock' : ($q <= $t ? 'low_stock' : 'in_stock')]);
    }
}
echo "ok\n";
