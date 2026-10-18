<?php

/**
 * RBAC matrix runner: every admin route x every actor, each probe in
 * its own fresh PHP process. Scratch only:
 *
 *   php tests/Rbac/run.php            (forces DB_DATABASE=furniture_db_test)
 *
 * It refuses to run unless the database name ends in _test, creates two
 * throwaway users (a customer and an admin), and deletes them at the end.
 * Routes with an id are only ever sent id 999999999 (nonexistent), so
 * the real admin's requests find nothing to change.
 */

$db = 'furniture_db_test';
putenv("DB_DATABASE={$db}");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $db;
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: not a _test database\n");
    exit(99);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($name, '_test')) {
    fwrite(STDERR, "REFUSING: app resolved database '{$name}'\n");
    exit(99);
}

if (!Illuminate\Support\Facades\Schema::hasTable('users')) {
    fwrite(STDERR, "scratch database has no schema; run: DB_DATABASE=furniture_db_test php artisan migrate\n");
    exit(97);
}

$tag = bin2hex(random_bytes(4));
$mk = function (string $role) use ($tag) {
    $u = new App\Models\User();
    $u->forceFill([
        'first_name' => 'Rbac', 'last_name' => ucfirst($role), 'email' => "rbac-{$role}-{$tag}@example.test",
        'password' => 'not-a-real-password', 'role' => $role, 'is_validated' => true, 'account_status' => 'active',
    ])->save();

    return $u;
};
$customer = $mk('customer');
$admin = $mk('admin');

// routes: every admin route, the public login pair is checked for being reachable instead
$routes = [];
foreach ($app['router']->getRoutes() as $route) {
    $uri = $route->uri();
    if (!str_starts_with($uri, 'admin/') && $uri !== 'admin') {
        continue;
    }
    $methods = array_values(array_diff($route->methods(), ['HEAD']));
    $concrete = preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', fn ($m) => $m[1] === 'type' ? 'id' : '999999999', $uri);
    $routes[] = [
        'name'   => $route->getName() ?? $uri,
        'method' => $methods[0],
        'uri'    => '/' . $concrete,
        'public' => in_array($route->getName(), ['admin.login', 'admin.login.post'], true),
    ];
}
usort($routes, fn ($a, $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

$actors = ['guest', 'customer_web', 'customer_on_admin_guard', 'admin'];
$probe = __DIR__ . '/probe.php';
$results = [];

foreach ($routes as $r) {
    foreach ($actors as $actor) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . implode(' ', array_map('escapeshellarg', [
            $actor, $r['method'], $r['uri'], $customer->email, $admin->email,
        ])) . ' 2>&1';

        $lines = preg_split('/\R/', trim((string) shell_exec($cmd)));
        $last = end($lines) ?: '';
        $json = json_decode($last, true);
        $results[$r['method'] . ' ' . $r['uri']][$actor] = $json ?: ['status' => 'ERR', 'location' => substr(implode(' ', $lines), 0, 120), 'db' => '?'];
    }
}

/* ── Stage 21d: the owner-only customer route GET /services/invoices/{id} ──────────────────────────────
   Not an /admin route, so it is probed separately: guest, the owner, another customer, and an admin who is
   only signed in on the admin guard, against a cancelled, a sent and a draft invoice and an id that does not exist. */
$mkNamed = function (string $label) use ($tag) {
    $u = new App\Models\User();
    $u->forceFill([
        'first_name' => 'Rbac', 'last_name' => ucfirst($label), 'email' => "rbac-{$label}-{$tag}@example.test",
        'password' => 'not-a-real-password', 'role' => 'customer', 'is_validated' => true, 'account_status' => 'active',
    ])->save();

    return $u;
};
$owner = $mkNamed('owner');
$other = $mkNamed('other');
$desk = app(App\Support\ServiceInvoiceDesk::class);
$ownerInvoices = [];
foreach (['cancelled', 'sent', 'draft'] as $state) {
    $ticket = App\Models\ServiceInquiry::create([
        'reference_number' => 'RBAC-' . strtoupper(bin2hex(random_bytes(3))), 'service_type' => 'repair', 'furniture_type' => 'Chair',
        'concern' => 'Rbac probe ticket.', 'name' => 'Rbac', 'phone' => '09171234567', 'status' => 'new', 'user_id' => $owner->id,
    ]);
    $clean = App\Support\ServiceInvoiceDesk::validate([
        'service_description' => 'Probe', 'service_cost' => '100', 'delivery_option' => 'none',
        'customer_name' => 'Rbac', 'customer_phone' => '09171234567',
    ]);
    $inv = $desk->createDraft($ticket->id, $admin, $clean);
    if ($state !== 'draft') {
        $inv = $desk->issue($inv->id, $admin);
    }
    if ($state === 'cancelled') {
        $inv = $desk->void($inv->id, $admin, 'Rbac probe reason');
    }
    $ownerInvoices[$state] = $inv->id;
}
$ownerInvoices['missing'] = 999999999;

// Expectations per customer route and invoice state. 'login' = redirect to /login, int = that status,
// 'redirect' = redirected to the invoice page (the cancelled explanation), 'skip' = not probed (see note below the table).
$pageExpect = fn ($cancelledOwner) => [
    'cancelled' => ['guest' => 'login', 'owner' => $cancelledOwner, 'other' => 404, 'admin_guard_only' => 'login'],
    'sent'      => ['guest' => 'login', 'owner' => 200, 'other' => 404, 'admin_guard_only' => 'login'],
    'draft'     => ['guest' => 'login', 'owner' => 404, 'other' => 404, 'admin_guard_only' => 'login'],
    'missing'   => ['guest' => 'login', 'owner' => 404, 'other' => 404, 'admin_guard_only' => 'login'],
];
$payExpect = [
    'cancelled' => ['guest' => 'login', 'owner' => 'redirect', 'other' => 404, 'admin_guard_only' => 'login'],
    // the owner paying a SENT invoice would call the real Stripe / PayMongo API from this process: covered by PHPUnit with a fake gateway
    'sent'      => ['guest' => 'login', 'owner' => 'skip', 'other' => 404, 'admin_guard_only' => 'login'],
    'draft'     => ['guest' => 'login', 'owner' => 404, 'other' => 404, 'admin_guard_only' => 'login'],
    'missing'   => ['guest' => 'login', 'owner' => 404, 'other' => 404, 'admin_guard_only' => 'login'],
];
$customerRoutes = [
    ['GET',  '/services/invoices/{id}',                 $pageExpect(200)],
    ['GET',  '/services/invoices/{id}/print',           $pageExpect('redirect')],
    ['GET',  '/services/invoices/{id}/payment/success',   $pageExpect('redirect')],
    ['GET',  '/services/invoices/{id}/payment/cancelled', $pageExpect('redirect')],
    ['GET',  '/services/invoices/{id}/payment/failed',    $pageExpect('redirect')],
    ['POST', '/services/invoices/{id}/pay/stripe',        $payExpect],
    ['POST', '/services/invoices/{id}/pay/gcash',         $payExpect],
];
$ownerTable = [
    '| Route | Invoice | guest | owner | another customer | admin (admin guard only) |',
    '|---|---|---|---|---|---|',
];
$ownerBad = 0;
$ownerTotal = 0;
foreach ($customerRoutes as [$method, $template, $expectations]) {
    foreach ($expectations as $state => $expect) {
        $id  = $ownerInvoices[$state];
        $uri = str_replace('{id}', (string) $id, $template);
        $cells = [];
        foreach (['guest' => ['guest', null], 'owner' => ['customer_web', $owner], 'other' => ['customer_web', $other], 'admin_guard_only' => ['admin', null]] as $who => [$actorName, $asUser]) {
            if ($expect[$who] === 'skip') {
                $cells[] = 'not probed (PHPUnit)';
                continue;
            }
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . implode(' ', array_map('escapeshellarg', [
                $actorName, $method, $uri, ($asUser ?? $customer)->email, $admin->email,
            ])) . ' 2>&1';
            $lines = preg_split('/\R/', trim((string) shell_exec($cmd)));
            $x = json_decode(end($lines) ?: '', true) ?: ['status' => 'ERR', 'location' => null];
            $ownerTotal++;
            $label = $x['status'] . ($x['location'] ? ' → ' . parse_url($x['location'], PHP_URL_PATH) : '');
            $isRedirect = in_array($x['status'], [301, 302], true);
            $ok = match (true) {
                $expect[$who] === 'login'    => $isRedirect && parse_url((string) $x['location'], PHP_URL_PATH) === '/login',
                $expect[$who] === 'redirect' => $isRedirect && parse_url((string) $x['location'], PHP_URL_PATH) === '/services/invoices/' . $id,
                default                      => $x['status'] === $expect[$who],
            };
            if (!$ok) {
                $ownerBad++;
                $label = '**' . $label . ' FAIL**';
            } else {
                $label .= ' ✓';
            }
            $cells[] = $label;
        }
        $ownerTable[] = '| `' . $method . ' ' . $template . '` | ' . $state . ' | ' . implode(' | ', $cells) . ' |';
    }
}

/* ── Stage 21f: owner-only cancellation / refund routes ───────────────────────────────────────────────────
   Ticket A has no request (form routes); ticket B has an open request created through the real service (request pages).
   The owner's POSTs send an empty body, so they are refused by validation or by the request status and change nothing. */
$ticketA = App\Models\ServiceInquiry::create([
    'reference_number' => 'RBAC-' . strtoupper(bin2hex(random_bytes(3))), 'service_type' => 'repair', 'furniture_type' => 'Chair',
    'concern' => 'Rbac refund probe A.', 'name' => 'Rbac', 'phone' => '09171234567', 'status' => 'new', 'user_id' => $owner->id,
]);
$ticketB = App\Models\ServiceInquiry::create([
    'reference_number' => 'RBAC-' . strtoupper(bin2hex(random_bytes(3))), 'service_type' => 'repair', 'furniture_type' => 'Chair',
    'concern' => 'Rbac refund probe B.', 'name' => 'Rbac', 'phone' => '09171234567', 'status' => 'new', 'user_id' => $owner->id,
]);
$refundSvc = app(App\Support\ServiceRefundRequestService::class);
$refundSvc->startWork($ticketB->id, $admin);   // work started + nothing paid = a real open request instead of an instant cancel
$reqB = $refundSvc->create($ticketB->id, [
    'reason_code' => 'other', 'contact_phone' => '09171234567', 'contact_email' => 'rbac@example.test', 'terms' => '1',
], $owner, 'form');

$keys = ['ticket' => $ticketA->id, 'ref' => $reqB->reference, 'missing_ticket' => 999999999, 'missing_ref' => 'REF-2026-9999'];
$refundRoutes = [
    // [method, template, key for the existing record, key for the missing one, owner expectation]
    ['GET',  '/services/requests/{x}/cancellation',         'ticket', 'missing_ticket', 200],
    ['POST', '/services/requests/{x}/cancellation/review',  'ticket', 'missing_ticket', 'redirect_any'],
    ['POST', '/services/requests/{x}/cancellation',         'ticket', 'missing_ticket', 'redirect_any'],
    ['GET',  '/services/refunds/{x}',                       'ref',    'missing_ref',    200],
    ['GET',  '/services/refunds/{x}/sent',                  'ref',    'missing_ref',    200],
    ['POST', '/services/refunds/{x}/reply',                 'ref',    'missing_ref',    'redirect_any'],
];
$refundTable = [
    '| Route | Record | guest | owner | another customer | admin (admin guard only) |',
    '|---|---|---|---|---|---|',
];
$refundBad = 0;
$refundTotal = 0;
foreach ($refundRoutes as [$method, $template, $existing, $missing, $ownerExpect]) {
    foreach ([[$existing, 'exists'], [$missing, 'does not exist']] as [$key, $recordLabel]) {
        $uri = str_replace('{x}', (string) $keys[$key], $template);
        $expect = $key === $existing
            ? ['guest' => 'login', 'owner' => $ownerExpect, 'other' => 404, 'admin_guard_only' => 'login']
            : ['guest' => 'login', 'owner' => 404, 'other' => 404, 'admin_guard_only' => 'login'];
        $cells = [];
        foreach (['guest' => ['guest', null], 'owner' => ['customer_web', $owner], 'other' => ['customer_web', $other], 'admin_guard_only' => ['admin', null]] as $who => [$actorName, $asUser]) {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . implode(' ', array_map('escapeshellarg', [
                $actorName, $method, $uri, ($asUser ?? $customer)->email, $admin->email,
            ])) . ' 2>&1';
            $lines = preg_split('/\R/', trim((string) shell_exec($cmd)));
            $x = json_decode(end($lines) ?: '', true) ?: ['status' => 'ERR', 'location' => null];
            $refundTotal++;
            $label = $x['status'] . ($x['location'] ? ' → ' . parse_url($x['location'], PHP_URL_PATH) : '');
            $isRedirect = in_array($x['status'], [301, 302], true);
            $path = $x['location'] ? parse_url($x['location'], PHP_URL_PATH) : null;
            $ok = match (true) {
                $expect[$who] === 'login'        => $isRedirect && $path === '/login',
                $expect[$who] === 'redirect_any' => $isRedirect && $path !== '/login',
                default                          => $x['status'] === $expect[$who],
            };
            if (!$ok) {
                $refundBad++;
                $label = '**' . $label . ' FAIL**';
            } else {
                $label .= ' ✓';
            }
            $cells[] = $label;
        }
        $refundTable[] = '| `' . $method . ' ' . $template . '` | ' . $recordLabel . ' | ' . implode(' | ', $cells) . ' |';
    }
}
$ownerBad += $refundBad;
$ownerTotal += $refundTotal;
$stateAfter = [App\Models\ServiceRefundRequest::count(), $reqB->fresh()->status];
if ($stateAfter !== [1, 'new']) {
    $ownerBad++;
    fwrite(STDERR, 'A probe changed refund request state: ' . json_encode($stateAfter) . "\n");
}

$rids = App\Models\ServiceRefundRequest::whereIn('service_inquiry_id', [$ticketA->id, $ticketB->id])->pluck('id');
Illuminate\Support\Facades\DB::table('service_refund_messages')->whereIn('service_refund_request_id', $rids)->delete();
Illuminate\Support\Facades\DB::table('service_refund_attempts')->whereIn('service_refund_request_id', $rids)->delete();
Illuminate\Support\Facades\DB::table('service_refund_requests')->whereIn('id', $rids)->delete();
Illuminate\Support\Facades\DB::table('service_inquiries')->whereIn('id', [$ticketA->id, $ticketB->id])->delete();
App\Models\Notification::where('title', 'New Cancellation Request')->where('message', 'like', '%Rbac%')->delete();

// scratch cleanup. Sent and cancelled invoices are locked at the model layer, so remove them with the query builder (scratch database only).
$ids = array_values(array_filter($ownerInvoices, fn ($v) => $v !== 999999999));
$ticketIds = Illuminate\Support\Facades\DB::table('service_invoices')->whereIn('id', $ids)->pluck('service_inquiry_id');
Illuminate\Support\Facades\DB::table('service_invoice_lines')->whereIn('invoice_id', $ids)->delete();
Illuminate\Support\Facades\DB::table('service_invoices')->whereIn('id', $ids)->delete();
Illuminate\Support\Facades\DB::table('service_inquiries')->whereIn('id', $ticketIds)->delete();
App\Models\Notification::whereIn('user_id', [$owner->id, $other->id])->delete();
App\Models\User::whereIn('id', [$owner->id, $other->id])->delete();

// remove the scratch users (and anything produced for them)
App\Models\Notification::whereIn('user_id', [$customer->id, $admin->id])->delete();
App\Models\User::whereIn('id', [$customer->id, $admin->id])->delete();

$expectLogin = fn ($x) => in_array($x['status'], [301, 302], true) && str_contains((string) $x['location'], '/admin/login');

$table = [];
$table[] = '| Route | guest | customer (web guard) | customer creds on admin guard | admin |';
$table[] = '|---|---|---|---|---|';
$bad = 0;
$total = 0;
foreach ($routes as $r) {
    $key = $r['method'] . ' ' . $r['uri'];
    $cells = [];
    foreach ($actors as $actor) {
        $x = $results[$key][$actor];
        $total++;
        $label = $x['status'] . ($x['location'] ? ' → ' . parse_url($x['location'], PHP_URL_PATH) : '');
        if ($r['public']) {
            $ok = in_array($x['status'], [200, 302, 422], true);
            $label .= ' (public page)';
        } elseif ($actor === 'admin' && $r['name'] === 'admin.logout') {
            $ok = $expectLogin($x);   // signing out legitimately lands on the login page
        } elseif ($actor === 'admin') {
            $ok = is_int($x['status']) && $x['status'] < 500 && !$expectLogin($x) && !in_array($x['status'], [401, 403, 419], true);
        } else {
            $ok = $expectLogin($x);
        }
        if (!$ok) {
            $bad++;
            $label = '**' . $label . ' FAIL**';
        } else {
            $label .= ' ✓';
        }
        $cells[] = $label;
    }
    $table[] = '| `' . $key . '` | ' . implode(' | ', $cells) . ' |';
}

$summary = sprintf('%d routes x %d actors = %d probes, plus %d owner-route probes, %d failures. Database: %s', count($routes), count($actors), $total, $ownerTotal, $bad + $ownerBad, $name);
$doc = "# RBAC matrix (Stage 11)\n\n{$summary}\n\n"
    . "Expected: guest, customer and customer-credentials-on-admin-guard are redirected to /admin/login; "
    . "admin gets anything except that redirect, 401, 403, 419 or 5xx. Routes with an id were sent id 999999999 only. "
    . "One fresh PHP process per actor per route.\n\n"
    . "Stage 21c: no routes were added. \"Send to customer\" from the edit page is the existing `POST /admin/service-invoices/{id}` (update) "
    . "with `then=send`, so that row covers it; the feature test `test_save_and_send_is_admin_only` also probes it with the send flag.\n\n"
    . "Stage 21d: the customer invoice page `GET /services/invoices/{id}` is owner-only and is probed in the last section of this file. "
    . "A cancelled invoice now answers its owner with a 200 explanation page; another customer, a draft and an unknown id are all 404; a guest is sent to /login.\n\n"
    . implode("\n", $table) . "\n\n## Owner-only customer routes: `/services/invoices/{id}` and what hangs off it\n\n"
    . "Not /admin routes. Each cell is one fresh PHP process. Expected: a guest, and an admin signed in only on the admin guard, are redirected to /login; "
    . "another customer gets 404 for every id (so a pay attempt reveals nothing); a draft or an unknown id is 404 for the owner too; "
    . "for a cancelled invoice the owner gets the 200 explanation page on the show route and is redirected to it from print, the return pages and pay (no payment can start). "
    . "Stage 21e: the owner paying a SENT invoice is deliberately not probed here, because that would call the real Stripe or PayMongo API from this process; it is covered by PHPUnit with a fake gateway.

"
    . implode("\n", $ownerTable) . "\n\n## Stage 21f: owner-only cancellation and refund routes\n\n"
    . "The request form, summary, confirm, confirmation page, request page and reply route belong to the ticket's owner. Expected: a guest, and an admin signed in only on the admin guard, "
    . "are redirected to /login; another customer gets 404 for every record (so nothing reveals whether a request exists); the owner reaches the record, and the owner's empty POSTs are refused by validation or by the request status and change nothing. "
    . "The new /admin routes (Cancellations & Refunds, the actions, opening a request on a customer's behalf, Work started) are in the table above.\n\n"
    . implode("\n", $refundTable) . "\n";

file_put_contents($root . '/docs/rbac-matrix-stage11.md', $doc);
echo $summary, "\n";
exit(($bad + $ownerBad) === 0 ? 0 : 1);
