<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Philippine mobile or landline number, written either way:
 *   mobile   — 09XX XXX XXXX / +63 9XX XXX XXXX
 *   landline — (02) 8XXX XXXX (Metro Manila) / (043) XXX XXXX (provincial)
 *              or the same with +63 in place of the leading 0
 * Spaces, dashes, dots and parentheses are allowed as separators and
 * ignored when checking the digits.
 */
class PhilippinePhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'Enter a valid Philippine mobile (e.g. 0917-123-4567) or landline number (e.g. (043) 123-4567).';

        if (!is_string($value) || !preg_match('/^\+?[\d\s\-().]+$/', $value)) {
            $fail($message);
            return;
        }

        $digits = preg_replace('/[\s\-().]/', '', $value);

        // 0 or +63, then either a 10-digit mobile (9 + 9 digits) or a
        // 9-digit landline (area code 2–8 + subscriber number)
        if (!preg_match('/^(?:0|\+63)(?:9\d{9}|[2-8]\d{8})$/', $digits)) {
            $fail($message);
        }
    }
}
