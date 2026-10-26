<?php

namespace App\Support;

use App\Models\User;
use App\Rules\PhilippineMobile;

/**
 * Phone numbers for the account and for orders. No runtime service: a small table of country calling codes and the
 * length of the number after the country code. Philippine numbers must be a mobile number (10 digits starting with 9);
 * every other country is checked by its own length range. Stored in E.164 (+639171234567).
 *
 * users.phone_number keeps a mirror for the older code that reads it: 09XXXXXXXXX for the Philippines, the E.164
 * number for everything else.
 */
final class PhoneNumber
{
    public const DEFAULT_COUNTRY = 'PH';

    /** code => [name, calling code, min digits, max digits] (digits after the country code, no leading 0) */
    private const COUNTRIES = [
        'PH' => ['Philippines', '63', 10, 10],
        'SG' => ['Singapore', '65', 8, 8],
        'US' => ['United States', '1', 10, 10],
        'CA' => ['Canada', '1', 10, 10],
        'JP' => ['Japan', '81', 9, 10],
        'AU' => ['Australia', '61', 9, 9],
        'GB' => ['United Kingdom', '44', 10, 10],
        'NZ' => ['New Zealand', '64', 8, 10],
        'HK' => ['Hong Kong', '852', 8, 8],
        'TW' => ['Taiwan', '886', 9, 9],
        'KR' => ['South Korea', '82', 9, 10],
        'CN' => ['China', '86', 11, 11],
        'MY' => ['Malaysia', '60', 9, 10],
        'TH' => ['Thailand', '66', 9, 9],
        'VN' => ['Vietnam', '84', 9, 10],
        'ID' => ['Indonesia', '62', 9, 12],
        'IN' => ['India', '91', 10, 10],
        'AE' => ['United Arab Emirates', '971', 9, 9],
        'SA' => ['Saudi Arabia', '966', 9, 9],
        'QA' => ['Qatar', '974', 8, 8],
        'KW' => ['Kuwait', '965', 8, 8],
        'BH' => ['Bahrain', '973', 8, 8],
        'OM' => ['Oman', '968', 8, 8],
        'IT' => ['Italy', '39', 9, 10],
        'DE' => ['Germany', '49', 10, 11],
        'FR' => ['France', '33', 9, 9],
        'ES' => ['Spain', '34', 9, 9],
        'NL' => ['Netherlands', '31', 9, 9],
        'CH' => ['Switzerland', '41', 9, 9],
        'SE' => ['Sweden', '46', 7, 9],
        'NO' => ['Norway', '47', 8, 8],
        'IE' => ['Ireland', '353', 9, 9],
    ];

    /** For the country picker: code, name, calling code, in a stable order (Philippines first). */
    public static function countries(): array
    {
        $out = [];
        foreach (self::COUNTRIES as $code => [$name, $dial]) {
            $out[] = ['code' => $code, 'name' => $name, 'dial' => '+' . $dial];
        }

        return $out;
    }

    public static function isCountry(?string $code): bool
    {
        return is_string($code) && isset(self::COUNTRIES[$code]);
    }

    /** Digit-count rules for the browser script: code => [dial, min, max]. */
    public static function rulesForScript(): array
    {
        $out = [];
        foreach (self::COUNTRIES as $code => [, $dial, $min, $max]) {
            $out[$code] = ['dial' => $dial, 'min' => $min, 'max' => $max];
        }

        return $out;
    }

    /**
     * @return array{ok:true,e164:string,country:string,legacy:string}|array{ok:false,message:string}
     */
    public static function parse(?string $input, ?string $country): array
    {
        $country = strtoupper((string) $country);
        if (!self::isCountry($country)) {
            return ['ok' => false, 'message' => 'Choose the country of your phone number.'];
        }
        [, $dial, $min, $max] = self::COUNTRIES[$country];

        $raw = trim((string) $input);
        if ($raw === '') {
            return ['ok' => false, 'message' => 'Enter your phone number. We call you about your orders.'];
        }
        // only digits and the usual separators; a letter or symbol is a typo, not a number
        if (!preg_match('/^\+?[\d\s\-().]+$/', $raw)) {
            return ['ok' => false, 'message' => 'Use digits only, for example 0917 123 4567.'];
        }

        $digits = preg_replace('/\D/', '', $raw);
        $international = str_starts_with($raw, '+');

        if ($international) {
            if (!str_starts_with($digits, $dial)) {
                return ['ok' => false, 'message' => 'This number does not start with +' . $dial . '. Pick the matching country.'];
            }
            $national = substr($digits, strlen($dial));
            // +44 (0)7... : drop a trunk zero typed after the country code
            if (strlen($national) > $max && str_starts_with($national, '0')) {
                $national = substr($national, 1);
            }
        } elseif ($country === 'PH') {
            if (str_starts_with($digits, '63') && strlen($digits) === 12) {
                $national = substr($digits, 2);
            } elseif (str_starts_with($digits, '0')) {
                $national = substr($digits, 1);
            } else {
                $national = $digits;
            }
        } else {
            $national = str_starts_with($digits, '0') ? substr($digits, 1) : $digits;
        }

        if ($country === 'PH') {
            if (!preg_match('/^9\d{9}$/', $national)) {
                return ['ok' => false, 'message' => 'Enter a Philippine mobile number like 0917 123 4567.'];
            }
        } elseif (strlen($national) < $min || strlen($national) > $max) {
            $range = $min === $max ? "$min digits" : "$min to $max digits";

            return ['ok' => false, 'message' => "A number in this country has $range after the country code."];
        } elseif (!preg_match('/^[1-9]\d+$/', $national)) {
            return ['ok' => false, 'message' => 'This does not look like a valid phone number.'];
        }

        return [
            'ok'      => true,
            'e164'    => '+' . $dial . $national,
            'country' => $country,
            'legacy'  => $country === 'PH' ? '0' . $national : '+' . $dial . $national,
        ];
    }

    /** The E.164 number of an account, from the new column or from a valid older Philippine number; null when none. */
    public static function forUser(User $user): ?string
    {
        if ($user->phone_e164) {
            return $user->phone_e164;
        }
        $legacy = PhilippineMobile::normalize($user->phone_number);

        return $legacy ? '+63' . substr($legacy, 1) : null;
    }

    /** What a person reads: 0917 123 4567 for a Philippine mobile, +65 8123 4567 otherwise. */
    public static function display(?string $e164): string
    {
        if (!$e164) {
            return '';
        }
        if (preg_match('/^\+63(9\d{2})(\d{3})(\d{4})$/', $e164, $m)) {
            return "0{$m[1]} {$m[2]} {$m[3]}";
        }

        return $e164;
    }

    /** [country code, national digits as typed for the form] for an E.164 number. */
    public static function split(?string $e164): array
    {
        if ($e164) {
            // longest calling code first, so +1 does not swallow +1xxx style codes
            $byDial = [];
            foreach (self::COUNTRIES as $code => [, $dial]) {
                $byDial[$dial][] = $code;
            }
            uksort($byDial, fn ($a, $b) => strlen($b) <=> strlen($a));
            foreach ($byDial as $dial => $codes) {
                if (str_starts_with($e164, '+' . $dial)) {
                    $national = substr($e164, strlen($dial) + 1);

                    return [$codes[0], $codes[0] === 'PH' ? '0' . $national : $national];
                }
            }
        }

        return [self::DEFAULT_COUNTRY, ''];
    }
}
