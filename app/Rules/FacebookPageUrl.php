<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link to the business's Facebook page: https only, and the host must
 * be exactly facebook.com or m.me — lookalikes (facebook.com.evil.io),
 * other subdomains, ports and user@host tricks are all rejected.
 *
 * This field deliberately skips NoLinks (it IS a link); this rule is its
 * host check instead. The settings controller rewrites www.facebook.com
 * to facebook.com before validating, so pasted browser URLs still work.
 */
class FacebookPageUrl implements ValidationRule
{
    private const ALLOWED_HOSTS = ['facebook.com', 'm.me'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'The Facebook link must start with https:// and point to facebook.com or m.me.';

        // Plain URL characters only — no "@", "\", ":" after the host,
        // or whitespace, which is where parser-confusion tricks live
        if (!is_string($value) || !preg_match('#^https://[A-Za-z0-9.\-]+(/[A-Za-z0-9._~\-/%?=&+]*)?$#', $value)) {
            $fail($message);
            return;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if (!in_array($host, self::ALLOWED_HOSTS, true)) {
            $fail($message);
            return;
        }

        if (trim((string) parse_url($value, PHP_URL_PATH), '/') === '') {
            $fail('The Facebook link must point to your page, e.g. https://facebook.com/YourPage.');
        }
    }
}
