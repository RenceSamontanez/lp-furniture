<?php
/** SCRATCH only: signs every browser session in furniture_db_test out again (so guest pages such as Sign In and Register can be viewed). */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }
$n = 0;
foreach (Illuminate\Support\Facades\DB::table('sessions')->get() as $row) {
    $s = app('session')->driver();
    $s->setId($row->id);
    $s->start();
    $s->forget(Illuminate\Support\Facades\Auth::guard('web')->getName());
    $s->save();
    $n++;
    app()->forgetInstance('session.store');
}
echo "signed out $n sessions\n";
