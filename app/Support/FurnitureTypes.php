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

    /**
     * What Length, Width and Height mean for each type, with a typical size as a suggestion only.
     * Shown in the measuring guide. Nothing here is checked or enforced anywhere.
     */
    public const GUIDE = [
        'Chair' => [
            'length' => 'Seat or frame, front to back or side to side, whichever is longer.',
            'width'  => 'The other horizontal side of the seat or frame.',
            'height' => 'Floor to the top of the backrest.',
            'hint'   => 'A dining chair seat is usually about 45 cm high and the whole chair 85 to 95 cm.',
        ],
        'Table' => [
            'length' => 'The long side of the tabletop.',
            'width'  => 'The short side of the tabletop.',
            'height' => 'Floor to the top of the tabletop.',
            'hint'   => 'A table is usually 70 to 80 cm high. A dining table for six is often about 180 by 90 cm.',
        ],
        'Desk' => [
            'length' => 'The long side of the desktop.',
            'width'  => 'The short side of the desktop, front to back.',
            'height' => 'Floor to the top of the desktop.',
            'hint'   => 'A desk is usually 72 to 76 cm high and 120 to 160 cm long.',
        ],
        'Bed' => [
            'length' => 'Head to foot, including the headboard and footboard.',
            'width'  => 'Side to side.',
            'height' => 'Floor to the top of the headboard.',
            'hint'   => 'Mattress sizes differ: a double is about 135 by 190 cm and a queen about 150 by 200 cm. Add the frame.',
        ],
        'Sofa' => [
            'length' => 'Across the front, armrest to armrest.',
            'width'  => 'Front to back.',
            'height' => 'Floor to the top of the backrest.',
            'hint'   => 'A three-seater is often 190 to 220 cm long, about 90 cm from front to back and 80 to 90 cm high.',
        ],
        'Cabinet' => [
            'length' => 'Across the front.',
            'width'  => 'Front to back.',
            'height' => 'Floor to the top.',
            'hint'   => 'A kitchen base cabinet is usually about 60 cm from front to back and 85 to 90 cm high.',
        ],
        'Shelf / Bookcase' => [
            'length' => 'Across the front.',
            'width'  => 'Front to back.',
            'height' => 'Floor to the top.',
            'hint'   => 'Books need about 25 to 30 cm from front to back. A tall bookcase is often 180 to 200 cm high.',
        ],
        'Door' => [
            'length' => 'Across the door, edge to edge.',
            'width'  => 'The thickness of the door.',
            'height' => 'Top to bottom.',
            'hint'   => 'A room door is often 80 to 90 cm across, 200 to 210 cm high and 3 to 4 cm thick. Measure the frame opening, not the old door.',
        ],
        'Wardrobe' => [
            'length' => 'Across the front.',
            'width'  => 'Front to back.',
            'height' => 'Floor to the top.',
            'hint'   => 'Clothes on hangers need about 55 to 60 cm from front to back. A two-door wardrobe is often 100 to 120 cm across.',
        ],
        'TV stand' => [
            'length' => 'Across the front.',
            'width'  => 'Front to back.',
            'height' => 'Floor to the top.',
            'hint'   => 'A TV stand is usually 40 to 55 cm high and a little wider than the TV.',
        ],
        'Bench' => [
            'length' => 'The long side of the seat.',
            'width'  => 'The short side of the seat.',
            'height' => 'Floor to the top of the seat, or the backrest if it has one.',
            'hint'   => 'A bench seat is usually about 45 cm high. A dining bench is often 120 to 160 cm long.',
        ],
        'Stool' => [
            'length' => 'The longer side of the seat (or its diameter if round).',
            'width'  => 'The other side of the seat (the same number if round).',
            'height' => 'Floor to the top of the seat.',
            'hint'   => 'A bar stool seat is usually 65 to 75 cm high and a low stool about 45 cm.',
        ],
        self::OTHER => [
            'length' => 'The longest horizontal side.',
            'width'  => 'The shorter horizontal side.',
            'height' => 'Floor to the top.',
            'hint'   => 'Not sure what to measure? Describe the piece and ask us.',
        ],
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
