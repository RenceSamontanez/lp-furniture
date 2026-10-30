<?php
/**
 * Admin Customers list and profile walk: SCRATCH data only. Needs tests/Rbac/seed_screenshots.php first. Refuses to run unless the
 * database name ends in _test. Re-running replaces what it made (accounts ending @walk-customers.test).
 *   php tests/UiLinks/seed_customers_admin.php          (add --empty to leave only customers with nothing on them)
 * Makes 23 customers: one with 14 orders (cancelled, partly paid, completed), designs (legacy, in the pipeline, with no admin page),
 * a name change, Luma chats, a saved address; one with a very long name and email; a pending identity; one with nothing at all.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\CustomDesignRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Province;
use App\Models\User;
use App\Models\UserNameChange;
use App\Models\UserValidation;
use Illuminate\Support\Facades\DB;

$empty = in_array('--empty', $argv, true);
$old = User::where('email', 'like', '%@walk-customers.test')->pluck('id');
if ($old->isNotEmpty()) {
    DB::table('chatbot_logs')->whereIn('user_id', $old)->delete();
    DB::table('order_items')->whereIn('order_id', Order::whereIn('user_id', $old)->pluck('id'))->delete();
    DB::table('payment_records')->whereIn('order_id', Order::whereIn('user_id', $old)->pluck('id'))->delete();
    Order::whereIn('user_id', $old)->delete();
    DB::table('ai_design_suggestions')->whereIn('design_id', CustomDesignRequest::whereIn('user_id', $old)->pluck('id'))->delete();
    CustomDesignRequest::whereIn('user_id', $old)->delete();
    UserValidation::whereIn('user_id', $old)->delete();
    UserNameChange::whereIn('user_id', $old)->delete();
    User::whereIn('id', $old)->delete();
}

$prov = Province::firstOrCreate(['name' => 'Batangas']);
$city = CityMunicipality::firstOrCreate(['province_id' => $prov->id, 'name' => 'San Juan']);
$brgy = Barangay::firstOrCreate(['city_municipality_id' => $city->id, 'name' => 'Calit-calit']);

$make = function (string $first, string $last, array $o = []) {
    static $n = 0; $n++;
    $u = new User();
    $u->forceFill(array_merge([
        'first_name' => $first, 'last_name' => $last, 'email' => 'walk' . $n . '@walk-customers.test', 'password' => 'x', 'role' => 'customer',
        'is_validated' => false, 'account_status' => 'active', 'phone_number' => '0917' . str_pad((string) (1000000 + $n * 37), 7, '0', STR_PAD_LEFT),
        'created_at' => now()->subDays($n * 9), 'updated_at' => now(),
    ], $o))->save();

    return $u;
};
$order = function (User $u, float $total, float $paid, string $status, ?string $item = null, $when = null) {
    static $k = 0; $k++;
    $o = Order::create(['user_id' => $u->id, 'order_number' => 'LP-W-' . str_pad((string) $k, 4, '0', STR_PAD_LEFT), 'total_amount' => $total, 'delivery_option' => 'pickup', 'delivery_fee' => 0, 'order_status' => $status]);
    $o->forceFill(['amount_paid' => $paid, 'created_at' => $when ?? now()->subDays($k)])->save();
    if ($item) { OrderItem::create(['order_id' => $o->id, 'product_id' => null, 'product_name' => $item, 'quantity' => 1, 'unit_price' => $total, 'subtotal' => $total]); }

    return $o;
};

$names = [['Maria', 'Santos'], ['Jose', 'Reyes'], ['Ana', 'Lopez'], ['Carlo', 'Mendoza'], ['Liza', 'Garcia'], ['Paolo', 'Cruz'], ['Bea', 'Navarro'], ['Dante', 'Ramos'], ['Elena', 'Torres'], ['Felix', 'Aquino'], ['Gina', 'Bautista'], ['Hector', 'Castillo'], ['Iris', 'Domingo'], ['Jun', 'Estrada'], ['Kara', 'Flores'], ['Luis', 'Gonzales'], ['Mia', 'Hernandez'], ['Noel', 'Ibarra']];
$people = [];
foreach ($names as $i => [$f, $l]) { $people[] = $make($f, $l, ['is_validated' => $i % 3 === 0, 'email_verified_at' => $i % 2 ? now() : null]); }

if (!$empty) {
    // the busy customer: first in the list when sorted by orders
    $busy = $make('Rosalinda', 'Villanueva-Montenegro', [
        'email' => 'rosalinda.villanueva.montenegro.longaddress@walk-customers.test', 'is_validated' => true, 'email_verified_at' => now()->subMonths(4), 'created_at' => now()->subMonths(5),
        'address_province_id' => $prov->id, 'address_city_id' => $city->id, 'address_barangay_id' => $brgy->id, 'address_street' => 'Sitio Pulo Ilaya', 'address_postal_code' => '4226',
    ]);
    $statuses = ['pending', 'confirmed', 'in_production', 'ready', 'out_for_delivery', 'completed', 'completed', 'completed', 'cancelled', 'completed', 'confirmed', 'completed', 'completed', 'pending'];
    foreach ($statuses as $i => $s) {
        $total = 2500 + $i * 750;
        $paid = $s === 'pending' ? 0 : ($s === 'cancelled' ? $total : ($s === 'confirmed' ? round($total / 2) : $total));
        $order($busy, $total, $paid, $s, ['King Bed Frame', 'Narra Dining Table', 'TV Console Cabinet', 'Executive Office Chair'][$i % 4], now()->subDays(3 + $i * 6));
    }
    UserNameChange::create(['user_id' => $busy->id, 'old_first_name' => 'Rosalinda', 'old_last_name' => 'Villanueva', 'new_first_name' => 'Rosalinda', 'new_last_name' => 'Villanueva-Montenegro', 'was_verified' => true]);
    foreach ([['Narra Bed', 'approved', 'quoted'], ['Mahogany Table', 'approved', null], ['Pending Shelf', 'pending', null], ['Ordered Chair', 'converted_to_order', 'ordered']] as $i => [$title, $status, $pipe]) {
        (new CustomDesignRequest())->forceFill(['user_id' => $busy->id, 'title' => $title, 'furniture_type' => 'Bed', 'description' => 'walk', 'status' => $status, 'pipeline_status' => $pipe, 'created_at' => now()->subDays(2 + $i * 5)])->save();
    }
    $r = CustomDesignRequest::where('user_id', $busy->id)->orderBy('id')->first();
    DB::table('ai_design_suggestions')->insert([['design_id' => $r->id, 'image_path' => 'images/ai-designs/none.jpg', 'ai_model_used' => 'x', 'prompt_used' => 'x', 'generated_at' => now()], ['design_id' => $r->id, 'image_path' => 'images/ai-designs/none2.jpg', 'ai_model_used' => 'x', 'prompt_used' => 'x', 'generated_at' => now()]]);
    foreach ([1, 1, 2] as $d) { DB::table('chatbot_logs')->insert(['user_id' => $busy->id, 'session_id' => 'w', 'message' => 'walk question', 'response' => 'ok', 'intent_detected' => 'x', 'created_at' => now()->subDays($d)]); }

    // a second customer with a few orders, a pending identity and a rejected one
    $few = $make('Test', 'User', ['is_validated' => false, 'created_at' => now()->subDays(40)]);
    $order($few, 6800, 6800, 'confirmed', 'Narra Dining Table'); $order($few, 3500, 0, 'pending', 'Side Table');
    UserValidation::create(['user_id' => $few->id, 'attempt_number' => 1, 'id_photo_path' => 'x', 'selfie_photo_path' => 'y', 'status' => 'pending', 'submitted_at' => now()->subHours(5)]);
    $rej = $make('Nina', 'Rejected', ['created_at' => now()->subDays(12)]);
    UserValidation::create(['user_id' => $rej->id, 'attempt_number' => 1, 'id_photo_path' => 'x', 'selfie_photo_path' => 'y', 'status' => 'rejected', 'submitted_at' => now()->subDays(3), 'reviewed_at' => now()->subDays(2), 'rejection_reason' => 'The photo is blurry.']);
    $make('Nothing', 'Yet', ['phone_number' => null, 'created_at' => now()->subDay()]);
}
echo "seeded customers for the walk (" . User::where('email', 'like', '%@walk-customers.test')->count() . ") in furniture_db_test\n";
