<?php
// Dev tool: php tests/Luma/route_check.php  -- checks every knowledge-map phrasing against the matcher with NO database.
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\ChatbotIntent;
use App\Support\Luma\DefaultIntents;
use App\Support\Luma\IntentMatcher;
use App\Support\Luma\OtherServicesKnowledge as K;

$intents = collect(); $id = 1;
foreach (DefaultIntents::all() as $name => $def) {
    $i = new ChatbotIntent(['category' => $name, 'keywords' => $def['keywords'], 'is_active' => true]);
    $i->id = $id++;
    $intents->push($i);
}
$fail = []; $total = 0;
foreach (K::nodes() as $nid => $node) {
    foreach (['en','tl','mix'] as $k) foreach ($node['say'][$k] as $p) {
        $total++;
        $hit = IntentMatcher::best($p, $intents);
        $got = $hit['intent']->category ?? '(none)';
        if ($got !== $nid) $fail[] = sprintf('%-4s %-60s want %-32s got %s', $k, '"'.$p.'"', str_replace('luma_os_','',$nid), str_replace('luma_os_','',$got));
    }
}
echo count($fail), " of $total missed\n", implode("\n", $fail), "\n";
