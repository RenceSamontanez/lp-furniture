<?php

/**
 * RBAC matrix runner: every admin route x every actor, each probe in
 * its own fresh PHP process. Scratch only:
 *
 *   php tests/Rbac/run.php            (forces DB_DATABASE=furniture_db_test)
 *
 * It refuses to run unless the database name ends in _test, creates two
 * throwaway users (a customer and an admin), and deletes them at the end.
 * Routes with an id are only ever sent id 999999999 (nonexistent), so
 * the real admin's requests find nothing to change.
 */

$db = 'furniture_db_test';
putenv("DB_DATABASE={$db}");
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $db;
putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';

if (!str_ends_with($db, '_test')) {
    fwrite(STDERR, "REFUSING: not a _test database\n");
    exit(99);
}

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$name = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($name, '_test')) {
    fwrite(STDERR, "REFUSING: app resolved database '{$name}'\n");
    exit(99);
}

if (!Illuminate\Support\Facades\Schema::hasTable('users')) {
    fwrite(STDERR, "scratch database has no schema; run: DB_DATABASE=furniture_db_test php artisan migrate\n");
    exit(97);
}

$tag = bin2hex(random_bytes(4));
$mk = function (string $role) use ($tag) {
    $u = new App\Models\User();
    $u->forceFill([
        'first_name' => 'Rbac', 'last_name' => ucfirst($role), 'email' => "rbac-{$role}-{$tag}@example.test",
        'password' => 'not-a-real-password', 'role' => $role, 'is_validated' => true, 'account_status' => 'active',
    ])->save();

    return $u;
};
$customer = $mk('customer');
$admin = $mk('admin');

// routes: every admin route, the public login pair is checked for being reachable instead
$routes = [];
foreach ($app['router']->getRoutes() as $route) {
    $uri = $route->uri();
    if (!str_starts_with($uri, 'admin/') && $uri !== 'admin') {
        continue;
    }
    $methods = array_values(array_diff($route->methods(), ['HEAD']));
    $concrete = preg_replace_callback('/\{([a-zA-Z_]+)\??\}/', fn ($m) => $m[1] === 'type' ? 'id' : '999999999', $uri);
    $routes[] = [
        'name'   => $route->getName() ?? $uri,
        'method' => $methods[0],
        'uri'    => '/' . $concrete,
        'public' => in_array($route->getName(), ['admin.login', 'admin.login.post'], true),
    ];
}
usort($routes, fn ($a, $b) => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

$actors = ['guest', 'customer_web', 'customer_on_admin_guard', 'admin'];
$probe = __DIR__ . '/probe.php';
$results = [];

foreach ($routes as $r) {
    foreach ($actors as $actor) {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probe) . ' ' . implode(' ', array_map('escapeshellarg', [
            $actor, $r['method'], $r['uri'], $customer->email, $admin->email,
        ])) . ' 2>&1';

        $lines = preg_split('/\R/', trim((string) shell_exec($cmd)));
        $last = end($lines) ?: '';
        $json = json_decode($last, true);
        $results[$r['method'] . ' ' . $r['uri']][$actor] = $json ?: ['status' => 'ERR', 'location' => substr(implode(' ', $lines), 0, 120), 'db' => '?'];
    }
}

// remove the scratch users (and anything produced for them)
App\Models\Notification::whereIn('user_id', [$customer->id, $admin->id])->delete();
App\Models\User::whereIn('id', [$customer->id, $admin->id])->delete();

$expectLogin = fn ($x) => in_array($x['status'], [301, 302], true) && str_contains((string) $x['location'], '/admin/login');

$table = [];
$table[] = '| Route | guest | customer (web guard) | customer creds on admin guard | admin |';
$table[] = '|---|---|---|---|---|';
$bad = 0;
$total = 0;
foreach ($routes as $r) {
    $key = $r['method'] . ' ' . $r['uri'];
    $cells = [];
    foreach ($actors as $actor) {
        $x = $results[$key][$actor];
        $total++;
        $label = $x['status'] . ($x['location'] ? ' → ' . parse_url($x['location'], PHP_URL_PATH) : '');
        if ($r['public']) {
            $ok = in_array($x['status'], [200, 302, 422], true);
            $label .= ' (public page)';
        } elseif ($actor === 'admin' && $r['name'] === 'admin.logout') {
            $ok = $expectLogin($x);   // signing out legitimately lands on the login page
        } elseif ($actor === 'admin') {
            $ok = is_int($x['status']) && $x['status'] < 500 && !$expectLogin($x) && !in_array($x['status'], [401, 403, 419], true);
        } else {
            $ok = $expectLogin($x);
        }
        if (!$ok) {
            $bad++;
            $label = '**' . $label . ' FAIL**';
        } else {
            $label .= ' ✓';
        }
        $cells[] = $label;
    }
    $table[] = '| `' . $key . '` | ' . implode(' | ', $cells) . ' |';
}

$summary = sprintf('%d routes x %d actors = %d probes, %d failures. Database: %s', count($routes), count($actors), $total, $bad, $name);
$doc = "# RBAC matrix (Stage 11)\n\n{$summary}\n\n"
    . "Expected: guest, customer and customer-credentials-on-admin-guard are redirected to /admin/login; "
    . "admin gets anything except that redirect, 401, 403, 419 or 5xx. Routes with an id were sent id 999999999 only. "
    . "One fresh PHP process per actor per route.\n\n" . implode("\n", $table) . "\n";

file_put_contents($root . '/docs/rbac-matrix-stage11.md', $doc);
echo $summary, "\n";
exit($bad === 0 ? 0 : 1);
