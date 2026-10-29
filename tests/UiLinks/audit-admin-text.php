<?php
/**
 * Admin text audit (SCRATCH database only): fetches admin pages with a minted admin session and lists, per page, buttons and
 * button-styled links that still carry an arrow, and headings, buttons, labels, tabs, table headers, badges and menu items that are
 * not in Title Case. Needs the dev server on 127.0.0.1:8011 (tests/Rbac/serve_test.ps1) and the seeds the admin walk uses.
 *
 *   php tests/UiLinks/audit-admin-text.php            (add --quiet to print only pages with findings)
 */
putenv('DB_DATABASE=furniture_db_test');
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = 'furniture_db_test';
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = (string) config('database.connections.' . config('database.default') . '.database');
if (!str_ends_with($db, '_test')) { fwrite(STDERR, "REFUSING: {$db}\n"); exit(99); }

use App\Support\TitleCase;

$quiet = in_array('--quiet', $argv, true);
$email = 'ot-admin@example.test';
$pages = require __DIR__ . '/admin-pages.php';

function audit(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xp = new DOMXPath($dom);
    foreach (['script', 'style', 'template', 'noscript'] as $t) {
        foreach (iterator_to_array($dom->getElementsByTagName($t)) as $n) { $n->parentNode->removeChild($n); }
    }
    $arrows = $sent = [];
    foreach ($xp->query('//button|//a[contains(@class,"btn") or contains(@class,"button") or contains(@class,"admin-filter-tab") or contains(@class,"nav-link")]') as $n) {
        $t = trim(preg_replace('/\s+/', ' ', $n->textContent));
        if (preg_match('/(→|←|»|«|›|‹|➜|➔)/u', $t)) { $arrows[] = mb_substr($t, 0, 40); }
    }
    $cls = ['admin-pill', 'badge', 'co-chip', 'idr-pill', 'tag', 'admin-stat-label', 'admin-panel-title', 'co-block-label', 'admin-filter-tab', 'admin-nav-link',
        'admin-nav-group-label', 'admin-topbar-title', 'lp-btn', 'admin-btn', 'co-count-label', 'co-lastcall-label', 'co-modal-label'];
    $q = '//h1|//h2|//h3|//h4|//button|//label|//th|//legend|//summary|//*[@role="tab"]';
    foreach ($cls as $c) { $q .= '|//*[contains(concat(" ",normalize-space(@class)," ")," ' . $c . ' ")]'; }
    foreach ($xp->query($q) as $n) {
        $kids = 0;
        foreach ($n->childNodes as $c) { if ($c->nodeType === XML_ELEMENT_NODE && !in_array($c->nodeName, ['svg', 'span', 'b', 'i', 'em', 'strong', 'img', 'path', 'small'], true)) { $kids++; } }
        if ($kids > 0) { continue; }
        $t = trim(preg_replace('/\s+/', ' ', $n->textContent));
        if ($t === '' || mb_strlen($t) > 60 || preg_match('/[.?!…]$/u', $t) || count(explode(' ', $t)) > 7 || preg_match('/[{}@]/', $t)) { continue; }
        $a = TitleCase::apply($t);
        if ($a !== $t) { $sent[] = $t . '  =>  ' . $a; }
    }
    return [array_values(array_unique($arrows)), array_values(array_unique($sent))];
}

$cookie = json_decode(shell_exec('php ' . escapeshellarg(__DIR__ . '/session-cookie-for.php') . ' ' . $email . ' admin'), true);
$base = 'http://127.0.0.1:8011';
$total = ['arrows' => 0, 'case' => 0];
foreach ($pages as $uri) {
    $ctx = stream_context_create(['http' => ['header' => "Cookie: {$cookie['name']}=" . urlencode($cookie['value']) . "\r\n", 'ignore_errors' => true, 'follow_location' => 0]]);
    $html = (string) @file_get_contents($base . $uri, false, $ctx);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    $code = (int) ($m[1] ?? 0);
    [$arrows, $sent] = $code === 200 ? audit($html) : [[], []];
    $total['arrows'] += count($arrows);
    $total['case'] += count($sent);
    if ($quiet && !$arrows && !$sent) { continue; }
    echo "$uri  [$code]  arrows=" . count($arrows) . "  sentence-case=" . count($sent) . "\n";
    foreach ($arrows as $a) { echo "    ARROW: $a\n"; }
    foreach ($sent as $s) { echo "    CASE : $s\n"; }
}
echo "TOTAL arrows={$total['arrows']} sentence-case={$total['case']}\n";
