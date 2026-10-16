<?php

/**
 * One RBAC probe: ONE fresh PHP process = ONE actor x ONE route.
 *
 *   php tests/Rbac/probe.php <actor> <METHOD> <uri> <customer-email> <admin-email>
 *
 * Refuses to run unless the database name ends in _test. Boots the
 * app, puts the actor on the right guard (exactly what actingAs does),
 * sends one request through the HTTP kernel and prints one JSON line.
 */

putenv('APP_ENV=testing');
putenv('SESSION_DRIVER=array');
putenv('CACHE_STORE=array');
putenv('QUEUE_CONNECTION=sync');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
$_ENV['SESSION_DRIVER'] = $_SERVER['SESSION_DRIVER'] = 'array';
$_ENV['CACHE_STORE'] = $_SERVER['CACHE_STORE'] = 'array';
$_ENV['QUEUE_CONNECTION'] = $_SERVER['QUEUE_CONNECTION'] = 'sync';

[$self, $actor, $method, $uri, $customerEmail, $adminEmail] = array_pad($argv, 6, null);

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

$request = Illuminate\Http\Request::create($uri, $method);
$app->instance('request', $request);

$auth = $app->make('auth');
$customer = App\Models\User::where('email', $customerEmail)->first();
$admin = App\Models\User::where('email', $adminEmail)->first();

switch ($actor) {
    case 'guest':
        break;
    case 'customer_web':
        $auth->guard('web')->setUser($customer);
        break;
    case 'customer_on_admin_guard':
        $auth->guard('admin')->setUser($customer);
        break;
    case 'admin':
        $auth->guard('admin')->setUser($admin);
        break;
    default:
        fwrite(STDERR, "unknown actor\n");
        exit(98);
}

$response = $kernel->handle($request);

echo json_encode([
    'status'   => $response->getStatusCode(),
    'location' => $response->headers->get('Location'),
    'db'       => $db,
]), "\n";
