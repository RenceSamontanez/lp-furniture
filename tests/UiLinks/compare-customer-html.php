<?php
/**
 * Customer pages must be byte-for-byte unchanged by the admin typography work (SCRATCH database only).
 * Fetches the same pages from the baseline worktree server (commit 13c54f6, default 127.0.0.1:8012) and from this tree
 * (127.0.0.1:8011), removes what changes on every request (CSRF tokens, asset version stamps, timestamps) and compares.
 *
 *   php tests/UiLinks/compare-customer-html.php [beforeBase] [afterBase]
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!str_ends_with((string) config('database.connections.' . config('database.default') . '.database'), '_test')) { fwrite(STDERR, "REFUSING\n"); exit(99); }

$before = $argv[1] ?? 'http://127.0.0.1:8012';
$after = $argv[2] ?? 'http://127.0.0.1:8011';
$cookie = json_decode(shell_exec('php ' . escapeshellarg(__DIR__ . '/session-cookie-for.php') . ' ot-customer@example.test web'), true);
$pages = [['/', false], ['/catalog', false], ['/product/1', false], ['/login', false], ['/cart', true], ['/checkout', true], ['/orders', true], ['/orders/1', true], ['/profile', true], ['/notifications', true]];

$norm = function (string $html): string {
    $html = preg_replace('/<meta name="csrf-token" content="[^"]*"\s*\/?>/', '', $html);
    $html = preg_replace('/name="_token" value="[^"]*"/', 'name="_token" value=""', $html);
    $html = preg_replace('/([?&])v=[A-Za-z0-9._-]+/', '$1v=', $html);
    $html = preg_replace('/csrfToken\s*[:=]\s*"[^"]*"/', 'csrfToken=""', $html);
    $html = preg_replace('/(127\.0\.0\.1):80(11|12)/', '$1:PORT', $html);
    $html = preg_replace('/\b\d{1,2}:\d{2}:\d{2}\b/', 'HH:MM:SS', $html);
    $html = preg_replace('/\d+ (seconds?|minutes?) ago/', 'N ago', $html);
    $html = preg_replace('/\s+/', ' ', $html);
    return $html;
};
$get = function (string $base, string $uri, bool $auth) use ($cookie) {
    $hdr = $auth ? "Cookie: {$cookie['name']}=" . urlencode($cookie['value']) . "\r\n" : '';
    $ctx = stream_context_create(['http' => ['header' => $hdr, 'ignore_errors' => true, 'follow_location' => 0]]);
    $body = (string) @file_get_contents($base . $uri, false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), $body];
};
$bad = 0;
foreach ($pages as [$uri, $auth]) {
    [$c1, $h1] = $get($before, $uri, $auth);
    [$c2, $h2] = $get($after, $uri, $auth);
    $same = $c1 === $c2 && $norm($h1) === $norm($h2);
    echo str_pad($uri, 14), " before=$c1 after=$c2 bytes=" . strlen($h1) . '/' . strlen($h2) . '  ' . ($same ? 'IDENTICAL' : 'DIFFERENT') . "\n";
    if (!$same) {
        $bad++;
        $a = explode('>', $norm($h1)); $b = explode('>', $norm($h2));
        foreach (array_diff($a, $b) as $i => $line) { echo "   - " . substr($line, 0, 140) . "\n"; if (--$i < -999) break; }
        foreach (array_slice(array_diff($b, $a), 0, 5) as $line) { echo "   + " . substr($line, 0, 140) . "\n"; }
    }
}
echo $bad === 0 ? "ALL CUSTOMER PAGES IDENTICAL\n" : "$bad page(s) differ\n";
exit($bad === 0 ? 0 : 1);
