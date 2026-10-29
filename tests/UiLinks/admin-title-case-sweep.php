<?php
/**
 * Source-level Title Case sweep for admin Blade views (no database needed).
 *
 *   php tests/UiLinks/admin-title-case-sweep.php            list classes of static-text elements (--classes) or the proposed changes
 *   php tests/UiLinks/admin-title-case-sweep.php --apply    rewrite the files and write docs/text/admin-title-case-changes.md
 *
 * Only elements whose whole inner text is static (no {{ }}, no @directives, no child tags) and whose tag/class says it is a
 * heading, button, tab, label, table header, badge, menu item or modal title are touched. Sentences (ending in . ? ! … or longer
 * than 7 words) are never touched. Display only: href, value, name, data-* attributes are never part of the text.
 */
$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Support\TitleCase;

$apply = in_array('--apply', $argv, true);
$classesMode = in_array('--classes', $argv, true);

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/resources/views/admin'));
foreach ($it as $f) { if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) { $files[] = $f->getPathname(); } }
$files[] = $root . '/resources/views/layouts/admin.blade.php';
sort($files);

// class fragments that mark a "label-like" element when the tag alone is not enough
$classHint = '/(btn|tab\b|-tab|badge|pill|chip|status|title|heading|nav-link|nav-group|label|eyebrow|stat-label|th\b|caption|legend|tag\b|kpi|lbl)/i';
$alwaysTags = ['button', 'th', 'label', 'legend', 'summary', 'h1', 'h2', 'h3', 'h4', 'h5'];
$maybeTags = ['a', 'span', 'div', 'p', 'li', 'strong', 'b', 'small', 'dt', 'td'];

$changes = [];
$classCount = [];
foreach ($files as $file) {
    $src = file_get_contents($file);
    $rel = str_replace('\\', '/', substr($file, strlen($root) + 1));
    $new = preg_replace_callback(
        '#<(button|a|h[1-5]|label|th|td|legend|summary|span|div|p|li|strong|b|small|dt)\b((?:\{\{.*?\}\}|\{!!.*?!!\}|[^>{])*+)>((?:\s*<svg\b.*?</svg>\s*)?[^<>{}@]{2,80}(?:\s*<svg\b.*?</svg>\s*)?)</\1>#us',
        function ($m) use (&$changes, &$classCount, $rel, $classHint, $alwaysTags, $classesMode) {
            [$full, $tag, $attrs, $inner] = $m;
            // text = inner without its (optional) leading/trailing icon
            $svgParts = preg_split('#(\s*<svg\b.*?</svg>\s*)#s', $inner, -1, PREG_SPLIT_DELIM_CAPTURE);
            $textIdx = null;
            foreach ($svgParts as $i => $part) { if ($part !== '' && !str_starts_with(ltrim($part), '<svg') && trim($part) !== '') { $textIdx = $textIdx === null ? $i : -1; } }
            if ($textIdx === null || $textIdx < 0) { return $full; }
            $text = $svgParts[$textIdx];
            $clean = trim(preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($clean === '') { return $full; }
            preg_match('/class="([^"]*)"/', $attrs, $cm);
            $cls = $cm[1] ?? '';
            $isLabelLike = in_array($tag, $alwaysTags, true) || ($cls !== '' && preg_match($GLOBALS['classHint'], $cls)) || ($tag === 'a' && $cls === '' && false);
            if (!$isLabelLike) { return $full; }
            if ($tag === 'td' || $tag === 'p') { return $full; }
            if ($classesMode) { $classCount["{$tag}.{$cls}"][] = $clean; }
            // sentences and long text stay as written
            if (preg_match('/[.?!…:,;]$/u', $clean) && !preg_match('/^[A-Za-z &\/\-]+:$/u', $clean)) { return $full; }
            if (count(explode(' ', $clean)) > 7 || mb_strlen($clean) > 60) { return $full; }
            if (preg_match('/^[\d\s%.,\-+]+$/u', $clean)) { return $full; }
            $to = TitleCase::apply($clean);
            // keep the original entities: only swap the words that changed
            if ($to === $clean) { return $full; }
            $rawTo = $text;
            // work on the raw text word by word so &amp; etc. survive
            $rawWords = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
            $out = [];
            foreach ($rawWords as $w) { $out[] = preg_match('/^\s+$/u', $w) || $w === '' ? $w : $w; }
            $rawTo = TitleCase::apply($text);
            if (html_entity_decode($rawTo, ENT_QUOTES | ENT_HTML5, 'UTF-8') !== $to && $rawTo === $text) { return $full; }
            $changes[] = [$rel, $tag, $cls, $text, $rawTo];
            $svgParts[$textIdx] = $rawTo;
            return '<' . $tag . $attrs . '>' . implode('', $svgParts) . '</' . $tag . '>';
        },
        $src
    );
    // arrows on buttons and links: words only (leading ← / &larr;, trailing → / &rarr; right before the closing tag)
    if ($new !== null) {
        $new = preg_replace_callback('~(?<=>)(\s*)(?:←|&larr;|&#8592;)\s*(?=[A-Za-z])~u', function ($m) use (&$changes, $rel) { $changes[] = [$rel, 'arrow', '', '← …', '(arrow removed)']; return $m[1]; }, $new);
        $new = preg_replace_callback('~([A-Za-z])\s*(?:→|&rarr;|&#8594;)(?=\s*</)~u', function ($m) use (&$changes, $rel) { $changes[] = [$rel, 'arrow', '', '… →', '(arrow removed)']; return $m[1]; }, $new);
    }
    if ($new === null) { fwrite(STDERR, "PREG ERROR " . preg_last_error_msg() . " in {$rel}
"); continue; }
    if ($apply && $new !== $src) { file_put_contents($file, $new); }
}

if ($classesMode) {
    ksort($classCount);
    foreach ($classCount as $k => $v) { echo str_pad((string) count($v), 4), $k, '   e.g. ', implode(' | ', array_slice(array_unique($v), 0, 3)), "\n"; }
    exit;
}
foreach ($changes as [$rel, $tag, $cls, $a, $b]) { echo "{$rel} <{$tag} {$cls}>: {$a}  =>  {$b}\n"; }
echo count($changes), " change(s)\n";
if ($apply) {
    @mkdir($root . '/docs/text', 0777, true);
    $md = "# Admin Title Case: static strings changed\n\nGenerated by `php tests/UiLinks/admin-title-case-sweep.php --apply` (admin views and `layouts/admin.blade.php` only; display text only, stored values and `value`/`href`/`data-*` attributes untouched). Dynamic text is handled with `@titlecase` / `TitleCase::apply` and is not listed here.\n\n| File | Element | Before | After |\n|---|---|---|---|\n";
    foreach ($changes as [$rel, $tag, $cls, $a, $b]) {
        $md .= '| ' . $rel . ' | `' . $tag . ($cls ? '.' . explode(' ', trim($cls))[0] : '') . '` | ' . str_replace('|', '\|', trim(preg_replace('/\s+/', ' ', $a))) . ' | ' . str_replace('|', '\|', trim(preg_replace('/\s+/', ' ', $b))) . " |\n";
    }
    file_put_contents($root . '/docs/text/admin-title-case-changes.md', $md);
}
