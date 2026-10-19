<?php

/**
 * Mints a signed-in session for the demo customer in the SCRATCH database and prints the cookie as JSON, so the headless
 * browser walk (tests/Luma/browser-checks.cjs) can use the chat as a customer. The real sign-in form is protected by
 * reCAPTCHA, which this tooling must not bypass; this writes a session row into furniture_db_test only.
 *
 *   php tests/Luma/session-cookie.php            (refuses to run unless the database name ends in _test)
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

$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$user  = App\Models\User::where('email', $argv[1] ?? $login['customer_email'])->firstOrFail();

$session = app('session')->driver();
$session->setId(Illuminate\Support\Str::random(40));
$session->start();
$session->put(Illuminate\Support\Facades\Auth::guard('web')->getName(), $user->id);
$session->put('_token', Illuminate\Support\Str::random(40));
$session->save();

$name      = config('session.cookie');
$encrypter = app('encrypter');
$value     = $encrypter->encrypt(Illuminate\Cookie\CookieValuePrefix::create($name, $encrypter->getKey()) . $session->getId(), Illuminate\Cookie\Middleware\EncryptCookies::serialized($name));

echo json_encode(['name' => $name, 'value' => $value]);
