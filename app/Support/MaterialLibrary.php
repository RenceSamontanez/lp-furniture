<?php

namespace App\Support;

/**
 * The starter furniture-type library: how much of each inventory material one piece uses. The numbers already include the
 * safety margin (wood +18%, everything else x2), so nothing is added on top. Wood is in sqft (board feet) to match the inventory.
 * Reference sizes (cm, length x width x height) are used only to scale CUSTOM orders; catalog orders never scale.
 */
final class MaterialLibrary
{
    /** The six numbers, in the order the forms show them: key => [label, unit]. */
    public const FIELDS = [
        'wood'    => ['Wood', 'sqft'],
        'varnish' => ['Varnish', 'liters'],
        'lacquer' => ['Lacquer', 'liters'],
        'nails'   => ['Nails and Screws', 'pieces'],
        'sand'    => ['Sandpaper', 'pieces'],
        'glue'    => ['Wood Glue', 'liters'],
    ];

    /** The inventory row each consumable is taken from (exact name). */
    public const CONSUMABLE_ROWS = [
        'varnish' => 'Barnis / Varnish',
        'lacquer' => 'White Lacquer',
        'nails'   => 'Nails and Screws',
        'sand'    => 'Sandpaper',
        'glue'    => 'Wood Glue',
    ];

    public const OTHER = 'Other / Unknown';

    /** name => [wood, varnish, lacquer, nails, sand, glue] */
    public const TYPES = [
        'Bed, King'                       => [100, 2.4, 1.6, 600, 16, 0.5],
        'Bed, Queen'                      => [77, 2, 1.3, 540, 14, 0.4],
        'Bed, Single / Double'            => [53, 1.2, 0.8, 300, 10, 0.24],
        'Bunk Bed'                        => [83, 2, 1.2, 700, 16, 0.4],
        'Crib / Baby Bed'                 => [47, 1.2, 0.6, 400, 12, 0.24],
        'Headboard'                       => [18, 0.6, 0.4, 120, 4, 0.1],
        'Bedside Table'                   => [12, 0.4, 0.24, 100, 4, 0.08],
        'Wardrobe (4-Door)'               => [94, 2.4, 1.6, 600, 16, 0.5],
        'Dresser / Drawer Chest'          => [47, 1.2, 0.8, 400, 12, 0.3],
        'Vanity Table'                    => [30, 0.8, 0.5, 240, 8, 0.2],
        'Cabinet / TV Stand'              => [41, 1, 0.6, 300, 10, 0.24],
        'Display / China Cabinet'         => [65, 1.8, 1.2, 500, 16, 0.4],
        'Kitchen Cabinet (Set)'           => [71, 1.8, 1.2, 600, 16, 0.4],
        'Shoe Rack'                       => [14, 0.4, 0.2, 120, 4, 0.08],
        'Shelf / Bookcase'                => [35, 0.8, 0.5, 240, 8, 0.2],
        'Dining Table'                    => [53, 1.2, 0.8, 200, 12, 0.3],
        'Dining Set (Table + 6 Chairs)'   => [138, 3, 2, 680, 36, 0.9],
        'Desk / Study Table'              => [47, 1, 0.6, 240, 10, 0.24],
        'Coffee Table'                    => [18, 0.5, 0.3, 120, 6, 0.12],
        'Console Table'                   => [14, 0.4, 0.24, 100, 4, 0.1],
        'Reception / Counter Desk'        => [65, 1.6, 1, 440, 12, 0.4],
        'Bar Counter'                     => [59, 1.6, 1, 400, 12, 0.4],
        'Chair'                           => [14, 0.3, 0.2, 80, 4, 0.1],
        'Rocking Chair'                   => [21, 0.6, 0.3, 160, 6, 0.16],
        'Office Chair (Wood Frame)'       => [17, 0.4, 0.2, 120, 4, 0.12],
        'Stool'                           => [7, 0.16, 0.1, 40, 2, 0.06],
        'Bar Stool'                       => [9, 0.24, 0.16, 60, 2, 0.08],
        'Bench'                           => [24, 0.5, 0.3, 120, 6, 0.16],
        'Ottoman / Footstool'             => [9, 0.2, 0.1, 60, 2, 0.06],
        'Sofa, 2-Seater'                  => [35, 0.6, 0.3, 300, 6, 0.18],
        'Sofa, 3-Seater (Frame)'          => [47, 0.8, 0.4, 400, 8, 0.24],
        'Sala Set (5-Piece)'              => [130, 2.4, 1.2, 1000, 20, 0.6],
        'Door'                            => [21, 0.6, 0.4, 80, 6, 0.1],
        'Window Frame'                    => [12, 0.4, 0.3, 120, 4, 0.08],
        'Altar'                           => [41, 1, 0.8, 200, 10, 0.2],
        'Small Items (Frames, Trays)'     => [4, 0.2, 0.12, 30, 2, 0.04],
        self::OTHER                       => [41, 1, 0.8, 200, 10, 0.2],
    ];

    /** Reference sizes in cm (length, width, height) for scaling custom orders. Types not listed have none (factor 1). */
    public const REF_DIMS = [
        'Bed, King'                => [193, 203, 110],
        'Bed, Queen'               => [160, 203, 110],
        'Bed, Single / Double'     => [137, 190, 100],
        'Dining Table'             => [180, 90, 75],
        'Desk / Study Table'       => [120, 60, 75],
        'Wardrobe (4-Door)'        => [160, 55, 200],
        'Cabinet / TV Stand'       => [150, 40, 55],
        'Sofa, 3-Seater (Frame)'   => [200, 90, 85],
        'Chair'                    => [45, 45, 90],
        'Door'                     => [90, 5, 210],
    ];

    /** The nine products that exist today -> the starter type they begin from (by product name, case-insensitive). */
    public const EXISTING_PRODUCTS = [
        'King Bed Frame'            => 'Bed, King',
        '4-Door Wardrobe Cabinet'   => 'Wardrobe (4-Door)',
        'TV Console Cabinet'        => 'Cabinet / TV Stand',
        'Executive Office Chair'    => 'Office Chair (Wood Frame)',
        'Rocking Chair'             => 'Rocking Chair',
        '3-Seater Sofa Frame'       => 'Sofa, 3-Seater (Frame)',
        'Sala Set 5-piece'          => 'Sala Set (5-Piece)',
        'Narra Dining Table'        => 'Dining Table',
        'Study Table with Shelves'  => 'Desk / Study Table',
    ];

    /** Rows for the Wood list: key => value of the six numbers. */
    public static function values(string $type): ?array
    {
        $row = self::TYPES[$type] ?? null;

        return $row === null ? null : array_combine(array_keys(self::FIELDS), $row);
    }
}
