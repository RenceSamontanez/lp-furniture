<?php
/**
 * Concept page: SCRATCH rows for the demo customer in three states (just sent, quote ready, paid order), using the AI images already
 * in public/images/ai-designs (read only). Refuses to run unless the database name ends in _test. Prints the three request ids.
 *   php tests/UiLinks/seed_concept.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\User;

$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$customer = User::where('email', $login['customer_email'])->firstOrFail();
$admin = User::where('email', $login['admin_email'])->firstOrFail();
$images = array_values(array_filter(scandir($root . '/public/images/ai-designs'), fn ($f) => str_starts_with($f, 'ai-design-backfill-')));

$mk = function (string $desc, string $pipeline, int $img, string $when) use ($customer, $images) {
    $r = new CustomDesignRequest();
    $r->forceFill([
        'user_id' => $customer->id, 'title' => 'Custom Furniture', 'furniture_type' => 'Furniture', 'description' => $desc,
        'ai_prompt' => 'Studio photo, ' . $desc, 'style_preference' => 'Modern', 'status' => 'approved', 'pipeline_status' => $pipeline, 'request_mode' => 'exact',
        'contact_phone' => '09171234567', 'preferred_call_window' => 'anytime', 'submitted_at' => now()->modify($when),
        'created_at' => now()->modify($when), 'updated_at' => now()->modify($when),
    ])->save();
    foreach ([$img, ($img + 1) % count($images)] as $k => $i) {
        $s = AiDesignSuggestion::create(['design_id' => $r->id, 'image_path' => 'images/ai-designs/' . $images[$i], 'is_selected' => $k === 0, 'is_favorited' => $k === 1, 'generated_at' => now()->modify($when)]);
        if ($k === 0) { $r->forceFill(['selected_suggestion_id' => $s->id])->save(); }
    }
    return $r;
};
$ev = fn ($r, string $type, string $when, array $data = []) => CustomOrderEvent::create(['request_id' => $r->id, 'type' => $type, 'actor_type' => 'system', 'data' => $data, 'occurred_at' => now()->modify($when)]);

// 1. just sent
$sent = $mk('A modern minimalist bookshelf in walnut, floor-to-ceiling, open-back shelving with three adjustable tiers.', 'submitted', 0, '-5 minutes');
$ev($sent, 'submitted', '-5 minutes');

// 2. quote ready
$quoted = $mk('A rustic oak dining table with turned legs and a distressed finish, seats six.', 'quoted', 1, '-2 days');
$ev($quoted, 'submitted', '-2 days'); $ev($quoted, 'call_update', '-2 days +30 minutes', ['outcome' => 'answered']);
$quote = CustomQuote::create(['request_id' => $quoted->id, 'version' => 1, 'status' => 'sent', 'price' => 30000, 'delivery_option' => 'pickup', 'delivery_fee' => 500,
    'payment_terms' => 'full', 'expires_at' => now()->addDays(5), 'width_cm' => 180, 'depth_cm' => 90, 'height_cm' => 75, 'production_days' => 21, 'material' => 'Oak', 'final_description' => 'Rustic oak dining table']);
$ev($quoted, 'quote_sent', '-1 day', ['version' => 1, 'total' => 30500, 'expires_at' => now()->addDays(5)->toIso8601String()]);

// 3. paid order
$paid = $mk('Design a modern front entrance door made from premium solid Narra wood with a clean minimalist design, vertical wooden panels, two narrow frosted glass side panels and a brushed-metal handle.', 'ordered', 2, '-9 days');
$q2 = CustomQuote::create(['request_id' => $paid->id, 'version' => 1, 'status' => 'accepted', 'price' => 30000, 'delivery_option' => 'pickup', 'delivery_fee' => 500, 'payment_terms' => 'full', 'expires_at' => now()->subDays(2)]);
$snap = ['design' => ['title' => 'Door'], 'price' => '30000.00', 'policy' => ['version' => 1, 'text' => 'x'], 'version' => 1];
$ag = DesignAgreement::create(['agreement_number' => 'LP-A-2026-9001', 'request_id' => $paid->id, 'quote_id' => $q2->id, 'quote_version' => 1, 'user_id' => $customer->id, 'finalized_by' => $admin->id,
    'sent_at' => now()->subDays(8), 'accepted_at' => now()->subDays(8)->addMinutes(5), 'accepted_at_local' => '-', 'ip_address' => '127.0.0.1', 'user_agent' => 'seed', 'policy_version' => 1,
    'terms_snapshot' => $snap, 'snapshot_sha256' => DesignAgreement::hashSnapshot($snap), 'total_amount' => 30500, 'upfront_amount' => 30500]);
$order = Order::create(['user_id' => $customer->id, 'order_number' => 'LP-2026-9001', 'total_amount' => 30500, 'delivery_option' => 'pickup', 'delivery_fee' => 500, 'order_status' => 'confirmed']);
$order->forceFill(['design_agreement_id' => $ag->id, 'amount_paid' => 30500, 'expected_ready_date' => now()->addDays(20)->toDateString()])->save();
$pay = Payment::create(['order_id' => $order->id, 'user_id' => $customer->id, 'amount' => 30500, 'payment_method' => 'stripe', 'payment_type' => 'full', 'payment_status' => 'paid']);
PaymentRecord::create(['order_id' => $order->id, 'payment_id' => $pay->id, 'amount' => 30500, 'method' => 'stripe', 'gateway_reference' => 'pi_seed_9001', 'paid_on' => now()->subDays(8)->toDateString()]);
$ev($paid, 'submitted', '-9 days'); $ev($paid, 'call_update', '-9 days +3 minutes', ['outcome' => 'answered']);
$ev($paid, 'quote_sent', '-9 days +4 minutes', ['version' => 1, 'total' => 30500, 'expires_at' => now()->subDays(2)->toIso8601String()]);
$ev($paid, 'quote_accepted', '-8 days', ['agreement_number' => 'LP-A-2026-9001']);
$ev($paid, 'order_placed', '-8 days +1 minute', ['order_number' => 'LP-2026-9001']);
$ev($paid, 'order_confirmed', '-8 days +2 minutes');

echo json_encode(['sent' => $sent->id, 'quoted' => $quoted->id, 'paid' => $paid->id]);
