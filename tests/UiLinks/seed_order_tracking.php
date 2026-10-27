<?php
/**
 * Order tracking walk: SCRATCH rows only (refuses to run unless the database name ends in _test).
 * Makes an admin, a customer and five catalog orders in different states, and a stand-in progress photo.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_order_tracking.php
 *
 * Prints the order ids as JSON. Sign-in is done by minting a session (tests/UiLinks/session-cookie-for.php), never the real form.
 */
putenv('DB_DATABASE=furniture_db_test');
putenv('MAIL_MAILER=log');   // never send real email from a walk
$_ENV['MAIL_MAILER'] = $_SERVER['MAIL_MAILER'] = 'log';
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentRecord;
use App\Models\Product;
use App\Models\User;
use App\Support\OrderTracking;
use Illuminate\Support\Facades\Storage;

$mk = function (string $key, string $first, string $last, string $role) {
    $u = new User();
    $u->forceFill([
        'first_name' => $first, 'last_name' => $last, 'email' => "ot-{$key}@example.test", 'password' => 'Walk-Pass-123',
        'role' => $role, 'account_status' => 'active', 'is_validated' => true, 'email_verified_at' => now()->subDays(30),
        'phone_number' => '09171234567', 'phone_e164' => '+639171234567', 'phone_country' => 'PH',
    ])->save();
    return $u;
};
$admin = $mk('admin', 'Rhea', 'Admin', 'admin');
$cust  = $mk('customer', 'Rence', 'Samontanez', 'customer');

$product = Product::create(['product_name' => 'Narra Dining Table', 'price' => 13500, 'stock_quantity' => 20, 'product_status' => 'available']);

$snapshot = ['recipient' => 'Rence Samontanez', 'phone' => '+639171234567', 'province' => 'Batangas', 'city' => 'San Jose', 'barangay' => 'Banaybanay I',
    'street' => '157', 'postal_code' => '4227', 'full' => '157, Brgy. Banaybanay I, San Jose, Batangas 4227'];

$make = function (string $status, string $option, float $total, float $required, float $paid, string $type = 'full') use ($cust, $product, $snapshot, $admin) {
    $o = Order::create([
        'user_id' => $cust->id, 'order_number' => 'LP-2026-' . str_pad((string) (Order::count() + 1), 5, '0', STR_PAD_LEFT),
        'total_amount' => $total, 'delivery_option' => $option, 'delivery_fee' => $option === 'delivery' ? 500 : 0,
        'delivery_address' => $option === 'delivery' ? $snapshot['full'] : null, 'delivery_snapshot' => $option === 'delivery' ? $snapshot : null,
        'order_status' => 'pending', 'production_status' => 'pending',
    ]);
    OrderItem::create(['order_id' => $o->id, 'product_id' => $product->id, 'product_name' => $product->product_name, 'quantity' => 1,
        'unit_price' => $total - ($option === 'delivery' ? 500 : 0), 'subtotal' => $total - ($option === 'delivery' ? 500 : 0)]);
    $p = Payment::create(['order_id' => $o->id, 'user_id' => $cust->id, 'amount' => $required, 'payment_method' => 'stripe', 'payment_type' => $type,
        'payment_status' => $paid >= $required && $paid > 0 ? 'paid' : 'pending']);
    OrderTracking::recordPlaced($o);
    if ($paid > 0) {
        PaymentRecord::create(['order_id' => $o->id, 'payment_id' => $p->id, 'amount' => $paid, 'method' => 'stripe', 'gateway_reference' => 'pi_walk_' . $o->id, 'paid_on' => now()->toDateString()]);
        $o->forceFill(['amount_paid' => $paid])->save();
        OrderTracking::recordPaymentEvent($o, $paid, 'Stripe');
    }
    $o->forceFill(['order_status' => $status])->save();
    return $o->fresh();
};

// photo for the progress update
$png = imagecreatetruecolor(480, 320);
imagefill($png, 0, 0, imagecolorallocate($png, 190, 140, 80));
imagefilledrectangle($png, 60, 120, 420, 250, imagecolorallocate($png, 120, 80, 40));
ob_start(); imagepng($png); $bytes = ob_get_clean();
Storage::disk('public')->put('order-update-photos/walk-demo.png', $bytes);

$tracking = app(OrderTracking::class);

// A: delivery, paid in full, confirmed automatically, then in production with a message and a photo
$a = $make('pending', 'delivery', 14000, 14000, 14000);
$tracking->autoConfirm($a->fresh());
$a = $a->fresh();
$tracking->advance($a->id, $admin, 'confirmed', true, 'The frame is assembled. Sanding and staining are next.', null);
$a = $a->fresh();
\App\Models\OrderStatusEvent::where('order_id', $a->id)->where('type', 'stage')->latest('id')->first()->update(['photo_path' => 'order-update-photos/walk-demo.png']);

// B: pickup, unpaid
$b = $make('pending', 'pickup', 9500, 9500, 0);
// C: delivery, installment, down payment in, in production, one correction on record
$c = $make('pending', 'delivery', 20500, 10250, 10250, 'installment');
$tracking->autoConfirm($c->fresh());
$tracking->advance($c->id, $admin, 'confirmed', true, null, null);
$tracking->advance($c->id, $admin, 'in_production', true, null, null);
$tracking->correct($c->id, $admin, 'ready', 'Marked ready too early, finishing is not done') ;
// D: pickup, ready for pickup, paid in full
$d = $make('ready', 'pickup', 7200, 7200, 7200);
// E: cancelled
$e = $make('pending', 'delivery', 5000, 5000, 0);
$tracking->cancel($e->id, $admin, 'pending', 'Customer asked us to stop the order');

echo json_encode(['admin' => $admin->email, 'customer' => $cust->email, 'a' => $a->id, 'b' => $b->id, 'c' => $c->id, 'd' => $d->id, 'e' => $e->id]);
