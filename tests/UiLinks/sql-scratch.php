<?php
/** Read-only helper for the browser walks (SCRATCH database only): php tests/UiLinks/sql-scratch.php "select ..." prints JSON rows. */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }
$sql = $argv[1] ?? '';
if (!preg_match('/^\s*select\b/i', $sql)) { fwrite(STDERR, "select only\n"); exit(2); }
echo json_encode(Illuminate\Support\Facades\DB::select($sql));
