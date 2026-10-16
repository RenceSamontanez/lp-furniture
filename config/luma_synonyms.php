<?php

/**
 * Luma's one small list of synonyms and common typos. Edit this file to teach her another spelling.
 *
 * Both a customer's message and every trained keyword are rewritten with these lists before they
 * are compared, so "magkano", "presyo" and "how much" all become the same word, "price".
 * Everything is lower case, no accents, no punctuation. Keys are what people type; values are the
 * one word or phrase they are rewritten to. Plain plurals (doors, chairs) are folded automatically.
 */
return [
    // Multi-word spellings, rewritten first. Longest keys win.
    'phrases' => [
        'how much'       => 'price',
        'ano ang presyo' => 'price',
        'gaano katagal'  => 'how long',
        'gaano ka tagal' => 'how long',
        'ilang araw'     => 'how long',
        'ilang buwan'    => 'how long',
        'how many days'  => 'how long',
        'how many weeks' => 'how long',
        'kailan matatapos' => 'how long',
        'kailan sisimulan' => 'when start',
        'when do you start' => 'when start',
        'when will you start' => 'when start',
        'tv console'     => 'tv stand',
        'tv rack'        => 'tv stand',
        'tv cabinet'     => 'tv stand',
        'television stand' => 'tv stand',
        'ready made'     => 'ready made',
        'made to order'  => 'made to order',
        'saan kayo'      => 'where',
        'mag order'      => 'order',
        'mag-order'      => 'order',
        'umorder'        => 'order',
        'mag bayad'      => 'pay',
        'magbayad'       => 'pay',
        'how do i'       => 'how',
        'how can i'      => 'how',
        'how to'         => 'how',
        'paano mag'      => 'how',
        'paano ang'      => 'how',
        'paano ko'       => 'how',
        'paano po'       => 'how',
    ],

    // Single words
    'words' => [
        // price
        'price' => 'price', 'pricing' => 'price', 'cost' => 'price', 'presyo' => 'price', 'magkano' => 'price',
        'halaga' => 'price', 'rate' => 'price', 'rates' => 'price', 'pricelist' => 'price list',
        'magkanu' => 'price', 'mgkano' => 'price', 'presyuhan' => 'price',
        // how / where / when
        'paano' => 'how', 'pano' => 'how', 'paanu' => 'how', 'panu' => 'how',
        'saan' => 'where', 'san' => 'where', 'kailan' => 'when', 'kelan' => 'when',
        // yes / thanks
        'salamat' => 'thanks', 'thank' => 'thanks', 'thankyou' => 'thanks',
        // greetings
        'kumusta' => 'kumusta', 'kamusta' => 'kumusta', 'musta' => 'kumusta', 'hello' => 'hello',
        'helo' => 'hello', 'hallo' => 'hello', 'hii' => 'hi', 'hiii' => 'hi', 'heyy' => 'hey',
        // shop talk
        'libre' => 'free', 'walang' => 'no', 'bayad' => 'pay', 'bayaran' => 'pay', 'payment' => 'payment',
        'deliver' => 'delivery', 'delivered' => 'delivery', 'shipping' => 'delivery', 'ship' => 'delivery',
        'idedeliver' => 'delivery', 'ideliver' => 'delivery', 'delivry' => 'delivery', 'delevery' => 'delivery',
        'kahoy' => 'wood', 'kahoi' => 'wood', 'wods' => 'wood', 'gemelina' => 'gmelina', 'gmelina' => 'gmelina',
        'melina' => 'gmelina', 'mahogani' => 'mahogany', 'mahagony' => 'mahogany', 'mahogeny' => 'mahogany',
        'akasya' => 'acacia', 'acasia' => 'acacia',
        'custome' => 'custom', 'customise' => 'customize', 'customized' => 'customize', 'customization' => 'customize',
        'customisation' => 'customize', 'costum' => 'custom', 'costumize' => 'customize',
        'pagawa' => 'custom', 'ipagawa' => 'custom', 'ipapagawa' => 'custom', 'pagagawa' => 'custom',
        'meron' => 'have', 'mayroon' => 'have', 'merun' => 'have',
        'ibebenta' => 'sell', 'nagbebenta' => 'sell', 'binebenta' => 'sell', 'benta' => 'sell',
        'cheapest' => 'cheapest', 'pinakamura' => 'cheapest', 'mura' => 'cheap',
        'expensive' => 'expensive', 'mahal' => 'expensive', 'pinakamahal' => 'expensive',
        'sukat' => 'size', 'laki' => 'size', 'dimensions' => 'dimension', 'measurements' => 'measurement',
        'kontak' => 'contact', 'tumawag' => 'call', 'tawag' => 'call', 'kausapin' => 'contact',
                'cancel' => 'cancel', 'icancel' => 'cancel', 'kansel' => 'cancel',
        'refund' => 'refund', 'ibalik' => 'return',
        'oras' => 'hours', 'schedule' => 'hours',
        'bukas' => 'open', 'sarado' => 'closed',
        'aktibo' => 'active', 'nagpapatakbo' => 'active',
        'warranty' => 'warranty', 'garantiya' => 'warranty', 'garantisado' => 'warranty',
        'gcsh' => 'gcash',
        'installment' => 'installment', 'instalment' => 'installment', 'hulugan' => 'installment',
        'hulog' => 'installment', 'downpayment' => 'deposit', 'dp' => 'deposit', 'reservation' => 'deposit',

        // furniture types (English and Tagalog) → the one word Luma uses for each
        'pinto' => 'door', 'pintuan' => 'door', 'pinturan' => 'door', 'doors' => 'door',
        'mesa' => 'table', 'lamesa' => 'table', 'hapag' => 'table',
        'upuan' => 'chair', 'silya' => 'chair', 'sillya' => 'chair', 'silla' => 'chair',
        'kama' => 'bed', 'higaan' => 'bed',
        'sopa' => 'sofa', 'couch' => 'sofa', 'sopas' => 'sofa',
        'kabinet' => 'cabinet', 'cabinet' => 'cabinet', 'cupboard' => 'cabinet', 'aparador' => 'wardrobe',
        'closet' => 'wardrobe', 'damitan' => 'wardrobe', 'wardrobe' => 'wardrobe',
        'estante' => 'shelf', 'shelves' => 'shelf', 'bookcase' => 'shelf', 'bookshelf' => 'shelf', 'rack' => 'shelf',
        'bangko' => 'bench', 'benches' => 'bench',
        'dumi' => 'stool',
        'disenyo' => 'design', 'idisenyo' => 'design', 'produkto' => 'product', 'paraan' => 'method',
        'labour' => 'labor', 'trabaho' => 'labor',
        'readymade' => 'ready made', 'ready-made' => 'ready made',
        'tvstand' => 'tv stand',
    ],
];
