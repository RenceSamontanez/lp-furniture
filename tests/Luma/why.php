<?php
// Dev tool: php tests/Luma/why.php "message"  -- the three best intents for a message, with the matched keywords (no database).
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Models\ChatbotIntent; use App\Support\Luma\{DefaultIntents, IntentMatcher, Text};
$msg = $argv[1] ?? ''; $words = Text::words($msg);
echo 'words: ', implode(' ', $words), "\n";
$rows = [];
foreach (DefaultIntents::all() as $name => $def) { $h = IntentMatcher::score($words, $def['keywords']); if ($h['score'] > 0) $rows[] = [$h['score'], $name, implode(' | ', $h['matched'])]; }
usort($rows, fn($a, $b) => $b[0] <=> $a[0]);
foreach (array_slice($rows, 0, 4) as $r) printf("%5.1f %-34s %s\n", $r[0], $r[1], $r[2]);
