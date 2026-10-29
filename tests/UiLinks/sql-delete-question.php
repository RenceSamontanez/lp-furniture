<?php
/** Walk helper (SCRATCH database only): deletes one luma_unanswered_questions row, as a second admin would. php tests/UiLinks/sql-delete-question.php ID */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }
Illuminate\Support\Facades\DB::table('luma_unanswered_questions')->where('id', (int) ($argv[1] ?? 0))->delete();
