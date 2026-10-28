<?php

namespace App\Support\Luma;

/**
 * Turns one Luma reply (plain text) into safe HTML for the chat bubble. Everything is escaped first: the only tags that
 * can come out are the ones built here (p, br, strong, ul, ol, li, dl, dt, dd, a, span). No raw HTML from an answer, the
 * database, an admin or an AI model ever reaches the page.
 *
 * Supported: paragraphs (blank line), line breaks, **bold**, bullet lists ("- " / "* " / "• "), numbered lists ("1. " / "1) "),
 * "Label: value" lines (two or more in a row, or one line with a known contact label) as a label/value block,
 * a "> " line as a small muted note, [text](link) links, and auto-links for emails (mailto:), phone numbers (tel:) and http(s) addresses (new tab,
 * rel="noopener noreferrer nofollow"). A link with any other scheme (javascript:, data:, ...) is shown as plain text.
 */
final class Formatter
{
    /** Labels that make a lone "Label: value" line a label/value block. */
    private const KNOWN_LABELS = ['email', 'phone', 'phone number', 'telepono', 'facebook', 'facebook account', 'shop', 'open', 'bukas', 'address', 'hours', 'reference', 'status'];

    public static function toHtml(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim(preg_replace('/[^\P{C}\n\t]/u', '', $text) ?? '');
        if ($text === '') {
            return '';
        }

        $html = '';
        foreach (preg_split('/\n{2,}/', $text) as $block) {
            $html .= self::block($block);
        }

        return $html;
    }

    private static function block(string $block): string
    {
        $out    = '';
        $para   = [];
        $items  = [];
        $kind   = null;   // ul | ol | dl
        $flushP = function () use (&$para, &$out) {
            if ($para) {
                $out .= '<p>' . implode('<br>', array_map(fn ($l) => self::inline($l), $para)) . '</p>';
                $para = [];
            }
        };
        $flushL = function () use (&$items, &$kind, &$out) {
            if (!$items) {
                return;
            }
            if ($kind === 'dl') {
                $out .= '<dl class="lf-kv">';
                foreach ($items as [$label, $value]) {
                    $out .= '<dt>' . self::esc($label) . '</dt><dd>' . self::inline($value) . '</dd>';
                }
                $out .= '</dl>';
            } else {
                $out .= '<' . $kind . ' class="lf-list">' . implode('', array_map(fn ($i) => '<li>' . self::inline($i) . '</li>', $items)) . '</' . $kind . '>';
            }
            $items = [];
            $kind  = null;
        };

        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), fn ($l) => trim($l) !== ''));

        foreach ($lines as $i => $line) {
            $line = trim($line);

            if (preg_match('/^>\s+(.+)$/u', $line, $m)) {
                $flushL();
                $flushP();
                $out .= '<p class="lf-note">' . self::inline($m[1]) . '</p>';
            } elseif (preg_match('/^(?:[-*•])\s+(.+)$/u', $line, $m)) {
                $flushP();
                if ($kind !== 'ul') {
                    $flushL();
                    $kind = 'ul';
                }
                $items[] = $m[1];
            } elseif (preg_match('/^\d{1,2}[.)]\s+(.+)$/u', $line, $m)) {
                $flushP();
                if ($kind !== 'ol') {
                    $flushL();
                    $kind = 'ol';
                }
                $items[] = $m[1];
            } elseif (($pair = self::labelValue($line)) && self::countsAsKv($lines, $i, $pair[0])) {
                $flushP();
                if ($kind !== 'dl') {
                    $flushL();
                    $kind = 'dl';
                }
                $items[] = $pair;
            } else {
                $flushL();
                $para[] = $line;
            }
        }
        $flushL();
        $flushP();

        return $out;
    }

    /** @return array{0: string, 1: string}|null */
    private static function labelValue(string $line): ?array
    {
        if (preg_match('/^(\p{Lu}[\p{L}]*(?: \p{L}+){0,2}): (\S.*)$/u', $line, $m) && mb_strlen($m[1]) <= 24) {
            return [$m[1], $m[2]];
        }

        return null;
    }

    /** Two label lines in a row are a block; a lone line only when its label is a known one. */
    private static function countsAsKv(array $lines, int $i, string $label): bool
    {
        if (in_array(mb_strtolower($label), self::KNOWN_LABELS, true)) {
            return true;
        }
        $near = fn ($j) => isset($lines[$j]) && self::labelValue(trim($lines[$j])) !== null;

        return $near($i - 1) || $near($i + 1);
    }

    private const INLINE = '~
        (?<link>\[(?<lt>[^\]\n]{1,120})\]\((?<lu>[^)\s]{1,500})\))
      | (?<bold>\*\*(?<bt>[^*\n]{1,300}?)\*\*)
      | (?<url>https?://[^\s<>"\']{3,500})
      | (?<mail>[A-Za-z0-9._%+\-]+@[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)*\.[A-Za-z]{2,})
      | (?<tel>(?<![\w.+])(?:\+?63|0)[\s\-]?9\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)|(?<![\w.+])\+\d[\d\s\-]{8,16}\d(?!\d)|(?<![\w.+])\(?0\d{1,2}\)?[\s\-]\d{3,4}[\s\-]\d{4}(?!\d))
      | (?<price>(?:₱|PHP\s?)\s?\d[\d,]*(?:\.\d+)?)
    ~xu';

    public static function inline(string $s, bool $allowBold = true): string
    {
        $out = '';
        $pos = 0;
        if (!preg_match_all(self::INLINE, $s, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return self::esc($s);
        }

        foreach ($all as $m) {
            [$whole, $start] = $m[0];
            if ($start < $pos) {
                continue;
            }
            $out .= self::esc(substr($s, $pos, $start - $pos));
            $pos  = $start + strlen($whole);

            if (($m['link'][1] ?? -1) >= 0 && $m['link'][0] !== '') {
                $out .= self::link($m['lu'][0], self::esc($m['lt'][0]));
            } elseif (($m['bold'][1] ?? -1) >= 0 && $m['bold'][0] !== '') {
                $out .= $allowBold ? '<strong>' . self::inline($m['bt'][0], false) . '</strong>' : self::esc($whole);
            } elseif (($m['url'][1] ?? -1) >= 0 && $m['url'][0] !== '') {
                $url   = rtrim($m['url'][0], '.,;:!?)');
                $pos  -= strlen($m['url'][0]) - strlen($url);
                $out  .= self::link($url, self::esc($url));
            } elseif (($m['mail'][1] ?? -1) >= 0 && $m['mail'][0] !== '') {
                $out .= self::link('mailto:' . $m['mail'][0], self::esc($m['mail'][0]));
            } elseif (($m['tel'][1] ?? -1) >= 0 && $m['tel'][0] !== '') {
                $digits = preg_replace('/\D+/', '', $m['tel'][0]);
                $plus   = str_starts_with($m['tel'][0], '+') ? '+' : '';
                $out   .= strlen($digits) >= 7 && strlen($digits) <= 15
                    ? self::link('tel:' . $plus . $digits, self::esc($m['tel'][0]), 'lf-num')
                    : self::esc($m['tel'][0]);
            } elseif (($m['price'][1] ?? -1) >= 0 && $m['price'][0] !== '') {
                $out .= '<span class="lf-num">' . self::esc($m['price'][0]) . '</span>';
            }
        }

        return $out . self::esc(substr($s, $pos));
    }

    /** $labelHtml is already escaped. Only http(s), mailto, tel and a same-site path become a link. */
    private static function link(string $url, string $labelHtml, string $class = ''): string
    {
        $url = trim($url);
        if (preg_match('/[\x00-\x20\x7F"\'<>\\\\]/', $url)) {
            return $labelHtml;
        }
        $cls = $class !== '' ? ' class="' . $class . '"' : '';

        if (preg_match('~^https?://[^/\s]+~i', $url)) {
            return '<a href="' . self::esc($url) . '" target="_blank" rel="noopener noreferrer nofollow"' . $cls . '>' . $labelHtml . '</a>';
        }
        if (preg_match('/^(mailto|tel):[^\s]+$/i', $url)) {
            return '<a href="' . self::esc($url) . '"' . $cls . '>' . $labelHtml . '</a>';
        }
        if (preg_match('~^/(?!/)[^\s]*$~', $url)) {
            return '<a href="' . self::esc($url) . '"' . $cls . '>' . $labelHtml . '</a>';
        }

        return $labelHtml;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
