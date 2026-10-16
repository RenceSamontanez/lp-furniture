<?php

/**
 * Stage 14: REAL Stripe TEST-MODE verification of what the controller sends.
 *
 *   php tests/Stripe/live_check.php
 *
 * Safety:
 *  - refuses to run unless the database name ends in _test,
 *  - refuses to run unless STRIPE_SECRET starts with sk_test_ (never live, never empty),
 *  - never pays a session, every session it creates is expired before it exits,
 *  - prints only the last 6 characters of any Stripe id, and never a key.
 * The scratch rows it creates (users, orders, agreements) are removed at the end.
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
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

use App\Models\CustomDesignRequest;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Stripe\StripeClient;

$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: database is '{$db}'\n");
    exit(99);
}

$secret = (string) config('services.stripe.secret');
if (!str_starts_with($secret, 'sk_test_')) {
    fwrite(STDERR, "REFUSING: STRIPE_SECRET is not a test-mode key. No call was made.\n");
    exit(98);
}

$stripe = new StripeClient($secret);
$tail = fn ($id) => '...' . substr((string) $id, -6);
$created = [];     // every session id we make, expired at the end
$rows = ['users' => [], 'orders' => [], 'requests' => [], 'agreements' => [], 'quotes' => []];
$out = [];

$line = function (string $s) use (&$out) { $out[] = $s; echo $s, "\n"; };

try {
    $tag = bin2hex(random_bytes(3));
    $customer = new User();
    $customer->forceFill([
        'first_name' => 'Live', 'last_name' => 'Check', 'email' => "live-check-{$tag}@stage14.example.test",
        'password' => 'not-a-real-password', 'role' => 'customer', 'is_validated' => true, 'account_status' => 'active',
    ])->save();
    $admin = new User();
    $admin->forceFill([
        'first_name' => 'Live', 'last_name' => 'Admin', 'email' => "live-admin-{$tag}@stage14.example.test",
        'password' => 'not-a-real-password', 'role' => 'admin', 'is_validated' => true, 'account_status' => 'active',
    ])->save();
    $rows['users'] = [$customer->id, $admin->id];

    $n = 0;
    $makeOrder = function (string $shape, float $amount, string $type, ?\Illuminate\Support\Carbon $due) use (&$n, $customer, $admin, &$rows, $tag) {
        $n++;
        $custom = $due !== null;
        $order = new Order();
        $attrs = [
            'user_id' => $customer->id, 'order_number' => "LP-LIVE-{$tag}-{$n}", 'total_amount' => $custom ? 20500 : 1000,
            'delivery_option' => 'pickup', 'delivery_fee' => 0, 'production_status' => 'pending', 'order_status' => 'pending',
        ];

        if ($custom) {
            $r = CustomDesignRequest::create([
                'user_id' => $customer->id, 'title' => "Live check {$shape}", 'furniture_type' => 'Table', 'description' => 'x',
                'status' => 'approved', 'pipeline_status' => 'ordered',
            ]);
            $r->forceFill(['payment_due_at' => $due])->save();
            $q = CustomQuote::create([
                'request_id' => $r->id, 'version' => 1, 'status' => 'accepted', 'price' => 20000, 'delivery_option' => 'pickup',
                'delivery_fee' => 0, 'payment_terms' => 'full', 'sent_by' => $admin->id,
            ]);
            $snapshot = ['design' => ['title' => $r->title], 'money' => ['total' => '20500.00'], 'production' => ['days' => 20]];
            $a = DesignAgreement::create([
                'agreement_number' => "LP-A-LIVE-{$tag}-{$n}", 'request_id' => $r->id, 'quote_id' => $q->id, 'quote_version' => 1,
                'user_id' => $customer->id, 'finalized_by' => $admin->id, 'sent_at' => now(), 'accepted_at' => now(),
                'accepted_at_local' => now('Asia/Manila')->format('Y-m-d H:i:s T'), 'ip_address' => '127.0.0.1',
                'policy_version' => 1, 'terms_snapshot' => $snapshot, 'snapshot_sha256' => DesignAgreement::hashSnapshot($snapshot),
                'total_amount' => 20500, 'upfront_amount' => $amount,
            ]);
            $attrs['design_request_id'] = $r->id;
            $attrs['design_agreement_id'] = $a->id;
            $rows['requests'][] = $r->id;
            $rows['quotes'][] = $q->id;
            $rows['agreements'][] = $a->id;
        }

        $order->forceFill($attrs)->save();
        $pay = new Payment();
        $pay->forceFill([
            'order_id' => $order->id, 'user_id' => $customer->id, 'amount' => $amount, 'payment_method' => 'stripe',
            'payment_type' => $type, 'payment_status' => 'pending',
        ])->save();
        $rows['orders'][] = $order->id;

        return $order;
    };

    $shapes = [
        ['catalog, full payment',             fn () => $makeOrder('catalog-full', 1000.00, 'full', null)],
        ['catalog, 50% downpayment',          fn () => $makeOrder('catalog-down', 500.00, 'installment', null)],
        ['custom, paid in full',              fn () => $makeOrder('custom-full', 20500.00, 'full', now()->addDays(5))],
        ['custom, deposit',                   fn () => $makeOrder('custom-deposit', 10250.00, 'installment', now()->addDays(5))],
        ['custom, deposit, due in 4 months',  fn () => $makeOrder('custom-far', 10250.00, 'installment', now()->addMonths(4))],
        ['custom, deposit, due in 40 minutes', fn () => $makeOrder('custom-soon', 10250.00, 'installment', now()->addMinutes(40))],
        ['custom, deposit, due in 20 minutes', fn () => $makeOrder('custom-verysoon', 10250.00, 'installment', now()->addMinutes(20))],
    ];

    $line('== Real controller code, real Stripe client (test mode). Nothing is paid. ==');

    foreach ($shapes as [$label, $make]) {
        $order = $make();

        $request = Illuminate\Http\Request::create('/payment/stripe/' . $order->id . '/checkout', 'POST');
        $app->instance('request', $request);
        $app->make('auth')->guard('web')->setUser($customer);
        $response = $kernel->handle($request);

        $loc = (string) $response->headers->get('Location');
        $payment = Payment::where('order_id', $order->id)->first();
        $sid = $payment->stripe_client_secret;

        if (!$sid || !str_starts_with($sid, 'cs_')) {
            $line(sprintf('%-38s FAILED  http=%d  %s', $label, $response->getStatusCode(), substr(strip_tags((string) $response->getContent()), 0, 120)));
            continue;
        }
        $created[] = $sid;

        $s = $stripe->checkout->sessions->retrieve($sid);
        $ttl = $s->expires_at - $s->created;
        $line(sprintf(
            '%-38s OK      http=%d  session=%s  status=%s  amount=%s %s  expires_at-created=%dh%02dm  hosted_url=%s',
            $label, $response->getStatusCode(), $tail($sid), $s->status, number_format($s->amount_total / 100, 2), strtoupper($s->currency),
            intdiv($ttl, 3600), intdiv($ttl % 3600, 60), str_starts_with($loc, 'https://checkout.stripe.com') ? 'yes' : 'NO'
        ));
    }

    // ── clock skew between this machine and Stripe (the likely reason 24h-1min was rejected)
    $probe = $stripe->checkout->sessions->create([
        'mode' => 'payment', 'payment_method_types' => ['card'],
        'line_items' => [['price_data' => ['currency' => 'php', 'product_data' => ['name' => 'skew probe'], 'unit_amount' => 5000], 'quantity' => 1]],
        'success_url' => 'http://localhost/ok?session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => 'http://localhost/no',
    ]);
    $created[] = $probe->id;
    $line(sprintf('clock skew: this machine minus Stripe at session creation = %+d seconds', time() - $probe->created));

    // ── live probes of individual limits (each failure creates nothing)
    $try = function (string $what, array $override) use ($stripe, &$created, $line) {
        $base = [
            'mode' => 'payment', 'payment_method_types' => ['card'],
            'line_items' => [['price_data' => ['currency' => 'php', 'product_data' => ['name' => 'limit probe'], 'unit_amount' => 50000], 'quantity' => 1]],
            'success_url' => 'http://localhost/ok?session_id={CHECKOUT_SESSION_ID}', 'cancel_url' => 'http://localhost/no',
        ];
        $params = array_replace_recursive($base, $override);
        try {
            $s = $stripe->checkout->sessions->create($params);
            $created[] = $s->id;
            $line(sprintf('  %-52s accepted', $what));
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $line(sprintf('  %-52s REJECTED: %s', $what, substr($e->getMessage(), 0, 110)));
        }
    };

    $line('== Limit probes (accepted or rejected by the live API) ==');
    $t = time();
    $try('expires_at = now + 24h - 1min (the old code)', ['expires_at' => $t + 86400 - 60]);
    $try('expires_at = now + 24h', ['expires_at' => $t + 86400]);
    $try('expires_at = now + 23h (new cap)', ['expires_at' => $t + 23 * 3600]);
    $try('expires_at = now + 31min (new minimum)', ['expires_at' => $t + 31 * 60]);
    $try('expires_at = now + 30min', ['expires_at' => $t + 30 * 60]);
    $try('expires_at = now + 29min', ['expires_at' => $t + 29 * 60]);
    foreach ([2000, 3000, 3100, 3200, 3500, 4000, 5000] as $c) {
        $try('unit_amount ' . $c . ' (PHP ' . number_format($c / 100, 2) . ')', ['line_items' => [['price_data' => ['unit_amount' => $c]]]]);
    }
    $try('unit_amount 99,999,999', ['line_items' => [['price_data' => ['unit_amount' => 99999999]]]]);
    $try('unit_amount 100,000,000', ['line_items' => [['price_data' => ['unit_amount' => 100000000]]]]);
    $try('product name 250 chars', ['line_items' => [['price_data' => ['product_data' => ['name' => str_repeat('N', 250)]]]]]);
    $try('product name 251 chars', ['line_items' => [['price_data' => ['product_data' => ['name' => str_repeat('N', 251)]]]]]);
    $try('metadata value 500 chars', ['metadata' => ['k' => str_repeat('v', 500)]]);
    $try('metadata value 501 chars', ['metadata' => ['k' => str_repeat('v', 501)]]);
    $try('metadata key 40 chars', ['metadata' => [str_repeat('k', 40) => 'v']]);
    $try('metadata key 41 chars', ['metadata' => [str_repeat('k', 41) => 'v']]);
    $try('relative success_url', ['success_url' => '/payment/ok']);

} finally {
    // ── expire every session we created, then verify
    $line('== Cleanup ==');
    $expired = 0;
    foreach ($created as $sid) {
        try {
            $s = $stripe->checkout->sessions->retrieve($sid);
            if ($s->status === 'open') {
                $stripe->checkout->sessions->expire($sid);
            }
            $check = $stripe->checkout->sessions->retrieve($sid);
            if ($check->status === 'expired') {
                $expired++;
            } else {
                $line('  NOT expired: ' . $tail($sid) . ' status=' . $check->status);
            }
        } catch (\Throwable $e) {
            $line('  could not expire ' . $tail($sid) . ': ' . substr($e->getMessage(), 0, 80));
        }
    }
    $line(sprintf('  sessions created: %d, verified expired: %d', count($created), $expired));

    // ── retrieving an expired session (what our reuse logic does after a lapse)
    if ($created) {
        $s = $stripe->checkout->sessions->retrieve($created[0]);
        $line('  retrieve of an expired session works: status=' . $s->status . ', url=' . ($s->url ? 'present' : 'null'));
    }

    // ── remove scratch rows
    DB::transaction(function () use ($rows) {
        DB::table('payments')->whereIn('order_id', $rows['orders'])->delete();
        DB::table('orders')->whereIn('id', $rows['orders'])->delete();
        DB::table('design_agreements')->whereIn('id', $rows['agreements'])->delete();
        DB::table('custom_quotes')->whereIn('id', $rows['quotes'])->delete();
        DB::table('custom_order_events')->whereIn('request_id', $rows['requests'])->delete();
        DB::table('custom_design_requests')->whereIn('id', $rows['requests'])->delete();
        DB::table('notifications')->whereIn('user_id', $rows['users'])->delete();
        DB::table('users')->whereIn('id', $rows['users'])->delete();
    });
    $line('  scratch rows removed');
}
