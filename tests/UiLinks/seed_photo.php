<?php

/**
 * Gives service ticket 3 (from seed_screenshots.php) one reference photo, in the SCRATCH database only, so the admin
 * photo link can be walked in the browser (tests/UiLinks/browser-polish-part1.cjs). Writes one small PNG under
 * storage/app/service-photos/ (a file it creates itself; it never deletes anything).
 *
 *   php tests/UiLinks/seed_photo.php
 */

putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: database is '{$db}', not a _test database\n");
    exit(99);
}

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAFElEQVR4nGP8z8Dwn4EIwESMolGFxAEAXVkDBVO7zyQAAAAASUVORK5CYII=');
$path = 'service-photos/polish-test-photo.png';
Illuminate\Support\Facades\Storage::disk('local')->put($path, $png);
App\Models\ServiceInquiry::whereKey(3)->update(['reference_photos' => json_encode([$path])]);
$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$admin = App\Models\User::where('email', $login['admin_email'])->firstOrFail();
if (!App\Models\Notification::where('user_id', $admin->id)->where('title', 'Polish check: unread')->exists()) {
    App\Models\Notification::create(['user_id' => $admin->id, 'title' => 'Polish check: unread', 'message' => 'An unread admin notification, so Mark read can be measured.', 'type' => 'custom_order', 'is_read' => false]);
}
echo "ticket 3 now has 1 photo; the demo admin has an unread notification\n";
