<?php

/**
 * Renders ONE customer page to an HTML file through the app's own HTTP kernel, with the demo customer on the web guard
 * (exactly what tests/Rbac/probe.php does). Used by tests/UiLinks/browser-checks.cjs because the real sign-in form is
 * protected by reCAPTCHA, which this tooling must not bypass.
 *
 *   php tests/UiLinks/render-customer.php /services/request out.html
 *
 * SCRATCH DATABASE ONLY: refuses to run unless the database name ends in _test.
 */

putenv('DB_DATABASE=furniture_db_test');
putenv('APP_ENV=testing');
putenv('SESSION_DRIVER=array');
putenv('CACHE_STORE=array');
putenv('QUEUE_CONNECTION=sync');
foreach (['DB_DATABASE' => 'furniture_db_test', 'APP_ENV' => 'testing', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $k => $v) {
    $_ENV[$k] = $_SERVER[$k] = $v;
}

[$self, $uri, $out] = array_pad($argv, 3, null);
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();

$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: database is '{$db}', not a _test database\n");
    exit(99);
}

$login = json_decode(file_get_contents($root . '/storage/app/screenshot-login.json'), true);
$user = App\Models\User::where('email', $login['customer_email'])->firstOrFail();

$request = Illuminate\Http\Request::create('http://127.0.0.1:8011' . $uri, 'GET');
$app->instance('request', $request);
$app->make('auth')->guard('web')->setUser($user);

$response = $kernel->handle($request);
$status = $response->getStatusCode();
file_put_contents($out, $response->getContent());
echo $status;
