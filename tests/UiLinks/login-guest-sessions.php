<?php
/**
 * SCRATCH only: signs every existing guest browser session in furniture_db_test in as one account, so the walk can use the
 * browser's own cookies (the real sign-in form is protected by reCAPTCHA and is not bypassed). Usage:
 *   php tests/UiLinks/login-guest-sessions.php ot-customer@example.test [web|admin]
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

$user = App\Models\User::where('email', $argv[1] ?? '')->firstOrFail();
$guard = $argv[2] ?? 'web';
$key = Illuminate\Support\Facades\Auth::guard($guard)->getName();
$n = 0;
foreach (Illuminate\Support\Facades\DB::table('sessions')->get() as $row) {
    $session = app('session')->driver();
    $session->setId($row->id);
    $session->start();
    $session->put($key, $user->id);
    $session->save();
    $n++;
    app()->forgetInstance('session.store');
}
echo "signed in $n sessions as {$user->email} ({$guard})\n";
