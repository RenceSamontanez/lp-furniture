<?php
/**
 * Customize page walk: a SCRATCH customer with no designs at all (copy of the demo customer). Prints the email.
 * Refuses to run unless the database name ends in _test.   php tests/UiLinks/seed_customize_empty.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

$demo = App\Models\User::where('email', json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true)['customer_email'])->firstOrFail();
$email = 'no-designs-customer@example.test';
if (!App\Models\User::where('email', $email)->exists()) {
    $u = $demo->replicate();
    $u->email = $email;
    $u->save();
}
echo $email . "\n";
