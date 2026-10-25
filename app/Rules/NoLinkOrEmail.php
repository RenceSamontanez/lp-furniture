<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * For free-text fields on customer forms: rejects web addresses and email addresses.
 *
 * Unlike NoLinks this never strips the spaces out of the text before matching, so an
 * ordinary sentence break such as "Seats six. No legs." is not mistaken for "six.no".
 * Written to be reused by any later form (Other Services, profile notes, and so on).
 */
class NoLinkOrEmail implements ValidationRule
{
    private const TLDS = 'com|net|org|ph|io|co|info|biz|xyz|me|app|dev|shop|store|online|site|tech|gov|edu|link|click|live|blog|us|uk|ca|au|de|fr|jp|cn|ru';

    public static function contains(string $text): bool
    {
        $t = mb_strtolower($text);

        return (bool) (
            preg_match('/[a-z0-9._%+\-]+\s?(@|\(at\)|\[at\])\s?[a-z0-9\-]+(\.|\s?\(dot\)|\s?\[dot\])\s?[a-z]{2,}/i', $t)  // name@site.com, name (at) site (dot) com
            || preg_match('/\b(?:https?|hxxps?|ftp):\/\//i', $t)                                                           // scheme
            || preg_match('/\bwww\s?\./i', $t)                                                                             // www.
            || preg_match('/\b[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.(?:' . self::TLDS . ')\b/i', $t)                     // site.com (a dot with no space after it)
            || preg_match('/\b[a-z0-9\-]+\s?(?:\(dot\)|\[dot\])\s?(?:' . self::TLDS . ')\b/i', $t)                        // site (dot) com
            || preg_match('/\b[a-z0-9\-]+\s+dot\s+(?:' . self::TLDS . ')\b/i', $t)                                        // site dot com
        );
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && trim($value) !== '' && self::contains($value)) {
            $fail('Links and email addresses are not allowed in this field.');
        }
    }
}
