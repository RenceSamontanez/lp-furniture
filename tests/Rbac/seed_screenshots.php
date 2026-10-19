<?php

/**
 * Seeds the SCRATCH database (furniture_db_test) with Stage 21f demo data for the screenshots:
 *
 *   php tests/Rbac/seed_screenshots.php
 *
 * Refuses to run unless the database name ends in _test. Creates a demo customer and a demo admin whose passwords are
 * random and written to storage/app/screenshot-login.json (git-ignored scratch file, local use only), a ticket for each
 * state, and a cancellation request in each status. It never touches furniture_db.
 */

$db = 'furniture_db_test';
putenv("DB_DATABASE={$db}");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $db;
putenv('APP_ENV=local');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';

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

use App\Models\ServiceInquiry;
use App\Models\ServiceRefundRequest as Req;
use App\Models\User;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use App\Support\ServiceRefundRequestService;

$tag = bin2hex(random_bytes(3));
$pw  = ['customer' => bin2hex(random_bytes(8)), 'admin' => bin2hex(random_bytes(8))];
$mk  = function (string $role, string $first) use ($tag, $pw) {
    $u = new User();
    $u->forceFill([
        'first_name' => $first, 'last_name' => 'Demo', 'email' => "demo-{$role}-{$tag}@example.test",
        'password' => Illuminate\Support\Facades\Hash::make($pw[$role]), 'role' => $role, 'is_validated' => true, 'account_status' => 'active',
        'phone_number' => '09171234567', 'email_verified_at' => now(),
    ])->save();

    return $u;
};
$customer = $mk('customer', 'Maria');
$admin    = $mk('admin', 'Admin');

$desk = app(ServiceInvoiceDesk::class);
$pay  = app(ServiceInvoicePayments::class);
$svc  = app(ServiceRefundRequestService::class);
$n    = 0;

$ticket = function (string $furniture = 'Dining Table') use ($customer, &$n, $tag) {
    $n++;

    return ServiceInquiry::create([
        'reference_number' => 'SI-2026-' . (9000 + $n) . '', 'service_type' => 'repair', 'furniture_type' => $furniture,
        'concern' => 'The leg is loose and the top has scratches.', 'name' => 'Maria Santos', 'phone' => '09171234567', 'email' => 'maria@example.test',
        'status' => 'new', 'user_id' => $customer->id, 'terms_accepted_at' => now(),
    ]);
};
$invoice = function (ServiceInquiry $t, string $plan = 'half_half', bool $firstHalf = true) use ($desk, $pay, $admin) {
    $clean = ServiceInvoiceDesk::validate([
        'service_description' => 'Repair service: ' . strtolower($t->furniture_type), 'service_cost' => '4000', 'delivery_option' => 'none',
        'customer_name' => 'Maria Santos', 'customer_phone' => '09171234567', 'customer_email' => 'maria@example.test', 'payment_plan' => $plan,
    ]);
    $inv = $desk->issue($desk->createDraft($t->id, $admin, $clean)->id, $admin);
    if ($firstHalf) {
        $pay->recordCash($inv->id, $admin, $plan === 'full' ? '4000' : '2000', true);
    }

    return $inv->refresh();
};
$payload = fn (array $o = []) => $o + [
    'reason_code' => 'change_of_mind', 'reason_text' => 'My plans changed and I will fix it myself.', 'contact_phone' => '09171234567',
    'contact_email' => 'maria@example.test', 'refund_method' => 'gcash', 'gcash_number' => '09181112222', 'gcash_account_name' => 'Maria Santos', 'terms' => '1',
];

$ids = [];

// 1. half paid, waiting for "Service done" (the second-half notice)
$t1 = $ticket('Dining Table'); $i1 = $invoice($t1); $ids['half_waiting'] = ['ticket' => $t1->id, 'invoice' => $i1->id];
// 2. nothing paid, not started (form: cancel this service request)
$t2 = $ticket('Chair'); $i2 = $invoice($t2, 'half_half', false); $ids['unpaid'] = ['ticket' => $t2->id, 'invoice' => $i2->id];
// 3. on hold (new request)
$t3 = $ticket('Cabinet'); $i3 = $invoice($t3); $r3 = $svc->create($t3->id, $payload(), $customer, 'form'); $ids['new'] = ['ticket' => $t3->id, 'invoice' => $i3->id, 'ref' => $r3->reference, 'req' => $r3->id];
// 4. approved
$t4 = $ticket('Bed Frame'); $i4 = $invoice($t4); $r4 = $svc->create($t4->id, $payload(), $customer, 'form'); $svc->approve($r4->id, $admin); $ids['approved'] = ['ticket' => $t4->id, 'invoice' => $i4->id, 'ref' => $r4->reference, 'req' => $r4->id];
// 5. refunded
$t5 = $ticket('Sofa'); $i5 = $invoice($t5); $r5 = $svc->create($t5->id, $payload(), $customer, 'form'); $svc->approve($r5->id, $admin);
$svc->markRefunded($r5->id, $admin, ['amount' => '2000', 'method' => 'gcash', 'transaction_number' => '6028871884223', 'sent_on' => now('Asia/Manila')->toDateString(), 'note' => null]);
$ids['refunded'] = ['ticket' => $t5->id, 'invoice' => $i5->id, 'ref' => $r5->reference, 'req' => $r5->id];
// 6. declined (work started)
$t6 = $ticket('Wardrobe'); $i6 = $invoice($t6); $svc->startWork($t6->id, $admin); $r6 = $svc->create($t6->id, $payload(), $customer, 'form');
$svc->decline($r6->id, $admin, 'Work has already started on this service, so the cost of the work cannot be refunded.'); $ids['declined'] = ['ticket' => $t6->id, 'invoice' => $i6->id, 'ref' => $r6->reference, 'req' => $r6->id];
// 7. refund problem (wrong number)
$t7 = $ticket('Table'); $i7 = $invoice($t7); $r7 = $svc->create($t7->id, $payload(), $customer, 'form'); $svc->approve($r7->id, $admin);
$svc->markRefundFailed($r7->id, $admin, 'wrong_gcash_number', 'The number belongs to someone else.'); $ids['problem'] = ['ticket' => $t7->id, 'invoice' => $i7->id, 'ref' => $r7->reference, 'req' => $r7->id];
// 8. work started, half paid (the form with the work-started notice) and overdue approved refund
$t8 = $ticket('Drawer'); $i8 = $invoice($t8); $svc->startWork($t8->id, $admin); $ids['started'] = ['ticket' => $t8->id, 'invoice' => $i8->id];
$t9 = $ticket('Shelf'); $i9 = $invoice($t9); $r9 = $svc->create($t9->id, $payload(), $customer, 'form'); $svc->approve($r9->id, $admin);
Req::whereKey($r9->id)->update(['refund_due_at' => now()->subDays(2)]); $ids['overdue'] = ['ticket' => $t9->id, 'invoice' => $i9->id, 'ref' => $r9->reference, 'req' => $r9->id];
// 10. ticket cancelled by the admin (no payment): blocked invoice
$t10 = $ticket('Bench'); $i10 = $invoice($t10, 'full', false); app(App\Support\ServiceRefundRequestService::class)->adminClose($t10->id, $admin, 'Customer could not be reached.'); $ids['cancelled'] = ['ticket' => $t10->id, 'invoice' => $i10->id];

// Stage 21h: the Work panel states, an instant cancellation and demo notifications for the details pages
$t11 = $ticket('Console');                                                       $ids['work_no_invoice'] = ['ticket' => $t11->id];
$t12 = $ticket('Mirror Frame'); $i12 = $invoice($t12, 'half_half', false);       $ids['work_waiting'] = ['ticket' => $t12->id, 'invoice' => $i12->id];
$t13 = $ticket('Bookshelf');    $i13 = $invoice($t13, 'half_half', true);        $ids['work_ready'] = ['ticket' => $t13->id, 'invoice' => $i13->id];
$t14 = $ticket('Nightstand');   $i14 = $invoice($t14, 'half_half', false);
$r14 = $svc->create($t14->id, $payload(['refund_method' => null, 'gcash_number' => null, 'gcash_account_name' => null]), $customer, 'form'); $ids['instant_closed'] = ['ticket' => $t14->id, 'invoice' => $i14->id, 'ref' => $r14->reference, 'req' => $r14->id];
$t15 = $ticket('Stool'); $i15 = $invoice($t15, 'full', false);                   $ids['form_unpaid_second'] = ['ticket' => $t15->id, 'invoice' => $i15->id];

$notif = function (string $template, string $url, array $values) use ($customer) {
    $t = App\Support\NotificationTemplates::render($template, $values + ['contact' => '']);

    return App\Models\Notification::create(['user_id' => $customer->id, 'title' => $t['title'], 'message' => $t['message'], 'type' => 'general', 'url' => $url, 'is_read' => false]);
};
$ids['notifications'] = [
    'cancelled' => $notif('service_request_cancelled', route('services.invoice', $i10->id), ['reference' => $t10->reference_number])->id,
    'refund'    => $notif('refund_refunded', route('services.refund.show', $r5->reference), ['request' => $r5->reference, 'amount' => 2000, 'method' => 'GCash', 'transaction' => '6028871884223'])->id,
    'approved'  => $notif('refund_approved', route('services.refund.show', $r4->reference), ['request' => $r4->reference, 'refund_line' => 'You get back ₱2,000.00 by GCash.'])->id,
    'declined'  => $notif('refund_declined', route('services.refund.show', $r6->reference), ['request' => $r6->reference, 'explanation' => 'Work has already started on this service.'])->id,
    'received'  => $notif('inquiry_received', route('services.request'), ['reference' => $t1->reference_number, 'phone' => '09171234567', 'window' => null])->id,
];
$out = ['customer_email' => $customer->email, 'customer_password' => $pw['customer'], 'admin_email' => $admin->email, 'admin_password' => $pw['admin'], 'ids' => $ids];
file_put_contents($root . '/storage/app/screenshot-login.json', json_encode($out, JSON_PRETTY_PRINT));
echo "seeded; login details are in storage/app/screenshot-login.json (scratch database {$name})\n";
