<?php

/**
 * Scratch data for the Stage 11 browser walk. SCRATCH DATABASE ONLY.
 *
 *   php tests/Rbac/browser_data.php seed
 *   php tests/Rbac/browser_data.php cleanup
 *
 * Refuses to run unless the database name ends in _test. Everything it
 * creates is tagged by the stage11-browser e-mail domain, and cleanup
 * removes exactly those rows (and the one phpunit- prefixed image it
 * wrote). It never touches any other file or row.
 */

putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($name, '_test')) {
    fwrite(STDERR, "REFUSING: database is '{$name}'\n");
    exit(99);
}

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const DOMAIN = '@stage11-browser.example.test';
const PASSWORD = 'Stage11-Walk-Only!';
const IMAGE = 'images/ai-designs/phpunit-stage11-browser.png';

$mode = $argv[1] ?? '';

$userIds = fn () => DB::table('users')->where('email', 'like', '%' . DOMAIN)->pluck('id')->all();

if ($mode === 'cleanup') {
    $users = $userIds();
    $requests = DB::table('custom_design_requests')->whereIn('user_id', $users)->pluck('id')->all();
    $orders = DB::table('orders')->whereIn('user_id', $users)->pluck('id')->all();

    DB::transaction(function () use ($users, $requests, $orders) {
        DB::table('payment_records')->whereIn('order_id', $orders)->delete();
        DB::table('installment_schedules')->whereIn('order_id', $orders)->delete();
        DB::table('order_items')->whereIn('order_id', $orders)->delete();
        DB::table('delivery')->whereIn('order_id', $orders)->delete();
        DB::table('payments')->whereIn('order_id', $orders)->delete();
        DB::table('orders')->whereIn('id', $orders)->delete();
        DB::table('custom_order_events')->whereIn('request_id', $requests)->delete();
        DB::table('custom_order_call_logs')->whereIn('request_id', $requests)->delete();
        DB::table('design_agreements')->whereIn('request_id', $requests)->delete();
        DB::table('custom_quotes')->whereIn('request_id', $requests)->delete();
        DB::table('custom_design_requests')->whereIn('id', $requests)->update(['selected_suggestion_id' => null]);
        DB::table('ai_design_suggestions')->whereIn('design_id', $requests)->delete();
        DB::table('custom_design_requests')->whereIn('id', $requests)->delete();
        DB::table('notifications')->whereIn('user_id', $users)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
    });

    $path = $root . '/public/' . IMAGE;
    if (is_file($path)) {
        unlink($path);
    }

    echo "cleaned: " . count($users) . " users, " . count($requests) . " requests, " . count($orders) . " orders\n";
    exit(0);
}

if ($mode !== 'seed') {
    fwrite(STDERR, "usage: seed|cleanup\n");
    exit(2);
}

if (DB::table('users')->where('email', 'like', '%' . DOMAIN)->exists()) {
    echo "already seeded\n";
    exit(0);
}

$dir = $root . '/public/images/ai-designs';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($root . '/public/' . IMAGE, base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
));

$mk = function (string $role, string $first) {
    $u = new App\Models\User();
    $u->forceFill([
        'first_name' => $first, 'last_name' => 'Walk', 'email' => 'stage11-' . strtolower($first) . DOMAIN,
        'password' => Hash::make(PASSWORD), 'role' => $role, 'is_validated' => true, 'account_status' => 'active',
        'phone_number' => '09170000011', 'email_verified_at' => now(),
    ])->save();

    return $u;
};

$admin = $mk('admin', 'Admin');
$customer = $mk('customer', 'Customer');
$other = $mk('customer', 'Other');

$request = App\Models\CustomDesignRequest::create([
    'user_id' => $customer->id, 'title' => 'Dining Table - Walk', 'furniture_type' => 'Dining Table',
    'description' => 'A six-seater narra dining table with rounded corners', 'status' => 'ai_generated',
    'preferred_material' => 'Narra',
]);
App\Models\AiDesignSuggestion::create([
    'design_id' => $request->id, 'image_path' => IMAGE, 'generated_at' => now(),
]);

echo json_encode([
    'database' => $name, 'admin' => $admin->email, 'customer' => $customer->email, 'other' => $other->email,
    'request_id' => $request->id,
]), "\n";
