<?php

namespace App\Support\Luma;

use App\Models\ChatbotIntent;

/**
 * Luma's built-in answers, in English and Tagalog. `php artisan chatbot:sync-defaults` inserts the ones
 * that are missing; an intent whose name (stored in its Category) already exists is never touched, so an
 * admin's edits are safe. The same name also tells Luma which buttons and special behaviour go with the answer.
 *
 * Text rules: short, accurate, no prices, days or places beyond config/luma.php. {{tokens}} are filled in
 * by Facts (hours, estimate, contact details...).
 */
final class DefaultIntents
{
    /** Names whose reply carries the shop's contact details as a business card. */
    public const CARD = ['luma_labor_only', 'luma_contact_shop', 'luma_about_business', 'luma_shop_open'];

    /**
     * name => keywords, en, tl, chips, buttons (label_en, label_tl, route|url), handler
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $types = array_keys(config('luma.types', []));

        $readyMadeKeywords = ['ready made', 'ordinary design', 'regular design', 'existing design', 'premade', 'in stock',
            'available design', 'available product', 'ready made design', 'catalog design', 'ordinary door', 'regular door'];
        foreach ($types as $type) {
            foreach (['do you have', 'do you sell', 'do you make', 'meron kayong', 'nagbebenta kayo ng', 'any', 'available'] as $cue) {
                $readyMadeKeywords[] = "{$cue} {$type}";
            }
        }

        return [
            'luma_greeting' => [
                'keywords' => 'hello, hi, hey, kumusta, good morning, good afternoon, good evening, magandang umaga, magandang hapon, magandang gabi, who are you, who is luma, what is your name, sino ka, sino si luma, ano pangalan mo, what can you do, luma',
                'en' => "Hi, I'm Luma, LP Furniture Shop's assistant. I can help with prices, ready-made designs, custom designs, wood choices, delivery, payment and your orders. What would you like to know?",
                'tl' => 'Kumusta po! Ako po si Luma, ang assistant ng LP Furniture Shop. Matutulungan ko po kayo sa presyo, mga ready-made na disenyo, custom na disenyo, mga kahoy, delivery, bayad at inyong mga order. Ano po ang gusto ninyong malaman?',
                'chips' => 'Prices, Custom design, Delivery, Contact us',
            ],

            'luma_products_cost' => [
                'keywords' => 'price, product price, furniture price, how much furniture, price of your products, price of your services, services price, magkano ang serbisyo',
                'en' => "Every product in our Catalog shows its price on its page. Custom designs are priced by the shop after we call you, in a written quote, and nothing is charged until you accept it. Here are some of our products. Tell me the type (for example door, table or bed) and I'll show you those.",
                'tl' => 'Nakasulat po ang presyo ng bawat produkto sa Catalog. Ang custom na disenyo naman po ay pinepresyuhan ng shop pagkatapos ka naming tawagan, sa nakasulat na quote, at walang sisingilin hangga’t hindi ninyo ito tinatanggap. Heto po ang ilan sa aming mga produkto. Sabihin lang po ang uri (halimbawa pinto, mesa o kama) at ipapakita ko po.',
                'chips' => 'Price range, Custom design, Labor only, Payment options',
                'handler' => 'products',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_ready_made' => [
                'keywords' => implode(', ', $readyMadeKeywords),
                'en' => 'Yes, our Catalog has ordinary designs for many furniture types. Everything is made to order: nothing is built in advance, so production starts as soon as your order is placed and confirmed.',
                'tl' => 'Opo, may mga ordinaryong disenyo po sa aming Catalog para sa iba’t ibang uri ng furniture. Lahat po ay gawa kapag may order: wala pong ginagawa nang maaga, kaya sisimulan ang paggawa pagka-order at kumpirmasyon ninyo.',
                'chips' => 'Prices, Price range, Custom design, How long does it take',
                'handler' => 'products',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_about_business' => [
                'keywords' => 'about your business, tell me about your business, about lp furniture, about the shop, about us, who is lp furniture, what is lp furniture, what do you do, what do you sell, ano ang lp furniture, tungkol sa negosyo, anong negosyo ninyo, kwento ng shop',
                'en' => 'LP Furniture Shop is a furniture shop in San Juan, Batangas. We make furniture to order, both ready-made designs from our Catalog and custom pieces designed with you. We are open {{hours}}. {{contact}}',
                'tl' => 'Ang LP Furniture Shop po ay isang furniture shop sa San Juan, Batangas. Gawa po kapag may order ang aming furniture, mula sa mga ready-made na disenyo sa Catalog hanggang sa custom na piraso na idinisenyo kasama kayo. Bukas po kami {{hours}}. {{contact}}',
                'chips' => 'Prices, Custom design, Shop hours, Contact us',
                'buttons' => [['View products', 'Tingnan ang mga produkto', 'catalog']],
            ],

            'luma_shop_open' => [
                'keywords' => 'are you open, still open, open now, open today, are you active, still active, is the shop active, store hours, shop hours, business hours, opening hours, office hours, what time do you open, what time do you close, anong oras, oras ng shop, bukas pa ba, bukas kayo, active pa ba, active pa kayo, open pa ba, working hours',
                'en' => 'Yes, LP Furniture Shop is active. Our hours are {{hours}} (Philippine time). {{open_status}}',
                'tl' => 'Opo, aktibo pa po ang LP Furniture Shop. Ang oras po namin ay {{hours}} (oras sa Pilipinas). {{open_status}}',
                'chips' => 'Contact us, Prices, Custom design',
            ],

            'luma_price_list' => [
                'keywords' => 'price list, list of prices, product list, price catalog, price guide, listahan ng presyo, catalog, all products, show products',
                'en' => "We don't keep one fixed price list because every piece is made to order. The price of each Catalog product is on its page, and custom designs are quoted by the shop after we call you. Here are some products. Tell me a type, like door or table, for more.",
                'tl' => 'Wala po kaming iisang nakapirming price list dahil gawa kapag may order ang bawat piraso. Nasa pahina po ng bawat produkto ang presyo nito sa Catalog, at ang custom na disenyo ay kino-quote ng shop pagkatapos ka naming tawagan. Heto po ang ilang produkto. Sabihin lang po ang uri, gaya ng pinto o mesa, para sa iba pa.',
                'chips' => 'Price range, Custom design, Labor only',
                'handler' => 'products',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_price_range' => [
                'keywords' => 'price range, range, cheapest, most expensive, lowest price, highest price, starting price, how cheap, pinakamura, pinakamahal, saklaw ng presyo, hanggang magkano, mula magkano',
                'en' => 'Tell me the furniture type (for example door, table or bed) and I will show you the lowest and highest price in our Catalog.',
                'tl' => 'Sabihin lang po ang uri ng furniture (halimbawa pinto, mesa o kama) at ipapakita ko po ang pinakamababa at pinakamataas na presyo sa aming Catalog.',
                'chips' => 'Prices, Custom design, Labor only',
                'handler' => 'range',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_how_long' => [
                'keywords' => 'how long, how many days, how many weeks, when will it be done, when will it be ready, when is it ready, how fast, turnaround, production time, processing time, lead time, gaano katagal, ilang araw, ilang linggo, kailan matatapos, kailan magiging ready, gaano ka bilis',
                'en' => 'Finishing a piece usually takes roughly {{days}} days, for any furniture type. That is an estimate: the exact expected ready date is given in your quote. Everything is made to order, so nothing is built in advance and production starts as soon as your order is placed and confirmed (once the deposit is received).',
                'tl' => 'Karaniwan pong aabutin ng humigit-kumulang {{days}} araw ang paggawa, para sa anumang uri ng furniture. Pagtataya lamang po iyan: ang eksaktong petsa kung kailan ito ready ay nasa quote ninyo. Gawa po kapag may order ang lahat, kaya wala pong ginagawa nang maaga at sisimulan ang paggawa pagka-order at kumpirmasyon ninyo (kapag natanggap na ang deposito).',
                'chips' => 'Made to order?, Custom design, Payment options',
            ],

            'luma_made_to_order' => [
                'keywords' => 'ready made or made to order, made to order, built in advance, build in advance, do you build in advance, are your items ready made, when start, when do you start making, when do you start, kailan sisimulan, simulan ang paggawa, ready stock, nakahanda na ba',
                'en' => 'Everything is made to order. Nothing is built in advance, so production starts as soon as your order is placed and confirmed, which is once the deposit is received. Finishing usually takes roughly {{days}} days (an estimate; the exact expected ready date is in your quote).',
                'tl' => 'Gawa po kapag may order ang lahat. Wala pong ginagawa nang maaga, kaya sisimulan ang paggawa pagka-order at kumpirmasyon ninyo, ibig sabihin kapag natanggap na ang deposito. Karaniwang aabutin ng humigit-kumulang {{days}} araw (pagtataya lamang; ang eksaktong petsa ay nasa quote ninyo).',
                'chips' => 'How long does it take, Payment options, Custom design',
            ],

            'luma_labor_only' => [
                'keywords' => 'labor only, labor, i will provide the wood, i have my own wood, own wood, my own wood, bring my own wood, supply my own wood, wood provided, wood is provided, customer supplied wood, sariling kahoy, may kahoy na ako, workmanship only, magkano ang labor',
                'en' => 'Yes, we can build with wood you provide (labor only). Labor-only prices vary by furniture type, so the owner discusses them with you by phone. Please send a request or contact us:',
                'tl' => 'Opo, maaari po kaming gumawa gamit ang kahoy na ibibigay ninyo (labor lang). Magkakaiba po ang presyo ng labor depende sa uri ng furniture, kaya ang may-ari mismo ang makikipag-usap sa inyo sa telepono. Magpadala po ng request o makipag-ugnayan sa amin:',
                'chips' => 'Custom design, Wood choices, Contact us',
                'handler' => 'labor',
                'buttons' => [['Send a request', 'Magpadala ng request', 'services.request']],
            ],

            'luma_free_delivery' => [
                'keywords' => 'free delivery, delivery free, free shipping, is delivery free, libre delivery, libre ang delivery, delivery area, delivery locations, which locations, do you deliver to, how far do you deliver, saan libre ang delivery, hatid',
                'en' => 'Delivery is free for locations close to San Juan, Batangas: {{free_area}}. Beyond that area, {{fee_note}} I cannot list every town or barangay here, so please contact us to check an exact location. {{contact}}',
                'tl' => 'Libre po ang delivery sa mga lugar na malapit sa San Juan, Batangas: {{free_area}}. Kung lampas po sa lugar na iyon, {{fee_note}} Hindi ko po mailista ang bawat bayan o barangay dito, kaya makipag-ugnayan po sa amin para malaman ang eksaktong lugar. {{contact}}',
                'chips' => 'Prices, Contact us, Payment options',
            ],

            'luma_delivery_fee' => [
                'keywords' => 'delivery, delivery fee, delivery price, delivery charge, delivery cost, shipping fee, magkano ang delivery, pickup, pick up, pick up at the shop',
                'en' => 'Delivery is free for locations close to San Juan, Batangas ({{free_area}}). Beyond that, {{fee_note}} You can also choose pickup at the shop when you check out. {{contact}}',
                'tl' => 'Libre po ang delivery sa mga lugar na malapit sa San Juan, Batangas ({{free_area}}). Lampas po doon, {{fee_note}} Maaari rin po kayong mag-pickup sa shop pagdating sa checkout. {{contact}}',
                'chips' => 'Free delivery, Payment options, Track my order',
            ],

            'luma_ai_design_steps' => [
                'keywords' => 'how does ai design work, ai design, custom design, custom order, customize, how to customize, how to request a custom design, step by step, steps for custom design, custom furniture, i want a custom, can i customize, design tool, generate design, custom na disenyo, paano ang custom design, gusto kong ipagawa, ipagawa',
                'en' => "Custom design works like this: 1) Choose the furniture type. 2) Enter the size as Length x Width x Height. 3) Choose the wood. 4) Describe what you want. 5) Our AI design tool generates pictures. 6) Review them and pick one. 7) Send it to our team. 8) We call you to talk about it. 9) You receive a quote. 10) If you agree, you accept the agreement. 11) You pay to start. Everything is made to order: production starts once your order is placed and confirmed, and finishing usually takes roughly {{days}} days (an estimate; the exact date is in your quote). The full steps are in our guide.",
                'tl' => 'Ganito po ang custom na disenyo: 1) Piliin ang uri ng furniture. 2) Ilagay ang sukat bilang Haba x Lapad x Taas. 3) Piliin ang kahoy. 4) Ilarawan ang gusto ninyo. 5) Gagawa ng mga larawan ang aming AI design tool. 6) Tingnan ang mga ito at pumili ng isa. 7) Ipadala sa aming team. 8) Tatawagan namin kayo para pag-usapan. 9) Makakatanggap kayo ng quote. 10) Kung sang-ayon po kayo, tatanggapin ninyo ang agreement. 11) Magbayad para masimulan. Gawa po kapag may order ang lahat: sisimulan ang paggawa pagka-order at kumpirmasyon ninyo, at karaniwang aabutin ng humigit-kumulang {{days}} araw (pagtataya lamang; ang eksaktong petsa ay nasa quote).',
                'chips' => 'Measuring guide, Wood choices, How long does it take, Labor only',
                'buttons' => [['Open the guide', 'Buksan ang gabay', 'guide'], ['Start a custom design', 'Magsimula ng custom na disenyo', 'design.index']],
            ],

            'luma_dimensions' => [
                'keywords' => 'how to measure, how do i measure, measuring guide, how to know the size, how to know the dimensions, what are the dimensions, what size, what sizes, length width height, length x width x height, which side is the length, i dont know the size, paano sukatin, paano malaman ang sukat, paano ang sukat, anong sukat',
                'en' => 'Size is always written as Length x Width x Height, in the unit you choose. Length is the longest horizontal side, Width is the other horizontal side, and Height is from the floor up. Our guide shows how to measure each type of furniture, with pictures. If you are not sure, a rough size is fine: we will confirm it when we call you.',
                'tl' => 'Laging nakasulat ang sukat bilang Haba x Lapad x Taas, sa unit na pipiliin ninyo. Ang Haba ay ang pinakamahabang gilid na pahalang, ang Lapad ay ang isa pang pahalang na gilid, at ang Taas ay mula sa sahig pataas. Ipinapakita po ng aming gabay kung paano sukatin ang bawat uri ng furniture, may mga larawan. Kung hindi po kayo sigurado, ayos lang ang tantiya: kukumpirmahin namin ito kapag tinawagan namin kayo.',
                'chips' => 'Custom design, Wood choices, Contact us',
                'buttons' => [['Open the measuring guide', 'Buksan ang gabay sa pagsukat', 'guide']],
            ],

            'luma_best_wood' => [
                'keywords' => 'which wood is best, which wood best, best wood, wood best, what wood, wood types, types of wood, wood choice, wood options, gmelina, mahogany, acacia, special request wood, other wood, anong kahoy, ano ang magandang kahoy, alin ang pinakamagandang kahoy, wood',
                'en' => 'We build with Gmelina, Mahogany and Acacia, or you can make a special request. In general: Gmelina is the budget-friendly choice and takes paint or stain well, good for indoor pieces that will not be heavily used. Mahogany has a classic reddish-brown look and is a good choice for a finer, longer-lasting piece, and it usually costs more. Acacia is hard and durable, a good pick for tables, chairs and anything used every day. For another kind of wood, choose Special request and the team will confirm what is possible and the price when we call you.',
                'tl' => 'Gumagawa po kami gamit ang Gmelina, Mahogany at Acacia, o maaari kayong mag-special request. Sa pangkalahatan: ang Gmelina po ang pinakamurang opsyon at maganda ang tanggap sa pintura o stain, bagay sa mga gamit sa loob ng bahay na hindi masyadong madalas gamitin. Ang Mahogany ay may klasikong mapula-pulang kayumanggi at magandang piliin para sa mas pino at pangmatagalang piraso, at karaniwang mas mahal. Ang Acacia ay matigas at matibay, magandang piliin para sa mesa, upuan at mga gamit araw-araw. Para sa ibang uri ng kahoy, piliin ang Special request at kukumpirmahin ng team ang posibilidad at presyo kapag tinawagan namin kayo.',
                'chips' => 'Custom design, Labor only, Contact us',
            ],

            'luma_payment_methods' => [
                'keywords' => 'payment method, payment methods, payment options, payment option, how to pay, ways to pay, how do i pay, pay, gcash, stripe, credit card, debit card, cash, cash on delivery, cash on pickup, cod, paraan ng bayad, paano magbayad, anong payment, paano bayaran',
                'en' => 'You can pay by card (Stripe), by GCash (through PayMongo), or in cash when you pick up or when it is delivered. You choose at checkout. An online payment is confirmed as soon as it goes through.',
                'tl' => 'Maaari po kayong magbayad sa card (Stripe), sa GCash (sa pamamagitan ng PayMongo), o cash kapag kukunin o ide-deliver na ang order. Pipili po kayo sa checkout. Nakukumpirma po ang online na bayad sa sandaling matagumpay itong maiproseso.',
                'chips' => 'Deposit and installment, Cancel an order, Track my order',
            ],

            'luma_deposit_rules' => [
                'keywords' => 'deposit, down payment, reservation, installment, installments, pay in parts, split payment, partial payment, pay later, layaway, how much deposit, minimum deposit, magkano ang down, hulugan, hulog, pwede bang hulugan',
                'en' => 'For Catalog orders you can pay in full or in installments: the first payment is a {{catalog_down}}% down payment and the rest is split over {{months}} months, every {{frequency}} days. For custom orders, the deposit is written in your quote and is at least {{deposit_min}}% of the total. Production starts once the deposit is received.',
                'tl' => 'Sa mga order mula sa Catalog, maaari pong magbayad nang buo o hulugan: ang unang bayad ay {{catalog_down}}% na down payment at hahatiin ang natitira sa {{months}} buwan, kada {{frequency}} araw. Sa custom na order, nakasulat sa quote ang deposito at hindi bababa sa {{deposit_min}}% ng kabuuan. Sisimulan ang paggawa kapag natanggap na ang deposito.',
                'chips' => 'Payment options, How long does it take, Cancel an order',
            ],

            'luma_cancel_refund' => [
                'keywords' => 'cancel, cancel order, cancel my order, cancellation, refund, return, change my order, i made a mistake, pwede ba icancel, ibalik ang pera, magrefund',
                'en' => 'Our cancellation and refund rules are in the Terms and Conditions. Because every piece is made to order, please contact us as soon as you can if you need to change or cancel an order. {{contact}}',
                'tl' => 'Nakasulat po ang aming patakaran sa pagkansela at refund sa Terms and Conditions. Dahil gawa po kapag may order ang bawat piraso, makipag-ugnayan po sa amin sa lalong madaling panahon kung kailangan ninyong baguhin o kanselahin ang order. {{contact}}',
                'chips' => 'Payment options, Track my order, Contact us',
                'buttons' => [['Read the Terms', 'Basahin ang Terms', 'terms']],
            ],

            'luma_track_order' => [
                'keywords' => 'track, tracking, track my order, order status, where is my order, my orders, check my order, order update, update on my order, saan na ang order ko, kumusta na ang order ko, status ng order',
                'en' => 'You can follow your order anytime under My Orders in your account. You also get a notification each time its status changes. For a custom order, the order page shows your agreement and payments too.',
                'tl' => 'Masusubaybayan po ninyo ang order anumang oras sa My Orders sa inyong account. Makakatanggap din po kayo ng notification tuwing magbabago ang status nito. Sa custom na order, makikita rin po sa pahina ng order ang agreement at mga bayad ninyo.',
                'chips' => 'Contact us, How long does it take',
                'buttons' => [['Go to My Orders', 'Pumunta sa My Orders', 'orders.index']],
            ],

            'luma_change_contact' => [
                'keywords' => 'change my number, change my phone number, update my phone, update my number, change my email, change my address, edit profile, update profile, update contact details, change contact details, wrong number, palitan ang number, baguhin ang contact, palitan ang email',
                'en' => 'You can update your phone number, email and other details on your Profile page. Please keep your phone number correct: for custom designs, our team confirms the details by phone. If you need to change the details of an order you already placed, contact us. {{contact}}',
                'tl' => 'Maaari po ninyong baguhin ang inyong numero, email at iba pang detalye sa pahina ng Profile. Pakitiyak po na tama ang inyong numero: sa custom na disenyo, tinatawagan namin kayo para kumpirmahin ang mga detalye. Kung kailangan pong baguhin ang detalye ng order na nailagay na, makipag-ugnayan po sa amin. {{contact}}',
                'chips' => 'Track my order, Contact us',
                'buttons' => [['Open my Profile', 'Buksan ang Profile', 'profile']],
            ],

            'luma_warranty' => [
                'keywords' => 'warranty, guarantee, guaranty, defect, defective, damaged, repair, after sales, how long is the warranty, may warranty ba, garantiya, sira, ipaayos',
                'en' => 'Warranty and after-sales terms are in our Terms and Conditions. If you have a concern about a piece you received, please contact us and we will help. {{contact}}',
                'tl' => 'Nakasulat po sa aming Terms and Conditions ang warranty at after-sales. Kung may alalahanin po kayo sa piraso na natanggap ninyo, makipag-ugnayan po sa amin at tutulungan namin kayo. {{contact}}',
                'chips' => 'Cancel an order, Contact us',
                'buttons' => [['Read the Terms', 'Basahin ang Terms', 'terms']],
            ],

            'luma_contact_shop' => [
                'keywords' => 'contact, contact us, contact number, phone number, phone, email, call, call you, talk to a person, talk to a human, human, real person, customer service, speak to someone, facebook, messenger, reach you, makausap, kausapin, tumawag, number ninyo',
                'en' => '{{contact}} We are open {{hours}}. {{open_status}}',
                'tl' => '{{contact}} Bukas po kami {{hours}}. {{open_status}}',
                'chips' => 'Shop hours, Custom design, Labor only',
            ],
        ];
    }

    public static function find(?string $name): ?array
    {
        return $name ? (self::all()[$name] ?? null) : null;
    }

    /**
     * Insert every default whose name is not in the table yet.
     *
     * @return array{added: list<string>, skipped: list<string>}
     */
    public static function sync(): array
    {
        $added   = [];
        $skipped = [];

        foreach (self::all() as $name => $def) {
            if (ChatbotIntent::where('category', $name)->exists()) {
                $skipped[] = $name;
                continue;
            }

            ChatbotIntent::create([
                'category'    => $name,
                'keywords'    => $def['keywords'],
                'response'    => $def['en'],
                'answer_tl'   => $def['tl'],
                'suggestions' => $def['chips'] ?? null,
                'is_active'   => true,
            ]);
            $added[] = $name;
        }

        return ['added' => $added, 'skipped' => $skipped];
    }
}
