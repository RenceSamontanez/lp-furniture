<?php

/**
 * Prints how many chatbot_logs rows contain the given text, in the SCRATCH database only (used by browser-polish2.cjs to prove a
 * Luma message reached the server exactly once).
 *   php tests/UiLinks/count-chat-log.php "some unique text"
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

$needle = $argv[1] ?? '';
echo $needle === '' ? 0 : Illuminate\Support\Facades\DB::table('chatbot_logs')->where('message', 'like', '%' . $needle . '%')->count();
