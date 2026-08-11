<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Supplementary to the Blasp profanity filter, not a replacement
 * for it. Blasp's default word list targets slurs/curse words —
 * it doesn't flag sexual/adult-content terms that are simply
 * off-brand for a furniture storefront (e.g. "sex" passes Blasp
 * cleanly). This is a small, focused blocklist for that specific
 * gap, not an attempt at general content-safety moderation.
 *
 * Deliberately excludes "nude", "naked", and "stripper" — all
 * three have legitimate furniture/woodworking usage ("nude
 * leather sofa", "naked wood finish" meaning unstained, "paint
 * stripper" for refinishing) and would be false positives here.
 */
class NoInappropriateTerms implements ValidationRule
{
    private const BLOCKED_TERMS = [
        'sex', 'pornographic', 'xxx', 'erotic', 'fetish', 'hentai',
        'escort', 'hookup', 'onlyfans', 'camgirl', 'bdsm', 'nsfw',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $normalized = strtolower($value);

        foreach (self::BLOCKED_TERMS as $term) {
            if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $normalized)) {
                $fail('This text contains a term that isn\'t appropriate here.');
                return;
            }
        }
    }
}
