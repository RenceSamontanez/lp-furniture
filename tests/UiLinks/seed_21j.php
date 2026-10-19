<?php
/**
 * Stage 21j: extra SCRATCH data for the pagination/nav screenshots (25 orders and 45 notifications for the demo customer,
 * 45 notifications for the demo admin, one customer whose ID is not validated). Refuses to run unless the database ends in _test.
 *   php tests/UiLinks/seed_21j.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\Notification;
use App\Models\Order;
use App\Models\User;

$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$customer = User::where('email', $login['customer_email'])->firstOrFail();
$admin = User::where('email', $login['admin_email'])->firstOrFail();

if (Order::where('user_id', $customer->id)->count() < 25) {
    for ($i = 1; $i <= 25; $i++) {
        Order::create(['user_id' => $customer->id, 'order_number' => sprintf('LP-UI21J-%03d', $i), 'total_amount' => 1500 + $i * 100,
            'delivery_option' => 'pickup', 'order_status' => ['pending', 'confirmed', 'completed'][$i % 3], 'production_status' => 'pending']);
    }
}
foreach ([$customer, $admin] as $u) {
    if (Notification::where('user_id', $u->id)->count() < 45) {
        for ($i = 1; $i <= 45; $i++) {
            Notification::create(['user_id' => $u->id, 'title' => "Sample notice {$i}", 'message' => 'Sample message for the pagination walk.', 'type' => 'general', 'is_read' => $i % 4 !== 0]);
        }
    }
}
$u = User::where('email', 'unvalidated-21j@example.test')->first() ?? new User();
$u->forceFill(['first_name' => 'Unchecked', 'last_name' => 'Customer', 'email' => 'unvalidated-21j@example.test', 'password' => bcrypt(Illuminate\Support\Str::random(20)),
    'role' => 'customer', 'is_validated' => false, 'account_status' => 'active'])->save();
echo "seeded\n";
