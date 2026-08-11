<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects URLs / website addresses in free-text fields, including
 * common filter-bypass tricks: hxxp:// substitution, spelled-out
 * "dot" next to a real TLD (e.g. "example dot com"), and spacing
 * used to break up a scheme or domain (e.g. "h t t p : / /").
 *
 * TLD list is curated, not exhaustive — matches the same "known
 * limitation" tradeoff as the profanity filter (see #2).
 */
class NoLinks implements ValidationRule
{
    private const TLDS = [
        'com', 'net', 'org', 'ph', 'io', 'co', 'info', 'biz', 'xyz', 'me',
        'app', 'dev', 'shop', 'store', 'online', 'site', 'tech', 'gov', 'edu',
        'mil', 'name', 'pro', 'tv', 'cc', 'link', 'click', 'live', 'blog',
        'us', 'uk', 'ca', 'au', 'de', 'fr', 'jp', 'cn', 'ru', 'in', 'id',
        'sg', 'my', 'th', 'vn', 'hk', 'kr', 'br', 'mx', 'es', 'it', 'nl',
        'se', 'no', 'dk', 'fi', 'pl', 'nz', 'za', 'ae', 'sa',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $normalized = strtolower($value);

        // Strip all whitespace to catch spaced-out obfuscation like
        // "h t t p : / /" or "e x a m p l e . c o m" — this collapses
        // back down to "http://" / "example.com" so the same regexes
        // below catch it without a separate code path.
        $collapsed = preg_replace('/\s+/', '', $normalized);

        $tldPattern = implode('|', self::TLDS);

        $patterns = [
            '/https?:\/\//i',                                  // http:// or https://
            '/hxxps?:\/\//i',                                   // hxxp:// bypass
            '/www\./i',                                         // www.
            '/\b(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:' . $tldPattern . ')\b/i', // domain.tld
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized) || preg_match($pattern, $collapsed)) {
                $fail('Links and website addresses are not allowed in this field.');
                return;
            }
        }

        // Spelled-out "dot" immediately next to a real TLD, e.g.
        // "example dot com", "example [dot] com", "example(dot)com".
        // Scoped to require a real TLD after "dot" so ordinary words
        // like "polka dot fabric" are left alone.
        $dotSpelledPattern = '/[a-z0-9-]+\s*[\[\(]?\s*dot\s*[\]\)]?\s*(?:' . $tldPattern . ')\b/i';

        if (preg_match($dotSpelledPattern, $normalized)) {
            $fail('Links and website addresses are not allowed in this field.');
        }
    }
}
