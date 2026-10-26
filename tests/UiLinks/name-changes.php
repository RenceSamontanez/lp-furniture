<?php
/**
 * Prints the recorded name changes of one customer (SCRATCH database only): php tests/UiLinks/name-changes.php <email>
 * Used by browser-personal-cards.cjs to prove the history row was written.
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

$user = App\Models\User::where('email', $argv[1] ?? '')->first();
echo json_encode($user ? $user->nameChanges()->get(['old_first_name', 'new_first_name', 'new_last_name', 'was_verified'])->toArray() : []);
