<?php

namespace App\Support;

use App\Models\Barangay;
use App\Models\CityMunicipality;
use App\Models\Province;
use App\Models\User;
use App\Rules\NoInappropriateTerms;
use App\Rules\NoLinkOrEmail;
use App\Support\Places\PlaceLookup;
use Blaspsoft\Blasp\Rules\Profanity;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The structured delivery address: province, city/municipality and barangay are chosen by id from our own place
 * tables (the server checks that the barangay is inside the city and the city inside the province), plus the street
 * line, a 4-digit postal code and an optional landmark. One of these lives on each customer; every order keeps its own
 * snapshot of the one it used.
 */
final class DeliveryAddress
{
    public const PLACE_ERROR = 'Choose a place from the list.';

    /** The words a customer may type in a name or address line: no markup, no links, no unsuitable terms. */
    public static function textRules(int $max, bool $required, string $pattern): array
    {
        return array_merge(
            [$required ? 'required' : 'nullable', 'string', 'max:' . $max, 'regex:' . $pattern],
            [new NoLinkOrEmail(), new NoInappropriateTerms(), Profanity::make()]
        );
    }

    public const NAME_PATTERN   = "/^[\\p{L}][\\p{L} '’.\\-]*$/u";
    public const STREET_PATTERN = "/^[\\p{L}\\p{N} ,.'’#\\/\\-]+$/u";

    public static function nameRules(): array
    {
        return self::textRules(60, true, self::NAME_PATTERN);
    }

    /** Pull the address fields out of a request array, clean the text ones. */
    public static function extract(array $input, string $prefix = ''): array
    {
        $get = fn (string $k) => $input[$prefix . $k] ?? null;
        $id  = fn ($v) => is_scalar($v) && preg_match('/^\d{1,10}$/', (string) $v) ? (int) $v : null;

        return [
            'province_id' => $id($get('province_id')),
            'city_id'     => $id($get('city_id')),
            'barangay_id' => $id($get('barangay_id')),
            'street'      => TextSanitizer::line(is_string($get('street')) ? $get('street') : ''),
            'postal_code' => preg_replace('/\s+/', '', (string) (is_scalar($get('postal_code')) ? $get('postal_code') : '')),
            'landmark'    => TextSanitizer::line(is_string($get('landmark')) ? $get('landmark') : ''),
        ];
    }

    public static function isBlank(array $a): bool
    {
        foreach ($a as $v) {
            if ($v !== null && $v !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a full address. Error keys carry $prefix ("addr_street"), so a form with two blocks can show them.
     *
     * @throws ValidationException
     */
    public static function validate(array $input, string $prefix = ''): array
    {
        $a = self::extract($input, $prefix);
        $data = [];
        foreach ($a as $k => $v) {
            $data[$prefix . $k] = $v === '' ? null : $v;
        }

        $v = Validator::make($data, [
            $prefix . 'province_id' => ['required', 'integer', 'exists:provinces,id'],
            $prefix . 'city_id'     => ['required', 'integer', 'exists:cities_municipalities,id'],
            $prefix . 'barangay_id' => ['required', 'integer', 'exists:barangays,id'],
            $prefix . 'street'      => array_merge(['min:3'], self::textRules(120, true, self::STREET_PATTERN)),
            $prefix . 'postal_code' => ['required', 'digits:4'],
            $prefix . 'landmark'    => self::textRules(120, false, self::STREET_PATTERN),
        ], [
            $prefix . 'province_id.required' => 'Choose a province.',
            $prefix . 'province_id.exists'   => self::PLACE_ERROR,
            $prefix . 'province_id.integer'  => self::PLACE_ERROR,
            $prefix . 'city_id.required'     => 'Choose a city or municipality.',
            $prefix . 'city_id.exists'       => self::PLACE_ERROR,
            $prefix . 'city_id.integer'      => self::PLACE_ERROR,
            $prefix . 'barangay_id.required' => 'Choose a barangay.',
            $prefix . 'barangay_id.exists'   => self::PLACE_ERROR,
            $prefix . 'barangay_id.integer'  => self::PLACE_ERROR,
            $prefix . 'street.required'      => 'Enter your house number and street.',
            $prefix . 'street.min'           => 'Enter your house number and street.',
            $prefix . 'street.regex'         => 'Use letters, numbers and , . # - / only.',
            $prefix . 'postal_code.required' => 'Enter your 4-digit postal code.',
            $prefix . 'postal_code.digits'   => 'Postal code has 4 digits.',
            $prefix . 'landmark.regex'       => 'Use letters, numbers and , . # - / only.',
        ]);

        $v->after(function ($v) use ($a, $prefix) {
            if ($v->errors()->hasAny([$prefix . 'province_id', $prefix . 'city_id', $prefix . 'barangay_id'])) {
                return;
            }
            // never trust the browser: the three places must belong together
            if (!PlaceLookup::chainIsValid($a['province_id'], $a['city_id'], $a['barangay_id'])) {
                $v->errors()->add($prefix . 'barangay_id', self::PLACE_ERROR);
            }
        });

        $v->validate();

        return $a;
    }

    /** The customer's saved address as ids and text, or null when it is not complete. */
    public static function fromUser(User $user): ?array
    {
        if (!$user->address_province_id || !$user->address_city_id || !$user->address_barangay_id
            || !$user->address_street || !preg_match('/^\d{4}$/', (string) $user->address_postal_code)) {
            return null;
        }
        $a = [
            'province_id' => (int) $user->address_province_id,
            'city_id'     => (int) $user->address_city_id,
            'barangay_id' => (int) $user->address_barangay_id,
            'street'      => $user->address_street,
            'postal_code' => $user->address_postal_code,
            'landmark'    => $user->address_landmark ?: '',
        ];

        // the places must still exist together (a removed place nulls the id, so this is a belt-and-braces check)
        return PlaceLookup::chainIsValid($a['province_id'], $a['city_id'], $a['barangay_id']) ? $a : null;
    }

    public static function hasSaved(User $user): bool
    {
        return self::fromUser($user) !== null;
    }

    /** Save $a as the customer's one delivery address. */
    public static function saveFor(User $user, array $a): void
    {
        $user->forceFill([
            'address_province_id' => $a['province_id'],
            'address_city_id'     => $a['city_id'],
            'address_barangay_id' => $a['barangay_id'],
            'address_street'      => $a['street'],
            'address_postal_code' => $a['postal_code'],
            'address_landmark'    => $a['landmark'] !== '' ? $a['landmark'] : null,
        ])->save();
    }

    public static function clearFor(User $user): void
    {
        $user->forceFill([
            'address_province_id' => null, 'address_city_id' => null, 'address_barangay_id' => null,
            'address_street' => null, 'address_postal_code' => null, 'address_landmark' => null,
        ])->save();
    }

    /** Names for the three ids: province, city, barangay (empty strings if an id is gone). */
    public static function names(array $a): array
    {
        return [
            'province' => (string) Province::whereKey($a['province_id'] ?? 0)->value('name'),
            'city'     => (string) CityMunicipality::whereKey($a['city_id'] ?? 0)->value('name'),
            'barangay' => (string) Barangay::whereKey($a['barangay_id'] ?? 0)->value('name'),
        ];
    }

    /** "Blk 5 Lot 12, Brgy. Marauoy, Lipa City, Batangas 4217" */
    public static function line(array $a, ?array $names = null): string
    {
        $n = $names ?? self::names($a);
        $parts = array_filter([
            $a['street'] ?? '',
            $n['barangay'] !== '' ? 'Brgy. ' . preg_replace('/^Brgy\.?\s+/i', '', $n['barangay']) : '',
            $n['city'],
            $n['province'],
        ]);

        return trim(implode(', ', $parts) . (!empty($a['postal_code']) ? ' ' . $a['postal_code'] : ''));
    }

    /** What an order keeps: the recipient, the phone and the address exactly as used, with the place names written out. */
    public static function snapshot(array $a, string $recipient, string $phoneE164): array
    {
        $n = self::names($a);

        return [
            'recipient'   => $recipient,
            'phone'       => $phoneE164,
            'province_id' => $a['province_id'], 'city_id' => $a['city_id'], 'barangay_id' => $a['barangay_id'],
            'province'    => $n['province'], 'city' => $n['city'], 'barangay' => $n['barangay'],
            'street'      => $a['street'], 'postal_code' => $a['postal_code'],
            'landmark'    => $a['landmark'] !== '' ? $a['landmark'] : null,
            'full'        => self::line($a, $n),
        ];
    }
}
