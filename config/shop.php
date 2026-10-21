<?php

/**
 * Public shop details used by the footer. Contact Settings (Admin) win; these are used only when a value there is empty.
 */
return [
    'fallback' => [
        'facebook_url'   => 'https://www.facebook.com/lpfurnituresj',
        'tiktok_url'     => null,   // add the real link in Contact Settings; nothing is shown until then
        'phone'          => '09912394770',
        'email'          => 'johncedricklumanglas@gmail.com',
        'shop_address'   => 'Sitio Pulo Ilaya, Calit-calit, San Juan, Batangas',
        'shop_landmarks' => 'San Juan Doctors Hospital, Calit-calit Elementary School, Calit-calit Barangay Hall',
        // Exact pin: Plus Code RCH5+QX6 (Sitio Pulo-Ilaya, San Juan, Batangas), decoded to the centre of its cell.
        'map_lat'        => 13.829413,
        'map_lng'        => 121.409891,
    ],

    // Payment tiles in display order. A logo is used when public/{logo_dir}/{key}.{svg,png,jpg,jpeg,webp} exists (first match in that order, extension in any case), otherwise the name is shown.
    'logo_dir' => 'images/payments',
    'logo_extensions' => ['svg', 'png', 'jpg', 'jpeg', 'webp'],
    'payments' => [
        'gcash'      => 'GCash',
        'maya'       => 'Maya',
        'unionbank'  => 'UnionBank',
        'maribank'   => 'MariBank',
        'visa'       => 'Visa',
        'mastercard' => 'Mastercard',
    ],

    // Policy links in display order. A link shows only when its route exists; 'route' => null means the page is not written yet.
    'policies' => [
        ['label' => 'Terms and Conditions',      'route' => 'terms'],
        ['label' => 'Privacy Policy',            'route' => null],
        ['label' => 'Refund and Cancellation',   'route' => null],
        ['label' => 'Payment Terms',             'route' => null],
        ['label' => 'Delivery and Installation', 'route' => null],
        ['label' => 'Warranty and Care',         'route' => null],
        ['label' => 'Other Services Terms',      'route' => 'services.terms'],
        ['label' => 'Custom Design Guide',       'route' => 'guide'],
    ],
];
