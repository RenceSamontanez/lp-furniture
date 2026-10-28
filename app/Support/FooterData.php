<?php

namespace App\Support;

use App\Models\BusinessSetting;
use App\Rules\PhilippineMobile;
use Illuminate\Support\Facades\Route;

/** Everything the shared footer shows, resolved in one place: Contact Settings first, config/shop.php fallbacks second, nothing invented. */
class FooterData
{
    /** Just the shop's phone, email and Facebook page (Contact Settings first, config/shop.php second). Luma reads these too. */
    public static function contact(): array
    {
        $bs = BusinessSetting::current();
        $fb = config('shop.fallback');

        $phoneRaw = $bs->publicPhone() ?? self::clean($fb['phone'] ?? null);

        return [
            'phone'    => $phoneRaw ? self::phone($phoneRaw) : null,
            'email'    => $bs->publicEmail() ?? self::clean($fb['email'] ?? null),
            'facebook' => $bs->publicFacebookUrl() ?? self::clean($fb['facebook_url'] ?? null),
            'address'  => self::clean($bs->shop_address) ?? self::clean($fb['shop_address'] ?? null),
        ];
    }

    public static function build(): array
    {
        $bs = BusinessSetting::current();
        $fb = config('shop.fallback');

        $contact   = self::contact();
        $address   = $contact['address'];
        $landmarks = self::clean($bs->shop_landmarks) ?? self::clean($fb['shop_landmarks'] ?? null);
        // Contact Settings win when both are set; otherwise the config pin (config/shop.php) is used.
        if (is_numeric($bs->map_lat) && is_numeric($bs->map_lng)) {
            $lat = (float) $bs->map_lat;
            $lng = (float) $bs->map_lng;
        } else {
            $lat = is_numeric($fb['map_lat'] ?? null) ? (float) $fb['map_lat'] : null;
            $lng = is_numeric($fb['map_lng'] ?? null) ? (float) $fb['map_lng'] : null;
        }
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
            $payments[] = ['key' => (string) $key, 'label' => $label, 'logo' => self::logoUrl($logoDir, (string) $key)];
        }

        $policies = [];
        foreach (config('shop.policies', []) as $p) {
            if (!empty($p['route']) && Route::has($p['route'])) {
                $policies[] = ['label' => $p['label'], 'url' => route($p['route'])];
            }
        }

        return [
            'phone'    => $contact['phone'],
            'email'    => $contact['email'],
            'facebook' => $contact['facebook'],
            'tiktok'   => $bs->publicTiktokUrl() ?? self::clean($fb['tiktok_url'] ?? null),
            'visit'    => $visit,
            'payments' => $payments,
            'policies' => $policies,
        ];
    }

    /** First existing logo for a method (svg, png, jpg, jpeg, webp in that order; extension case ignored), versioned by file time. */
    private static function logoUrl(string $dir, string $key): ?string
    {
        $found = [];
        foreach (glob(public_path($dir) . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            $info = pathinfo($file);
            if (is_file($file) && strcasecmp($info['filename'], $key) === 0) {
                $found[strtolower($info['extension'] ?? '')] = $info['basename'];
            }
        }
        foreach (config('shop.logo_extensions', ['svg', 'png', 'jpg', 'jpeg', 'webp']) as $ext) {
            if (isset($found[$ext])) {
                return asset($dir . '/' . $found[$ext]) . '?v=' . (@filemtime(public_path($dir . '/' . $found[$ext])) ?: 1);
            }
        }

        return null;
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
