<?php

/**
 * Stage 21e: REAL Stripe (and PayMongo) TEST-MODE check of the Other Services invoice payment start.
 *
 *   php tests/Stripe/invoice_live_check.php
 *
 * It builds a scratch invoice (half and half, 3,000 pesos, so the first half is 1,500 pesos), then runs the
 * real ServiceInvoicePayments::start() against the real gateway classes, exactly as a customer's "Pay with card"
 * and "Pay with GCash" buttons do, and checks what the gateway recorded.
 *
 * Safety:
 *  - refuses to run unless the database name ends in _test,
 *  - refuses to run unless STRIPE_SECRET starts with sk_test_ (never live, never empty). PayMongo is only checked
 *    when PAYMONGO_SECRET_KEY starts with sk_test_; otherwise that part is skipped,
 *  - never pays anything. The Stripe Checkout Session it creates is expired before the script exits; a PayMongo
 *    test-mode intent that nobody pays simply stays unpaid,
 *  - prints only the last 6 characters of any gateway id, and never a key,
 *  - the scratch rows it creates are removed at the end.
 */

putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
putenv('APP_ENV=testing');
putenv('SESSION_DRIVER=array');
putenv('CACHE_STORE=array');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';
$_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'array';

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
// route() needs a request when the app is booted outside a web request
$baseUrl = config('app.url') ?: 'http://127.0.0.1:8000';
$app->instance('request', Illuminate\Http\Request::create($baseUrl));
Illuminate\Support\Facades\URL::forceRootUrl($baseUrl);

use App\Models\Notification;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoicePayment;
use App\Models\User;
use App\Support\InvoiceGateway;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use Illuminate\Support\Facades\DB;

$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: database is '{$db}'\n");
    exit(99);
}

$stripeSecret = (string) config('services.stripe.secret');
if (!str_starts_with($stripeSecret, 'sk_test_')) {
    fwrite(STDERR, "REFUSING: STRIPE_SECRET is not a test-mode key. No call was made.\n");
    exit(98);
}
$paymongoOk = str_starts_with((string) config('services.paymongo.secret_key'), 'sk_test_');

$tail = fn ($id) => '...' . substr((string) $id, -6);
$line = fn (string $s) => print($s . "\n");
$fail = 0;
$check = function (string $what, bool $ok) use (&$fail, $line) {
    $line(($ok ? '  PASS  ' : '  FAIL  ') . $what);
    $ok || $fail++;
};

$gateway = app(InvoiceGateway::class);   // the real one
$sessionIds = [];
$userIds = [];
$ticketId = null;
$invoiceId = null;

try {
    $tag = bin2hex(random_bytes(3));
    foreach (['customer', 'admin'] as $role) {
        $u = new User();
        $u->forceFill([
            'first_name' => 'Live', 'last_name' => ucfirst($role), 'email' => "invoice-live-{$role}-{$tag}@stage21e.example.test",
            'password' => 'not-a-real-password', 'role' => $role, 'is_validated' => true, 'account_status' => 'active',
        ])->save();
        $userIds[$role] = $u->id;
    }
    $admin = User::find($userIds['admin']);

    $ticket = ServiceInquiry::create([
        'reference_number' => 'LIVE-' . strtoupper($tag), 'service_type' => 'repair', 'furniture_type' => 'Chair', 'concern' => 'Live check.',
        'name' => 'Live Check', 'phone' => '09171234567', 'email' => "invoice-live-customer-{$tag}@stage21e.example.test", 'status' => 'new', 'user_id' => $userIds['customer'],
    ]);
    $ticketId = $ticket->id;
    $desk = app(ServiceInvoiceDesk::class);
    $draft = $desk->createDraft($ticket->id, $admin, ServiceInvoiceDesk::validate([
        'service_description' => 'Live check repair', 'service_cost' => '3000', 'delivery_option' => 'none',
        'customer_name' => 'Live Check', 'customer_phone' => '09171234567', 'payment_plan' => 'half_half',
    ]));
    $invoiceId = $draft->id;
    $invoice = $desk->issue($draft->id, $admin);
    $line("Scratch invoice {$invoice->invoice_number}: total 3,000.00, half and half, first half 1,500.00 (database {$db})");

    $payments = app(ServiceInvoicePayments::class);

    /* ── Stripe ── */
    $line('Stripe (test mode):');
    $url = $payments->start($invoiceId, 'stripe');
    $row = ServiceInvoicePayment::where('service_invoice_id', $invoiceId)->where('method', 'stripe')->firstOrFail();
    $sessionIds[] = $row->provider_reference;
    $line('  session ' . $tail($row->provider_reference) . ' at ' . parse_url($url, PHP_URL_HOST));
    $session = $gateway->retrieveStripeSession($row->provider_reference);
    $check('the session is open', ($session->status ?? null) === 'open');
    $check('Stripe will charge exactly 1,500.00 PHP (150000 centavos)', (int) $session->amount_total === 150000 && strtolower((string) $session->currency) === 'php');
    $check('metadata ties it to this invoice payment', ($session->metadata->kind ?? null) === 'service_invoice'
        && (string) ($session->metadata->service_invoice_payment_id ?? '') === (string) $row->id);
    $expires = (int) ($session->expires_at ?? 0);
    $check('expires between 31 minutes and 23 hours from now', $expires > time() + 31 * 60 && $expires < time() + 23 * 3600 + 60);
    $check('our pending row stores the session id and checkout url', $row->status === 'pending' && $row->checkout_url === $url && (float) $row->amount === 1500.0);
    $again = $payments->start($invoiceId, 'stripe');
    $check('a second tap reuses the same open session (no second payment row)', $again === $url
        && ServiceInvoicePayment::where('service_invoice_id', $invoiceId)->where('method', 'stripe')->count() === 1);

    /* ── PayMongo ── */
    if ($paymongoOk) {
        $line('PayMongo (test mode):');
        $url2 = $payments->start($invoiceId, 'paymongo');
        $row2 = ServiceInvoicePayment::where('service_invoice_id', $invoiceId)->where('method', 'paymongo')->firstOrFail();
        $line('  intent ' . $tail($row2->provider_reference) . ' redirect host ' . parse_url($url2, PHP_URL_HOST));
        $intent = $gateway->retrievePaymongoIntent($row2->provider_reference);
        $check('PayMongo recorded the intent for exactly 150000 centavos', $intent['amount_cents'] === 150000);
        $check('the intent is waiting for the customer, not paid', $intent['status'] !== 'succeeded');
    } else {
        $line('PayMongo: SKIPPED (no sk_test_ key configured).');
    }

    $line('Nothing was paid. Final invoice status: ' . $invoice->fresh()->status . ', amount paid ' . $invoice->fresh()->amount_paid);
} catch (Throwable $e) {
    $fail++;
    $line('ERROR: ' . get_class($e) . ': ' . preg_replace('/(sk|pk|whsec)_[A-Za-z0-9_]+/', '$1_***', $e->getMessage()));
} finally {
    // expire every session we opened, so none is left payable
    foreach ($sessionIds as $sid) {
        try {
            $gateway->expireStripeSession($sid);
            $line('Expired Stripe session ' . $tail($sid));
        } catch (Throwable $e) {
            $line('Could not expire ' . $tail($sid) . ' (it may already be expired).');
        }
    }
    // scratch cleanup (sent invoices are locked at the model layer, so use the query builder; scratch database only)
    if ($invoiceId) {
        DB::table('service_invoice_payments')->where('service_invoice_id', $invoiceId)->delete();
        DB::table('service_invoice_lines')->where('invoice_id', $invoiceId)->delete();
        DB::table('service_invoices')->where('id', $invoiceId)->delete();
    }
    if ($ticketId) {
        DB::table('service_inquiries')->where('id', $ticketId)->delete();
    }
    if ($userIds) {
        Notification::whereIn('user_id', array_values($userIds))->delete();
        User::whereIn('id', array_values($userIds))->delete();
    }
}

$line($fail === 0 ? 'ALL CHECKS PASSED' : "{$fail} CHECK(S) FAILED");
exit($fail === 0 ? 0 : 1);
