<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Philippine mobile number. Accepts 09XXXXXXXXX, +639XXXXXXXXX and
 * 639XXXXXXXXX (spaces and hyphens are ignored); store the value
 * through normalize(), which always returns 09XXXXXXXXX.
 *
 * Registration keeps its own regex (09 / +639 only) — this rule is a
 * superset of it, so any number registration accepts passes here too.
 */
class PhilippineMobile implements ValidationRule
{
    private const PATTERN = '/^(?:09|\+639|639)(\d{9})$/';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (self::normalize($value) === null) {
            $fail('Please enter a valid Philippine mobile number, e.g. 09171234567 or +639171234567.');
        }
    }

    /** 09XXXXXXXXX, or null when the value isn't a valid PH mobile number. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $compact = preg_replace('/[\s\-]/', '', $value);

        return preg_match(self::PATTERN, $compact, $m) ? '09' . $m[1] : null;
    }

    public static function isValid(mixed $value): bool
    {
        return self::normalize($value) !== null;
    }
}
