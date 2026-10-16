<?php

namespace App\Support\Luma;

use App\Models\BusinessSetting;

/**
 * The shop's phone, email and Facebook page from Admin > Contact Settings. A value that is blank,
 * or still the placeholder the table was created with, counts as "not set": Luma then says
 * "through our website" instead of showing a fake number.
 */
final class Contact
{
    public static function phone(): ?string
    {
        return self::real('phone');
    }

    public static function email(): ?string
    {
        return self::real('email');
    }

    public static function facebook(): ?string
    {
        return self::real('facebook_url');
    }

    /** @return array{phone: ?string, email: ?string} */
    public static function card(): array
    {
        return ['phone' => self::phone(), 'email' => self::email()];
    }

    /** The phone and email as one sentence in the given language, or the website fallback. */
    public static function sentence(string $lang): string
    {
        $phone = self::phone();
        $email = self::email();
        $tl    = $lang === 'tl';

        if ($phone && $email) {
            return $tl
                ? "Maaari po kayong tumawag sa {$phone} o mag-email sa {$email}."
                : "You can call us at {$phone} or email us at {$email}.";
        }
        if ($phone) {
            return $tl ? "Maaari po kayong tumawag sa {$phone}." : "You can call us at {$phone}.";
        }
        if ($email) {
            return $tl ? "Maaari po kayong mag-email sa {$email}." : "You can email us at {$email}.";
        }

        return $tl ? 'Maaari po ninyo kaming kontakin sa pamamagitan ng aming website.' : 'You can reach us through our website.';
    }

    private static function real(string $field): ?string
    {
        $settings = BusinessSetting::current();
        $value    = trim((string) $settings->{$field});

        if ($value === '' || $value === (BusinessSetting::DEFAULTS[$field] ?? null)) {
            return null;
        }

        return $value;
    }
}
