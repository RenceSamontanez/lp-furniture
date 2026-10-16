<?php

namespace App\Support;

/**
 * The one list of furniture types a custom request can be, and the wording
 * built from a chosen type. The type is what the customer picked: it is never
 * worked out from the prompt text, here or anywhere else.
 */
final class FurnitureTypes
{
    public const OTHER = 'Other';
    public const OTHER_MAX = 60;

    public const LIST = [
        'Chair', 'Table', 'Desk', 'Bed', 'Sofa', 'Cabinet', 'Shelf / Bookcase',
        'Door', 'Wardrobe', 'TV stand', 'Bench', 'Stool', self::OTHER,
    ];

    public const NOT_SPECIFIED = 'Not specified';

    /** The phrase used inside a sentence: "a chair", "a TV stand", "a shelf or bookcase". */
    private const PHRASES = [
        'Shelf / Bookcase' => 'shelf or bookcase',
    ];

    public static function isValid(?string $type): bool
    {
        return $type !== null && in_array($type, self::LIST, true);
    }

    public static function needsText(?string $type): bool
    {
        return $type === self::OTHER;
    }

    /** What to show: "Chair", "Other: pergola", or "Not specified" for old requests. */
    public static function label(?string $type, ?string $other = null): string
    {
        $type = trim((string) $type);

        if ($type === '') {
            return self::NOT_SPECIFIED;
        }

        $other = trim((string) $other);

        return ($type === self::OTHER && $other !== '') ? self::OTHER . ': ' . $other : $type;
    }

    /** Just the type words, for a sentence: "chair", "pergola". Null when there is no usable type. */
    public static function noun(?string $type, ?string $other = null): ?string
    {
        $type = trim((string) $type);

        if ($type === '') {
            return null;
        }

        if ($type === self::OTHER) {
            $other = trim((string) $other);

            return $other !== '' ? $other : null;
        }

        return self::PHRASES[$type] ?? (preg_match('/^[A-Z]{2,}/', $type) ? $type : mb_strtolower($type));
    }

    /** "a chair", "an elephant stool": for a question or a sentence. Null with no usable type. */
    public static function withArticle(?string $type, ?string $other = null): ?string
    {
        $noun = self::noun($type, $other);

        return $noun === null ? null : (preg_match('/^[aeiou]/i', $noun) ? 'an ' : 'a ') . $noun;
    }

    /** The hard instruction put at the front of the AI prompt. */
    public static function promptInstruction(?string $type, ?string $other = null): ?string
    {
        $noun = self::noun($type, $other);

        if ($noun === null) {
            return null;
        }

        $article = preg_match('/^[aeiou]/i', $noun) ? 'an' : 'a';

        return "The piece must be {$article} {$noun}, a single piece of furniture, and nothing else. Show only that one {$noun}.";
    }
}
