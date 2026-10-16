<?php

/**
 * Luma, LP Furniture Shop's AI Assistant: the owner-confirmed shop facts her answers use.
 * Change a value here and every answer follows. Nothing here is looked up from outside.
 */
return [
    'name'    => 'Luma',
    'tagline' => "LP Furniture Shop's AI Assistant",

    'timezone' => 'Asia/Manila',

    // Shop hours: 8:00 AM to 5:00 PM, Monday to Friday (ISO weekdays, 1 = Monday)
    'hours' => [
        'open'  => '08:00',
        'close' => '17:00',
        'days'  => [1, 2, 3, 4, 5],
    ],

    // Typical time to finish any furniture type. Always called an estimate: the exact
    // expected ready date is the one given in the customer's quote.
    'production_days' => ['min' => 15, 'max' => 30],

    // Free delivery covers locations close to San Juan, Batangas. No town or barangay list on purpose.
    'free_delivery' => [
        'min_km'      => 5,
        'max_km'      => 15,
        'min_minutes' => 10,
        'max_minutes' => 30,
    ],

    // Matching
    'min_score' => 2.0,   // a reply needs at least this score, otherwise Luma uses the fallback
    'max_cards' => 4,

    // Words that are ignored on both sides of a match (fillers, not meaning)
    'stop_words' => [
        'a', 'an', 'the', 'is', 'are', 'po', 'opo', 'ba', 'naman', 'lang', 'ang', 'ng', 'nang', 'ay',
        'pls', 'please', 'kindly', 'sir', 'maam', 'mam',
    ],

    // One closing line, added in one place (Luma::finish) to every answer
    'closing' => [
        'en' => "You're welcome! Luma is always here to help.",
        'tl' => 'Walang anuman po! Nandito lang si Luma para tumulong.',
    ],

    // Quick-reply chips in Tagalog (the chip text is sent back as the customer's next question)
    'chips_tl' => [
        'How to order'     => 'Paano mag-order',
        'Custom design'    => 'Custom na disenyo',
        'Prices'           => 'Presyo',
        'Delivery'         => 'Delivery',
        'Delivery info'    => 'Delivery',
        'View all products' => 'Tingnan ang mga produkto',
        'Payment options'  => 'Paraan ng bayad',
        'Track my order'   => 'Saan na ang order ko',
    ],

    // Values the owner has not confirmed yet. Luma never states these as fact; each answer
    // that depends on one says the shop will confirm it.
    'to_confirm' => [
        'delivery_fee_beyond_free_area' => 'Fee beyond the free area is set by the shop per delivery (no flat fee confirmed; Business Settings has no delivery fee field yet).',
        'shop_street_address'           => 'Only "San Juan, Batangas" is known; no street address is given in answers.',
        'warranty_period'               => 'No warranty length confirmed; answers point to the Terms page.',
        'labor_only_prices'             => 'Labor-only prices vary by furniture type and are discussed by phone; no figures given.',
        'public_holidays'               => 'Hours are Monday to Friday only; holidays are not handled, so a holiday counts as a working day.',
    ],
];
