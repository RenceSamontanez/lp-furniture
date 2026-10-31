<?php
/**
 * Activity Log walk: SCRATCH data only. Needs tests/Rbac/seed_screenshots.php first (it makes the admin login). Refuses to run unless the
 * database name ends in _test. Re-running replaces what it made (rows whose actor names are the walk names below).
 *   php tests/UiLinks/seed_activity_log.php            (add --empty to leave the log empty)
 * Makes ~70 records over 40 days: sign-ins and sign-outs for two admins and four customers, every category, late-night rows at 12:00 AM,
 * 4:59 AM and 5:00 AM, an order with a real page to open, a removed product (no page), and a long description / name.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\User;

ActivityLog::query()->delete();
if (in_array('--empty', $argv, true)) { echo "log emptied\n"; exit(0); }

$user = fn (string $first, string $last, string $role) => User::firstOrCreate(
    ['email' => strtolower($first) . '@walk-activity.test'],
    ['first_name' => $first, 'last_name' => $last, 'password' => 'x', 'role' => $role, 'is_validated' => true, 'account_status' => 'active']
);
$joy = $user('Joy', 'Admin', 'admin');
$lp = $user('LP', 'Admin', 'admin');
$maria = $user('Maria', 'Santos', 'customer');
$mark = $user('Mark', 'Tan', 'customer');
$ana = $user('Ana', 'Lopez', 'customer');
$jose = $user('Jose', 'Reyes', 'customer');

$customer = $maria;
$order = Order::where('order_number', 'LP-ACT-1058')->first() ?? Order::create(['user_id' => $customer->id, 'order_number' => 'LP-ACT-1058', 'total_amount' => 12000, 'delivery_option' => 'pickup', 'delivery_fee' => 0, 'order_status' => 'confirmed']);

$add = function (string $when, User $u, string $kind, string $category, string $desc, ?string $type = null, ?int $id = null, ?string $label = null) {
    ActivityLog::create([
        'user_id' => $u->id, 'actor_name' => trim($u->first_name . ' ' . $u->last_name), 'role' => $u->role === 'admin' ? 'admin' : 'customer',
        'event' => $kind === 'action' ? 'walk' : ($kind === 'signin' ? 'login' : 'logout'), 'kind' => $kind, 'category' => $category, 'description' => $desc,
        'subject_type' => $type, 'subject_id' => $id, 'subject_label' => $label, 'created_at' => $when,
    ]);
};
$d = fn (int $days, string $time) => now()->subDays($days)->format('Y-m-d') . ' ' . $time;

$add($d(0, '09:02'), $maria, 'signin', 'signin', 'Signed in');
$add($d(0, '08:47'), $lp, 'signin', 'signin', 'Signed in');
$add($d(0, '08:50'), $lp, 'action', 'catalog', 'Restocked Wood (Gemelina) by 100 sqft', 'inventory', null, 'Material: Wood (Gemelina)');
$add($d(0, '08:55'), $lp, 'action', 'orders', 'Confirmed order LP-ACT-1058', 'order', $order->id, 'Order LP-ACT-1058');
$add($d(0, '08:58'), $lp, 'action', 'orders', 'Recorded a cash payment for order LP-ACT-1058', 'order', $order->id, 'Order LP-ACT-1058');
$add($d(0, '07:30'), $jose, 'signin', 'signin', 'Signed in');
$add($d(0, '07:05'), $ana, 'signout', 'signin', 'Signed out');
$add($d(0, '00:00'), $lp, 'signin', 'signin', 'Signed in');
$add($d(1, '17:40'), $joy, 'action', 'catalog', 'Edited the estimate for Bed, King', 'materialestimate', null, 'Furniture type: Bed, King');
$add($d(1, '17:12'), $joy, 'action', 'catalog', 'Removed product Old Narra Chair', 'product', null, 'Product: Old Narra Chair');
$add($d(1, '16:50'), $joy, 'signin', 'signin', 'Signed in');
$add($d(1, '15:20'), $mark, 'signin', 'signin', 'Signed in');
$add($d(1, '14:03'), $lp, 'action', 'people', 'Approved an ID check for Mark Tan', 'user', $mark->id, 'Customer: Mark Tan');
$add($d(1, '13:40'), $lp, 'action', 'settings', 'Answered the idea "Rattan Weave"', null, null, 'Idea: Rattan Weave');
$add($d(1, '04:59'), $lp, 'action', 'orders', 'Cancelled order LP-ACT-1049', 'order', null, 'Order LP-ACT-1049');
$add($d(1, '05:00'), $lp, 'action', 'orders', 'Approved the refund request REF-2026-0007', null, null, 'Request: REF-2026-0007');
$add($d(1, '02:14'), $lp, 'signin', 'signin', 'Signed in');
$add($d(2, '18:05'), $lp, 'signout', 'signin', 'Signed out');
$add($d(2, '16:45'), $lp, 'action', 'settings', 'Saved Contact Settings', 'settings', null, 'Contact Settings');
$add($d(2, '15:10'), $mark, 'signin', 'signin', 'Signed in');
$add($d(2, '09:15'), $lp, 'action', 'people', 'Added employee Dino Cruz', 'employee', null, 'Employee: Dino Cruz');
$add($d(3, '17:02'), $maria, 'signin', 'signin', 'Signed in');
$add($d(3, '14:28'), $lp, 'action', 'catalog', 'Corrected the count of Wood Glue to 2', 'inventory', null, 'Material: Wood Glue');
$add($d(4, '16:11'), $joy, 'action', 'settings', 'Answered a Luma question', 'luma', null, 'Luma question');
$add($d(4, '10:05'), $lp, 'action', 'orders', 'Created service ticket SI-2026-0012', null, null, 'Ticket: SI-2026-0012');
$add($d(5, '18:30'), $lp, 'action', 'catalog', 'Edited product King Bed Frame With A Very Long Name That Keeps Going And Going To Test Wrapping In Cards', 'product', null, 'Product: King Bed Frame With A Very Long Name That Keeps Going And Going');
$add($d(6, '09:40'), $lp, 'action', 'people', 'Rejected an ID check for Rey Alvarez', null, null, 'Customer: Rey Alvarez');
$add($d(8, '14:00'), $lp, 'action', 'catalog', 'Added material Wood (Acacia)', 'inventory', null, 'Material: Wood (Acacia)');
$add($d(9, '10:10'), $ana, 'signin', 'signin', 'Signed in');
$add($d(11, '16:20'), $joy, 'action', 'settings', 'Saved Contact Settings', 'settings', null, 'Contact Settings');
$add($d(14, '09:00'), $lp, 'action', 'people', 'Edited employee Dino Cruz', 'employee', null, 'Employee: Dino Cruz');
$add($d(19, '03:48'), $mark, 'signin', 'signin', 'Signed in');
$add($d(26, '11:25'), $jose, 'signin', 'signin', 'Signed in');
for ($i = 1; $i <= 30; $i++) {   // enough rows to page through
    $add($d(10 + $i, sprintf('%02d:%02d', 8 + $i % 9, ($i * 7) % 60)), $i % 3 ? $lp : $joy, 'action', ['orders', 'catalog', 'people', 'settings'][$i % 4], 'Walk record number ' . $i);
}
echo ActivityLog::count() . " records; order id {$order->id}\n";
