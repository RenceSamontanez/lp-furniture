<?php

namespace App\Support;

/**
 * The five styles on the Customize page, with the photo shown on each card.
 *
 * Real photos are not in yet. Until a file public/images/customize/styles/<slug>.jpg exists, the card
 * shows the marked placeholder <slug>-placeholder.svg. Drop a photo with the right name in the folder and it
 * takes over, no code change.
 */
final class StyleChoices
{
    public const LIST = ['Modern', 'Rustic', 'Classic', 'Minimalist', 'Contemporary'];

    public const BLURB = [
        'Modern'       => 'Clean lines, simple shapes',
        'Rustic'       => 'Natural grain, a worn look',
        'Classic'      => 'Carved details, rich tone',
        'Minimalist'   => 'Very plain, light and open',
        'Contemporary' => 'Mixed shapes, soft curves',
    ];

    public const DIR = 'images/customize/styles/';

    public static function isValid(?string $style): bool
    {
        return $style !== null && in_array($style, self::LIST, true);
    }

    public static function slug(string $style): string
    {
        return mb_strtolower($style);
    }

    public static function hasRealPhoto(string $style): bool
    {
        return is_file(public_path(self::DIR . self::slug($style) . '.jpg'));
    }

    public static function photoUrl(string $style): string
    {
        $file = self::slug($style) . (self::hasRealPhoto($style) ? '.jpg' : '-placeholder.svg');

        return asset(self::DIR . $file);
    }
}
