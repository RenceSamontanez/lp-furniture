<?php
/**
 * Admin menu / Ideas to Review / Reports and Analytics walk: SCRATCH data. Needs tests/Rbac/seed_screenshots.php and seed_ideas_admin.php first.
 * Adds a long wood idea with matching requests, six months of orders (live and cancelled, catalog and customized), designs, service tickets,
 * Luma messages and cancellation reasons. Refuses to run unless the database name ends in _test.
 *   php tests/UiLinks/seed_admin_menu.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\CustomDesignRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusEvent;
use App\Models\Product;
use App\Models\UnansweredIdea;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$customer = User::where('email', $login['customer_email'])->firstOrFail();
$others = [];
foreach ([['Maria', 'Santos'], ['Jose', 'Reyes'], ['Ana', 'Lopez']] as [$f, $l]) {
    $u = User::firstOrNew(['email' => strtolower($f) . '.walk@example.test']);
    if (!$u->exists) { $u->forceFill(['first_name' => $f, 'last_name' => $l, 'password' => 'x', 'role' => 'customer', 'is_validated' => $f !== 'Ana', 'account_status' => 'active', 'created_at' => now()->subDays(rand(2, 40))])->save(); }
    $others[] = $u;
}
$people = array_merge([$customer], $others);

// a long wood idea (120 characters is the most the page ever stores) and the requests that typed it
$long = 'I want my dining table made from the old molave wood my grandfather kept at our farm for many years, with a carved edge';
if (!UnansweredIdea::where('text_key', mb_strtolower($long))->exists()) {
    $row = UnansweredIdea::record('wood', $long);
    $row->forceFill(['times_asked' => 2, 'last_seen_at' => now()->subHours(2)])->save();
    foreach ([[$others[0], 'Table'], [$others[1], 'Bed']] as [$u, $type]) {
        (new CustomDesignRequest())->forceFill(['user_id' => $u->id, 'title' => 'Walk', 'furniture_type' => $type, 'preferred_material' => 'Special request', 'wood_special_details' => $long, 'description' => 'walk', 'status' => 'pending', 'created_at' => now()->subDays(1)])->save();
    }
}
foreach ([['Teak', 'Chair'], ['Teak', 'Cabinet'], ['Molave', 'Table']] as [$wood, $type]) {
    if (!CustomDesignRequest::where('wood_special_details', $wood)->where('furniture_type', $type)->exists()) {
        (new CustomDesignRequest())->forceFill(['user_id' => $people[array_rand($people)]->id, 'title' => 'Walk', 'furniture_type' => $type, 'preferred_material' => 'Special request', 'wood_special_details' => $wood, 'description' => 'walk', 'status' => 'pending', 'created_at' => now()->subDays(3)])->save();
    }
}
(new CustomDesignRequest())->forceFill(['user_id' => $others[2]->id, 'title' => 'Walk', 'furniture_type' => 'Other', 'furniture_type_other' => 'Pergola', 'description' => 'a table with a secret jewelry compartment and a glass top', 'status' => 'pending', 'created_at' => now()->subDays(2)])->save();

if (Order::where('order_number', 'like', 'WALK-%')->exists()) { echo "orders already seeded\n"; exit; }

// products, orders over six months, designs, tickets, Luma
$products = [];
foreach ([['Narra Dining Table', 'Narra', 'Table', 4], ['4-Door Wardrobe Cabinet', 'Mahogany', 'Cabinet', 0], ['Executive Office Chair', 'Acacia', 'Chair', 22], ['King Bed Frame', 'Narra', 'Bed', 2]] as [$n, $m, $c, $stock]) {
    $products[] = Product::firstOrCreate(['product_name' => $n], ['price' => 5000, 'stock_quantity' => $stock, 'product_status' => 'available', 'material' => $m, 'category' => $c]);
}
$n = 0;
for ($m = 5; $m >= 0; $m--) {
    foreach (range(1, rand(3, 7)) as $i) {
        $status = ['pending', 'confirmed', 'in_production', 'ready', 'completed', 'completed', 'cancelled'][rand(0, 6)];
        $total = rand(2, 40) * 1000;
        $o = (new Order())->forceFill(['user_id' => $people[array_rand($people)]->id, 'order_number' => 'WALK-' . (++$n), 'total_amount' => $total, 'delivery_option' => 'pickup', 'delivery_fee' => 0, 'order_status' => $status,
            'amount_paid' => $status === 'pending' ? 0 : $total * rand(5, 10) / 10]);
        $o->created_at = now()->startOfMonth()->subMonths($m)->addDays(rand(0, 18));
        $o->save();
        $p = $products[array_rand($products)];
        OrderItem::create(['order_id' => $o->id, 'product_id' => $p->id, 'product_name' => $p->product_name, 'quantity' => rand(1, 3), 'unit_price' => 5000, 'subtotal' => 5000]);
        if ($status === 'cancelled') {
            $reason = ['Changed My Mind', 'Ordered by Mistake', 'Found a Better Price'][rand(0, 2)];
            OrderStatusEvent::create(['order_id' => $o->id, 'admin_user_id' => null, 'type' => 'cancel', 'title' => 'Order Cancelled', 'from_status' => 'pending', 'to_status' => 'cancelled', 'note' => 'Cancelled by the customer. Reason: ' . $reason, 'customer_notified' => true]);
        }
    }
}
foreach (range(0, 119) as $i) {
    $at = now()->subDays(rand(0, 170));
    DB::table('chatbot_logs')->insert(['user_id' => null, 'session_id' => 'walk', 'message' => ['Do you deliver to Batangas City?', 'How long does a custom order take?', 'Can I pay by installment?', 'do you make boats'][rand(0, 3)], 'response' => 'r', 'intent_detected' => rand(0, 5) === 0 ? 'unmatched' : 'intent:1', 'created_at' => $at]);
}
DB::table('luma_unanswered_questions')->insertOrIgnore([['question_key' => 'do you make boats', 'question' => 'do you make boats', 'times_asked' => 6, 'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()], ['question_key' => 'free assembly', 'question' => 'free assembly', 'times_asked' => 4, 'last_asked_at' => now(), 'created_at' => now(), 'updated_at' => now()]]);
foreach (range(1, 14) as $i) {
    $at = now()->subDays(rand(0, 150));
    $r = (new CustomDesignRequest())->forceFill(['user_id' => $people[array_rand($people)]->id, 'title' => 'Walk design', 'furniture_type' => 'Table', 'description' => 'walk', 'status' => $i % 4 === 0 ? 'approved' : 'pending', 'pipeline_status' => $i % 4 === 0 ? 'submitted' : null]);
    $r->created_at = $at; $r->save();
    DB::table('ai_design_suggestions')->insert(['design_id' => $r->id, 'image_path' => 'x.jpg', 'generated_at' => $at]);
}
foreach (['repair', 'repair', 'labor_only_own_wood', 'other'] as $i => $type) {
    DB::table('service_inquiries')->insert(['user_id' => $customer->id, 'reference_number' => 'SI-2026-9' . $i . rand(10, 99), 'service_type' => $type, 'concern' => 'walk', 'name' => 'Walk', 'phone' => '09171234567', 'status' => $i === 0 ? 'closed' : 'new', 'created_at' => now()->subDays(rand(0, 20)), 'updated_at' => now()]);
}
echo "seeded\n";
