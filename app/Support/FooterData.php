<?php

namespace App\Support;

use App\Models\BusinessSetting;
use App\Rules\PhilippineMobile;
use Illuminate\Support\Facades\Route;

/** Everything the shared footer shows, resolved in one place: Contact Settings first, config/shop.php fallbacks second, nothing invented. */
class FooterData
{
    public static function build(): array
    {
        $bs = BusinessSetting::current();
        $fb = config('shop.fallback');

        $phoneRaw = $bs->publicPhone() ?? self::clean($fb['phone'] ?? null);
        $email    = $bs->publicEmail() ?? self::clean($fb['email'] ?? null);

        $address   = self::clean($bs->shop_address) ?? self::clean($fb['shop_address'] ?? null);
        $landmarks = self::clean($bs->shop_landmarks) ?? self::clean($fb['shop_landmarks'] ?? null);
        $lat = is_numeric($bs->map_lat) ? (float) $bs->map_lat : null;
        $lng = is_numeric($bs->map_lng) ? (float) $bs->map_lng : null;
        $hasCoords = $lat !== null && $lng !== null;

        $visit = null;
        if ($address || $hasCoords) {
            $q = $hasCoords ? self::num($lat) . ',' . self::num($lng) : $address;
            $visit = [
                'address'   => $address,
                'landmarks' => $landmarks,
                'embed'     => 'https://maps.google.com/maps?q=' . rawurlencode($q) . '&z=' . ($hasCoords ? 17 : 16) . '&output=embed',
                'open'      => 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($q),
            ];
        }

        $logoDir  = trim((string) config('shop.logo_dir'), '/');
        $payments = [];
        foreach (config('shop.payments', []) as $key => $label) {
            $logo = null;
            foreach (['svg', 'png'] as $ext) {
                if (is_file(public_path("{$logoDir}/{$key}.{$ext}"))) {
                    $logo = asset("{$logoDir}/{$key}.{$ext}");
                    break;
                }
            }
            $payments[] = ['label' => $label, 'logo' => $logo];
        }

        $policies = [];
        foreach (config('shop.policies', []) as $p) {
            if (!empty($p['route']) && Route::has($p['route'])) {
                $policies[] = ['label' => $p['label'], 'url' => route($p['route'])];
            }
        }

        return [
            'phone'    => $phoneRaw ? self::phone($phoneRaw) : null,
            'email'    => $email,
            'facebook' => $bs->publicFacebookUrl() ?? self::clean($fb['facebook_url'] ?? null),
            'tiktok'   => $bs->publicTiktokUrl() ?? self::clean($fb['tiktok_url'] ?? null),
            'visit'    => $visit,
            'payments' => $payments,
            'policies' => $policies,
        ];
    }

    /** "0991 239 4770" and tel:+639912394770 for a PH mobile; anything else is shown as typed with a digits-only tel: link. */
    private static function phone(string $raw): array
    {
        $m = PhilippineMobile::normalize($raw);
        if ($m !== null) {
            return ['display' => substr($m, 0, 4) . ' ' . substr($m, 4, 3) . ' ' . substr($m, 7), 'href' => 'tel:+63' . substr($m, 1)];
        }

        return ['display' => $raw, 'href' => 'tel:' . preg_replace('/[^\d+]/', '', $raw)];
    }

    private static function num(float $v): string
    {
        return rtrim(rtrim(number_format($v, 7, '.', ''), '0'), '.');
    }

    private static function clean(?string $v): ?string
    {
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
