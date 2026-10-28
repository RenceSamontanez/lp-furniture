<?php

namespace App\Support;

/**
 * Title Case for display text (headings, buttons, labels, names). Plain text in, plain text out: escape afterwards as usual.
 * Only an all-lowercase word is capitalised, so the result is idempotent and brands (GCash), acronyms (ID, PDF),
 * words with digits or symbols, units (cm), order numbers (LP-2026-00012), emails and URLs are never touched.
 * Small words (config/titlecase.php) stay lowercase unless they are the first or the last word.
 * Display only: never write the result back to the database.
 */
final class TitleCase
{
    public static function apply(?string $text): string
    {
        $text = (string) $text;
        if (trim($text) === '') {
            return $text;
        }

        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $wordIdx = [];
        foreach ($parts as $i => $p) {
            if ($p !== '' && !preg_match('/^\s+$/u', $p) && preg_match('/\pL/u', $p)) {
                $wordIdx[] = $i;
            }
        }
        $first = $wordIdx[0] ?? null;
        $last = $wordIdx ? $wordIdx[count($wordIdx) - 1] : null;

        $afterColon = false;
        foreach ($wordIdx as $i) {
            $isEdge = $i === $first || $i === $last || $afterColon;   // the word after "Order LP-1:" starts a new phrase
            $afterColon = preg_match('/:$/u', $parts[$i]) === 1;
            $parts[$i] = self::token($parts[$i], $isEdge);
        }

        return implode('', $parts);
    }

    private static function token(string $token, bool $edge): string
    {
        // split leading and trailing punctuation off, work on the core
        if (!preg_match('/^([^\pL\pN]*)(.*?)([^\pL\pN]*)$/us', $token, $m)) {
            return $token;
        }
        [, $pre, $core, $post] = $m;
        if ($core === '' || (str_ends_with($pre, '&') && str_starts_with($post, ';'))) {   // &times; and friends
            return $token;
        }
        // emails, URLs, paths, handles and anything with digits or symbols are left alone
        if (preg_match('/[0-9@\/\\:_#%&=+*]/u', $core)) {
            return $token;
        }

        $keepAs = (array) config('titlecase.keep_as', []);
        $low = mb_strtolower($core);
        if (isset($keepAs[$low])) {
            return $pre . $keepAs[$low] . $post;
        }
        if (in_array($low, (array) config('titlecase.keep', []), true)) {
            return $token;
        }

        // hyphen parts: "door-to-door", "pick-up"
        $bits = preg_split('/(-)/u', $core, -1, PREG_SPLIT_DELIM_CAPTURE);
        $n = count($bits);
        foreach ($bits as $k => $bit) {
            if ($bit === '-' || $bit === '') {
                continue;
            }
            $bits[$k] = self::word($bit, $edge && ($k === 0 || $k === $n - 1));
        }

        return $pre . implode('', $bits) . $post;
    }

    private static function word(string $w, bool $edge): string
    {
        $isUpper = $w === mb_strtoupper($w) && mb_strlen($w) > 1;      // acronym
        $isLower = $w === mb_strtolower($w);
        $small = (array) config('titlecase.small_words', []);
        $lc = mb_strtolower($w);

        if (!$edge && in_array($lc, $small, true) && !$isUpper) {
            return $lc;                                                // "To" in the middle -> "to"
        }
        if (!$isLower) {
            return $w;                                                 // already capitalised, acronym or inner capitals
        }
        if (mb_strlen($w) === 1 && !$edge && $lc === 'a') {
            return $w;
        }

        return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
    }
}
