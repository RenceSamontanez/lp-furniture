<?php
// Dev tool: lists every one-word keyword of every intent (after synonyms), to spot generic words that would hijack other questions.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Support\Luma\{DefaultIntents, Text};
$by = [];
foreach (DefaultIntents::all() as $name => $def) foreach (explode(',', $def['keywords']) as $k) {
    if (str_contains($k, '&')) continue;
    $w = Text::words($k);
    if (count($w) === 1) $by[$w[0]][$name][] = trim($k);
}
ksort($by);
foreach ($by as $word => $names) { $os = array_filter(array_keys($names), fn($n) => str_starts_with($n, 'luma_os_')); if ($os) printf("%-14s %s\n", $word, implode(', ', array_map(fn($n) => str_replace('luma_','',$n).'['.implode('/',$names[$n]).']', array_keys($names)))); }
