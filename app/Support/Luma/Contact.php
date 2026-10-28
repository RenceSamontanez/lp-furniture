<?php

namespace App\Support\Luma;

use App\Support\FooterData;

/**
 * The shop's phone, email, Facebook page and address, read from the same place the footer reads (FooterData: Admin >
 * Contact Settings first, config/shop.php second). A value that is blank, or still the placeholder the table was created
 * with, counts as "not set": Luma then says "through our website" instead of showing a fake number. Contact details are
 * never typed into an answer: an answer carries the {{contact}} sentence or a [[contact]] block and this class fills it.
 */
final class Contact
{
    /**
     * The phone exactly as typed in Contact Settings (blank or the starting placeholder = null), WITHOUT the footer's
     * config/shop.php fallback and its spacing. The printed Other Services invoice and the Other Services contact card
     * keep using these two, so a printed document never changes with a config fallback.
     */
    public static function settingsPhone(): ?string
    {
        $v = trim((string) \App\Models\BusinessSetting::current()->phone);

        return $v !== '' && $v !== \App\Models\BusinessSetting::DEFAULTS['phone'] ? $v : null;
    }

    public static function settingsEmail(): ?string
    {
        $v = trim((string) \App\Models\BusinessSetting::current()->email);

        return $v !== '' && $v !== \App\Models\BusinessSetting::DEFAULTS['email'] ? $v : null;
    }

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

    /** Phone, email and Facebook link of the shop; nothing typed into an answer. */
    public static function address(): ?string
    {
        return FooterData::contact()['address'];
    }

    /** The label/value lines for a [[contact]] block ("Label: value" per line), only for the fields that are set. */
    public static function rows(string $lang, bool $full): array
    {
        $tl = $lang === Language::TL;
        $c  = FooterData::contact();

        $rows = [];
        if ($c['email']) {
            $rows[] = ['Email', $c['email']];
        }
        if ($c['phone']) {
            $rows[] = [$full ? ($tl ? 'Telepono' : 'Phone') : ($tl ? 'Telepono' : 'Phone number'), $c['phone']['display']];
        }
        if ($c['facebook']) {
            $rows[] = [$full ? 'Facebook' : ($tl ? 'Facebook page' : 'Facebook account'), '[' . ($tl ? 'LP Furniture sa Facebook' : 'LP Furniture on Facebook') . '](' . $c['facebook'] . ')'];
        }
        if ($full && $c['address']) {
            $rows[] = ['Shop', $c['address']];
        }
        if ($full) {
            $rows[] = [$tl ? 'Bukas' : 'Open', Hours::text($lang)];
        }

        return $rows;
    }

    /** [[contact]] (everything) or [[contact:short]] (email, phone, Facebook) as text lines; with nothing set, the website sentence. */
    public static function block(string $lang, bool $full): string
    {
        $rows = self::rows($lang, $full);
        $set  = $rows !== [] && (self::phone() !== null || self::email() !== null || FooterData::contact()['facebook'] !== null || ($full && self::address() !== null));

        if (!$set) {
            return self::sentence($lang);
        }

        return implode("\n", array_map(fn ($r) => "{$r[0]}: {$r[1]}", $rows));
    }

    private static function real(string $field): ?string
    {
        $c = FooterData::contact();

        return match ($field) {
            'phone'        => $c['phone']['display'] ?? null,
            'email'        => $c['email'],
            'facebook_url' => $c['facebook'],
            default        => null,
        };
    }
}
