<?php

namespace App\Support;

/**
 * The wood a custom request is built from: three fixed woods or a special request.
 *
 * Pricing note: nothing in the app prices a custom order from its wood. The admin
 * sets the price by hand on the quote for every request. The three fixed woods and
 * the special request therefore all follow the same manual quote; a special request
 * is flagged so the team knows the material needs a decision of its own.
 */
final class WoodChoices
{
    public const SPECIAL = 'Special request';
    public const FIXED = ['Gmelina', 'Mahogany', 'Acacia'];
    public const LIST = ['Gmelina', 'Mahogany', 'Acacia', self::SPECIAL];

    public const SUPPLY_CUSTOMER = 'customer';
    public const SUPPLY_SHOP = 'shop';

    public const SUPPLY = [
        self::SUPPLY_CUSTOMER => 'I will provide my own wood (labor only)',
        self::SUPPLY_SHOP     => 'Use a different wood supplied by the shop',
    ];

    public const DETAILS_MAX = 500;

    public static function isValid(?string $wood): bool
    {
        return $wood !== null && in_array($wood, self::LIST, true);
    }

    public static function isSpecial(?string $wood): bool
    {
        return $wood === self::SPECIAL;
    }

    /** "Mahogany", or "Special request: teak from my old house (customer-supplied material, labor only)". */
    public static function label(?string $wood, ?string $details = null, ?string $supply = null): string
    {
        $wood = trim((string) $wood);

        if ($wood === '') {
            return 'Not specified';
        }

        if (!self::isSpecial($wood)) {
            return $wood;
        }

        $out = self::SPECIAL;
        $details = trim((string) $details);
        if ($details !== '') {
            $out .= ': ' . $details;
        }
        if ($supply === self::SUPPLY_CUSTOMER) {
            $out .= ' (customer-supplied material, labor only)';
        } elseif ($supply === self::SUPPLY_SHOP) {
            $out .= ' (wood supplied by the shop)';
        }

        return $out;
    }

    /** A short text for the quote's material field (100 characters at most). */
    public static function quoteMaterial(?string $wood, ?string $details = null, ?string $supply = null): ?string
    {
        $wood = trim((string) $wood);

        if ($wood === '') {
            return null;
        }
        if (!self::isSpecial($wood)) {
            return $wood;
        }

        $prefix = $supply === self::SUPPLY_CUSTOMER ? 'Customer-supplied: ' : 'Special wood: ';

        return mb_substr($prefix . trim((string) $details), 0, 100);
    }
}
