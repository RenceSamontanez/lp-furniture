<?php
/**
 * Text audit (SCRATCH database only): renders customer pages in-process as the order-tracking customer and lists, per page,
 * buttons/links that still carry an arrow and headings, buttons, labels and tabs that are not in Title Case.
 *
 *   php tests/UiLinks/seed_order_tracking.php && php tests/UiLinks/seed_text_walk.php
 *   php tests/UiLinks/audit-text.php
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Support\TitleCase;

$user = App\Models\User::where('email', 'ot-customer@example.test')->firstOrFail();
$pages = ['/', '/catalog', '/product/1', '/cart', '/orders', '/orders/1', '/orders/1/track', '/profile', '/design', '/design/gallery',
    '/notifications', '/checkout', '/payment/confirmed/1', '/guide', '/services/request', '/terms', '/reviews', '/validate-account'];
$guest = ['/login', '/register', '/forgot-password'];

function audit(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp = new DOMXPath($dom);
    foreach (['script', 'style', 'template', 'noscript'] as $t) {
        foreach (iterator_to_array($dom->getElementsByTagName($t)) as $n) { $n->parentNode->removeChild($n); }
    }
    $arrows = $sent = [];
    foreach ($xp->query('//button|//a[contains(@class,"btn") or contains(@class,"button")]') as $n) {
        $t = trim(preg_replace('/\s+/', ' ', $n->textContent));
        if (preg_match('/(→|←|»|«)/u', $t)) { $arrows[] = mb_substr($t, 0, 40); }
    }
    $q = '//h1|//h2|//h3|//h4|//button|//a[contains(@class,"btn") or contains(@class,"lp-btn")]|//label|//th|//legend|//*[@role="tab"]|//nav//a|//footer//a|//summary';
    foreach ($xp->query($q) as $n) {
        $kids = 0;
        foreach ($n->childNodes as $c) { if ($c->nodeType === XML_ELEMENT_NODE && !in_array($c->nodeName, ['svg', 'span', 'b', 'i', 'em', 'strong', 'img', 'path'], true)) { $kids++; } }
        if ($kids > 0) { continue; }
        $t = trim(preg_replace('/\s+/', ' ', $n->textContent));
        if ($t === '' || mb_strlen($t) > 60 || preg_match('/[.?!…]$/u', $t) || count(explode(' ', $t)) > 7 || preg_match('/[{}@]/', $t)) { continue; }
        $a = TitleCase::apply($t);
        if ($a !== $t) { $sent[] = $t . '  =>  ' . $a; }
    }
    return [array_values(array_unique($arrows)), array_values(array_unique($sent))];
}

$cookie = json_decode(shell_exec('php ' . escapeshellarg(__DIR__ . '/session-cookie-for.php') . ' ot-customer@example.test web'), true);
$base = 'http://127.0.0.1:8011';
$run = function (string $uri, ?App\Models\User $as) use ($cookie, $base) {
    $hdr = $as ? "Cookie: {$cookie['name']}=" . urlencode($cookie['value']) . "\r\n" : '';
    $ctx = stream_context_create(['http' => ['header' => $hdr, 'ignore_errors' => true, 'follow_location' => 0]]);
    $html = (string) @file_get_contents($base . $uri, false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), $html];
};

foreach (array_merge(array_map(fn ($p) => [$p, $user], $pages), array_map(fn ($p) => [$p, null], $guest)) as [$uri, $as]) {
    try {
        [$code, $html] = $run($uri, $as);
    } catch (Throwable $e) {
        echo "$uri  ERROR " . $e->getMessage() . "\n";
        continue;
    }
    [$arrows, $sent] = $code === 200 ? audit($html) : [[], []];
    echo "$uri  [$code]  arrows=" . count($arrows) . "  sentence-case=" . count($sent) . "\n";
    foreach ($arrows as $a) { echo "    ARROW: $a\n"; }
    foreach ($sent as $s) { echo "    CASE : $s\n"; }
}
