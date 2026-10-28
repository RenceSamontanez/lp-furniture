<?php
/**
 * Navbar / notifications walk seed (SCRATCH database only; refuses to run unless the name ends in _test).
 * Replaces the demo customer's notifications with one of each kind (order, service, account), spread over several dates,
 * plus enough extra rows to make the page paginate. Run after tests/Rbac/seed_screenshots.php.
 *
 *   php tests/UiLinks/seed_notif_nav.php            (add --empty to leave the customer with none)
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
$u = User::where('email', $login['customer_email'])->firstOrFail();
Notification::where('user_id', $u->id)->delete();
if (in_array('--empty', $argv, true)) { echo "emptied\n"; exit(0); }

$orderId = Order::where('user_id', $u->id)->value('id') ?? Order::value('id') ?? 1;
$rows = [
    ['Identity Verified ✓', 'Your identity has been verified. You can now place orders.', 'validation_approved', route('home'), 0, false],
    ['Order LP-2026-00012: In Production', 'Our craftsmen have started building your furniture piece.', 'order_update', route('orders.show', $orderId), 1, false],
    ['Payment Received and Order Confirmed', 'Order LP-2026-00012: We received your payment and confirmed your order.', 'payment_confirmed', route('orders.show', $orderId), 1, false],
    ['Your Design Suggestions Are Ready!', 'We have generated 3 design suggestions for your sofa request.', 'design_ready', route('design.show', 1), 9, false],
    ['Password Updated', 'Your password was changed successfully.', 'general', route('profile'), 23, true],
    ['Order LP-2026-00009: Completed', 'Your order was picked up. Thank you for choosing LP Furniture.', 'order_update', route('orders.show', $orderId), 40, true],
    ['Contact Details Updated', 'Your phone number was updated.', 'general', route('profile'), 70, true],
    ['<b>Script</b> test & "quotes"', '<script>window.__xss = 1</script> plain text only', 'general', null, 80, true],
];
foreach ($rows as [$title, $msg, $type, $url, $daysAgo, $read]) {
    $n = Notification::create(['user_id' => $u->id, 'title' => $title, 'message' => $msg, 'type' => $type, 'url' => $url, 'is_read' => $read]);
    $n->forceFill(['created_at' => now()->subDays($daysAgo)->subHours(2)])->save();
}
foreach (range(1, 18) as $i) {
    $n = Notification::create(['user_id' => $u->id, 'title' => "Older update $i", 'message' => 'An older note kept so the page paginates.', 'type' => 'order_update', 'url' => route('orders.show', $orderId), 'is_read' => true]);
    $n->forceFill(['created_at' => now()->subDays(100 + $i)])->save();
}
echo "seeded " . Notification::where('user_id', $u->id)->count() . " notifications for {$u->email}\n";
