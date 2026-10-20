<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link to the business's TikTok profile: https only, host exactly tiktok.com, www.tiktok.com, vm.tiktok.com or
 * vt.tiktok.com (the short share links). Same parser-confusion guards as FacebookPageUrl: plain URL characters only.
 */
class TikTokUrl implements ValidationRule
{
    private const ALLOWED_HOSTS = ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com', 'vt.tiktok.com'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $message = 'The TikTok link must start with https:// and point to tiktok.com, e.g. https://www.tiktok.com/@yourshop.';

        if (!is_string($value) || !preg_match('#^https://[A-Za-z0-9.\-]+(/[A-Za-z0-9._~\-/%?=&+@]*)?$#', $value)) {
            $fail($message);
            return;
        }

        $host = strtolower((string) parse_url($value, PHP_URL_HOST));

        if (!in_array($host, self::ALLOWED_HOSTS, true) || trim((string) parse_url($value, PHP_URL_PATH), '/') === '') {
            $fail($message);
        }
    }
}
