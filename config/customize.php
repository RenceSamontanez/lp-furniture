<?php

/*
 * Customize page (the studio). Presentation data only: nothing here is a business rule.
 * The lists the server accepts live in App\Support\FurnitureTypes, WoodChoices and StyleChoices.
 */
return [
    // Line drawings, 64 x 64, keyed by the stored furniture type value.
    'icons' => [
        'Sofa'             => '<path d="M10 30v-8a6 6 0 0 1 6-6h32a6 6 0 0 1 6 6v8"/><path d="M6 32a4 4 0 0 1 8 0v8h36v-8a4 4 0 0 1 8 0v14H6z"/><path d="M14 46v6M50 46v6"/>',
        'Bed'              => '<path d="M6 14v38M58 36v16"/><path d="M6 40h52v-4a6 6 0 0 0-6-6H22a4 4 0 0 0-4 4z"/><path d="M6 52h52"/><circle cx="14" cy="32" r="4"/>',
        'Table'            => '<path d="M6 20h52v6H6z"/><path d="M12 26v26M52 26v26M32 26v8"/>',
        'Chair'            => '<path d="M20 8h24v22H20z"/><path d="M16 30h32v8H16z"/><path d="M20 38v16M44 38v16"/>',
        'Cabinet'          => '<rect x="10" y="8" width="44" height="46" rx="3"/><path d="M32 8v46M10 30h44"/><path d="M27 20v4M37 20v4M27 42v4M37 42v4M16 54v4M48 54v4"/>',
        'Door'             => '<rect x="16" y="6" width="32" height="52" rx="2"/><rect x="21" y="12" width="9" height="16" rx="1"/><rect x="21" y="34" width="22" height="18" rx="1"/><circle cx="42" cy="32" r="1.8"/>',
        'Shelf / Bookcase' => '<path d="M12 6v52M52 6v52M12 6h40M12 24h40M12 42h40M12 58h40"/><path d="M20 24V14h8v10M36 42V30h10v12"/>',
        'Desk'             => '<path d="M6 20h52v5H6z"/><path d="M10 25v28M54 25v28"/><rect x="34" y="25" width="20" height="16" rx="1"/><path d="M44 31v4"/>',
        'Other'            => '<path d="M32 8l5 15 15 5-15 5-5 15-5-15-15-5 15-5z"/><path d="M50 44v10M45 49h10"/>',
    ],

    // Wood swatches for the three woods: [light, dark, short description]. Other wood has its own striped swatch.
    'woods' => [
        'Gmelina'  => ['#e6c999', '#b98a58', 'Light and smooth'],
        'Mahogany' => ['#a8563a', '#5a2a1c', 'Deep red-brown'],
        'Acacia'   => ['#d9a85f', '#8a5a2a', 'Golden, bold grain'],
    ],

    // Examples offered under "Something else".
    'other_type_examples' => ['Staircase railing', 'Altar', 'TV wall panel', 'Headboard', 'Kitchen counter', 'Gazebo'],
];
