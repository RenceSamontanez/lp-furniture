<?php

namespace App\Support;

use App\Models\User;

/**
 * The one name every custom order wears: "Custom Furniture — Rence".
 *
 * Used for the order item, the agreement title of new records, the admin
 * Custom Orders list and detail, the customer's order page, the quote
 * summary and the notifications. Stored data (existing agreement snapshots,
 * orders, notifications) is never rewritten; they keep what they were
 * created with.
 */
final class CustomOrderName
{
    public const BASE = 'Custom Furniture';
    public const SEPARATOR = ' — ';
    public const MAX_FIRST_NAME = 40;

    public static function for(?User $user): string
    {
        return self::fromFirstName($user?->first_name);
    }

    /** "RENCE" -> "Custom Furniture — Rence"; nothing usable -> "Custom Furniture". */
    public static function fromFirstName(?string $firstName): string
    {
        $first = self::cleanFirstName($firstName);

        return $first === '' ? self::BASE : self::BASE . self::SEPARATOR . $first;
    }

    /** The first name alone, in proper case, or '' when there is nothing usable. */
    public static function cleanFirstName(?string $firstName): string
    {
        $name = (string) $firstName;

        // Keep letters (any script), marks, spaces, hyphens, apostrophes and dots; drop the rest
        // (digits, symbols, emoji, control characters, markup).
        $name = (string) preg_replace('/[^\p{L}\p{M}\s\'’.-]+/u', '', $name);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        // Must contain at least one letter, not just punctuation.
        if ($name === '' || !preg_match('/\p{L}/u', $name)) {
            return '';
        }

        $name = NameFormat::proper($name);
        // O'neil -> O'Neil, D'angelo -> D'Angelo (a one-letter prefix, then an apostrophe)
        $name = (string) preg_replace_callback('/(?<![\p{L}\p{M}])(\p{L})(\'|’)(\p{L})/u',
            fn ($m) => $m[1] . $m[2] . mb_strtoupper($m[3]), $name);

        if (mb_strlen($name) > self::MAX_FIRST_NAME) {
            $name = mb_substr($name, 0, self::MAX_FIRST_NAME);
            // do not end on half a word's separator
            $name = rtrim($name, " -'’.");
        }

        return $name;
    }
}
