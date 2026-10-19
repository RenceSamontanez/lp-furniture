<?php
/**
 * Adds the two Stage 21i demo tickets to the SCRATCH database (after tests/Rbac/seed_screenshots.php): one with a vague description
 * and a draft invoice, one with a photo. Prints their ids as JSON. Refuses to run unless the database name ends in _test.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\ServiceInvoiceDesk;

$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$customer = User::where('email', $login['customer_email'])->firstOrFail();
$admin = User::where('email', $login['admin_email'])->firstOrFail();

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
Illuminate\Support\Facades\Storage::disk('local')->put('service-photos/demo-leg.png', $png);

$mk = fn (array $o) => ServiceInquiry::create($o + ['service_type' => 'repair', 'furniture_type' => 'Table', 'name' => 'Maria Santos', 'phone' => '09171234567', 'email' => 'maria@example.test', 'status' => 'new', 'user_id' => $customer->id, 'terms_accepted_at' => now()]);
$vague = $mk(['reference_number' => 'SI-2026-9101', 'concern' => 'Needs work.', 'work_location' => 'home']);
$photo = $mk(['reference_number' => 'SI-2026-9102', 'concern' => 'Leg cracked.', 'work_location' => 'shop', 'reference_photos' => ['service-photos/demo-leg.png']]);
$clean = ServiceInvoiceDesk::validate(['service_description' => 'Repair', 'service_cost' => '2000', 'delivery_option' => 'none', 'customer_name' => 'Maria Santos', 'customer_phone' => '09171234567', 'payment_plan' => 'full']);
$draft = app(ServiceInvoiceDesk::class)->createDraft($vague->id, $admin, $clean);
echo json_encode(['vague' => $vague->id, 'photo' => $photo->id, 'draft' => $draft->id]);
