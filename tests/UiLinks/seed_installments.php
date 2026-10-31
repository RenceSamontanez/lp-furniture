<?php
/**
 * Installments walk: SCRATCH data only. Needs tests/Rbac/seed_screenshots.php first (admin login) and a migrated furniture_db_test.
 * Refuses to run unless the database name ends in _test. Re-running replaces what it made (emails ending @walk-installments.test).
 *   php tests/UiLinks/seed_installments.php
 * Makes: Maria (Good, 70) with a started 6-payment card plan, Jose (GCash plan, Pay Now link sent), Ana (Overdue, Fair 50),
 * Mark (a declined card, retry tomorrow), and three customers with a ₱38,500 cart: Good, Fair (cap ₱30,000) and Suspended.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\InstallmentAttempt;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\StandingEvent;
use App\Models\User;
use App\Support\InstallmentScheduler;
use Illuminate\Support\Facades\DB;

$ids = User::where('email', 'like', '%@walk-installments.test')->pluck('id');
Order::whereIn('user_id', $ids)->delete();
DB::table('cart_items')->whereIn('user_id', $ids)->delete();
StandingEvent::whereIn('user_id', $ids)->delete();
User::whereIn('id', $ids)->delete();

$mk = function (string $first, string $last, int $score = 70) {
    $u = User::create(['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first) . '@walk-installments.test', 'password' => bcrypt('walk-pass-123'),
        'role' => 'customer', 'is_validated' => true, 'account_status' => 'active', 'phone_number' => '09171234567', 'phone_e164' => '+639171234567', 'phone_country' => 'PH']);
    DB::table('users')->where('id', $u->id)->update(['installment_standing_score' => $score]);

    return $u;
};

$plan = function (User $u, string $number, string $method, array $offsets, array $statuses, int $months = 3, int $freq = 15) {
    $order = Order::create(['user_id' => $u->id, 'order_number' => $number, 'total_amount' => 38500, 'delivery_option' => 'pickup', 'production_status' => 'pending', 'order_status' => 'confirmed']);
    OrderItem::create(['order_id' => $order->id, 'product_name' => 'King Bed Frame, Narra', 'quantity' => 1, 'unit_price' => 38500, 'subtotal' => 38500]);
    $p = Payment::create(['order_id' => $order->id, 'user_id' => $u->id, 'amount' => 19250, 'payment_method' => $method, 'payment_type' => 'installment',
        'installment_duration_months' => $months, 'installment_frequency_days' => $freq, 'payment_status' => 'paid', 'paid_at' => now()->subDays(15),
        'paymongo_customer_id' => null, 'installment_card_method_id' => $method === 'paymongo_card' ? 'sim_card_walk' : null, 'card_simulated' => $method === 'paymongo_card',
        'card_brand' => 'Visa', 'card_last4' => '4345', 'installments_started_at' => now()->subDays(15), 'terms_version' => config('installments.terms_version'), 'terms_accepted_at' => now()->subDays(15)]);
    PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $p->id, 'amount' => 19250, 'method' => $method === 'paymongo_card' ? 'card_paymongo' : 'gcash_paymongo', 'gateway_reference' => 'pi_walk_' . $number, 'paid_on' => now()->subDays(15)->toDateString()]);
    InstallmentScheduler::createSchedule($p, $order, 19250, $months, $freq);
    $rows = InstallmentSchedule::where('payment_id', $p->id)->orderBy('installment_no')->get();
    $paid = 19250.0;
    foreach ($rows as $i => $r) {
        $st = $statuses[$i] ?? 'pending';
        $r->forceFill(['due_date' => now('Asia/Manila')->addDays($offsets[$i] ?? 15 * ($i + 1))->toDateString(), 'status' => $st,
            'paid_at' => $st === 'paid' ? now()->subDay() : null, 'paid_via' => $st === 'paid' ? 'auto' : null,
            'overdue_at' => $st === 'overdue' ? now()->subDay() : null, 'charge_failed' => in_array($st, ['failed', 'link_sent', 'overdue'], true),
            'attempts' => in_array($st, ['failed'], true) ? 1 : 0, 'next_attempt_at' => $st === 'failed' ? now()->addDay() : null,
            'grace_ends_at' => in_array($st, ['failed', 'link_sent'], true) ? now()->addDays(2) : null])->save();
        if ($st === 'paid') {
            PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $p->id, 'installment_schedule_id' => $r->id, 'amount' => $r->amount_due, 'method' => $method === 'paymongo_card' ? 'card_paymongo' : 'gcash_paymongo', 'gateway_reference' => "pi_walk_{$number}_{$i}", 'paid_on' => now()->toDateString()]);
            $paid += (float) $r->amount_due;
        }
        InstallmentAttempt::create(['installment_schedule_id' => $r->id, 'kind' => 'reminder', 'result' => 'info', 'message' => 'Reminder sent to the customer']);
        if ($st === 'paid') { InstallmentAttempt::create(['installment_schedule_id' => $r->id, 'kind' => 'charge', 'result' => 'ok', 'message' => 'Charged ₱' . number_format((float) $r->amount_due, 2) . ' by card', 'gateway_reference' => "pi_walk_{$number}_{$i}"]); }
        if ($st === 'failed') { InstallmentAttempt::create(['installment_schedule_id' => $r->id, 'kind' => 'charge', 'result' => 'failed', 'message' => 'Card declined (insufficient funds). Will retry tomorrow']); }
        if ($st === 'overdue') { InstallmentAttempt::create(['installment_schedule_id' => $r->id, 'kind' => 'overdue', 'result' => 'failed', 'message' => 'Grace period ended, marked Overdue']); }
    }
    $order->forceFill(['amount_paid' => $paid])->save();

    return [$order, $rows];
};

$maria = $mk('Maria', 'Santos', 74);
$jose = $mk('Jose', 'Reyes', 70);
$ana = $mk('Ana', 'Lopez', 50);
$mark = $mk('Mark', 'Tan', 74);
$plan($maria, 'LP-WALK-1058', 'paymongo_card', [-1, 14, 29, 44, 59, 74], ['paid']);
$plan($jose, 'LP-WALK-1061', 'gcash', [0, 15, 30], ['link_sent'], 1 + 1, 30);
$plan($ana, 'LP-WALK-1049', 'gcash', [-4, 11, 26, 41], ['overdue'], 2, 15);
$plan($mark, 'LP-WALK-1070', 'paymongo_card', [-1, 14], ['failed'], 1, 15);
foreach ([[$maria, 'LP-WALK-1058', 74], [$ana, 'LP-WALK-1049', 50]] as [$u, $n, $s]) {
    StandingEvent::create(['user_id' => $u->id, 'points' => 0, 'score_after' => 70, 'reason' => 'Standing started at 70 (Good)', 'created_at' => now()->subDays(40)]);
    StandingEvent::create(['user_id' => $u->id, 'points' => $s - 70, 'score_after' => $s, 'reason' => $s > 70 ? 'Installment 1 of 6 paid on its due date by auto-debit' : 'Installment 1 of 4 became Overdue', 'created_at' => now()->subDay()]);
}

$p = Product::firstOrCreate(['product_name' => 'Walk King Bed Frame'], ['price' => 38500, 'stock_quantity' => 9, 'product_status' => 'available']);
foreach ([['Gina', 'Good', 70], ['Fely', 'Fair', 50], ['Susan', 'Suspended', 10]] as [$f, $l, $s]) {
    $u = $mk($f, $l, $s);
    DB::table('cart_items')->insert(['user_id' => $u->id, 'product_id' => $p->id, 'quantity' => 1, 'added_at' => now(), 'updated_at' => now()]);
}
echo "seeded installments walk (scratch database)\n";
