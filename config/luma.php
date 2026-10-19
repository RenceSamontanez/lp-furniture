<?php

/**
 * Luma, LP Furniture Shop's AI Assistant: the owner-confirmed shop facts her answers use.
 * Change a value here and every answer follows. Nothing here is looked up from outside.
 */
return [
    'name'    => 'Luma',
    'tagline' => "LP Furniture Shop's AI Assistant",

    'timezone' => 'Asia/Manila',

    // Show "Ask Luma about this" on Other Services notification details pages. Keep false until Luma can look up
    // a request by its reference number (Prompt 8f). Switch on with LUMA_NOTIFICATION_ASK_BUTTON=true in .env.
    'notification_ask_button' => (bool) env('LUMA_NOTIFICATION_ASK_BUTTON', false),

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

    // The furniture types Luma recognises in a question. The key is the one word or phrase she
    // rewrites every spelling to (see luma_synonyms.php) and looks for in a product's category and name.
    // Labels are used in sentences: "Doors range from ...".
    'types' => [
        'door'     => ['en' => 'doors',     'tl' => 'mga pinto'],
        'table'    => ['en' => 'tables',    'tl' => 'mga mesa'],
        'chair'    => ['en' => 'chairs',    'tl' => 'mga upuan'],
        'desk'     => ['en' => 'desks',     'tl' => 'mga desk'],
        'bed'      => ['en' => 'beds',      'tl' => 'mga kama'],
        'sofa'     => ['en' => 'sofas',     'tl' => 'mga sopa'],
        'cabinet'  => ['en' => 'cabinets',  'tl' => 'mga kabinet'],
        'shelf'    => ['en' => 'shelves',   'tl' => 'mga estante'],
        'wardrobe' => ['en' => 'wardrobes', 'tl' => 'mga aparador'],
        'tv stand' => ['en' => 'TV stands', 'tl' => 'mga TV stand'],
        'bench'    => ['en' => 'benches',   'tl' => 'mga bangko'],
        'stool'    => ['en' => 'stools',    'tl' => 'mga dumi'],
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
        'Wood choices'     => 'Anong kahoy',
        'Contact us'       => 'Makausap ang shop',
        'How long does it take' => 'Gaano katagal',
        'Price range'      => 'Saklaw ng presyo',
        'Labor only'       => 'Labor lang',
        'Shop hours'       => 'Oras ng shop',
        'Measuring guide'  => 'Paano sukatin',
        'Free delivery'    => 'Libre ba ang delivery',
        'Cancel an order'  => 'Cancel ng order',
        'Deposit and installment' => 'Hulugan',
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
