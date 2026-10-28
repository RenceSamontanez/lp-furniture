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
    public const CARD = ['luma_labor_only', 'luma_about_business', 'luma_shop_open'];

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

        return array_merge([
            'luma_greeting' => [
                'keywords' => 'hello, hi, hey, kumusta, good morning, good afternoon, good evening, magandang umaga, magandang hapon, magandang gabi, who are you, who is luma, what is your name, sino ka, sino si luma, ano pangalan mo, what can you do, luma',
                'en' => "Hi, I'm Luma, LP Furniture's shop assistant.\n\nI can help with prices, ready-made designs, custom designs, wood choices, delivery, payment and your orders. What would you like to know?",
                'tl' => "Kumusta po! Ako po si Luma, ang shop assistant ng LP Furniture.\n\nMatutulungan ko po kayo sa presyo, ready-made na disenyo, custom na disenyo, kahoy, delivery, bayad at inyong mga order. Ano po ang gusto ninyong malaman?",
                'chips' => 'Prices, Custom design, Delivery, Contact us',
            ],

            'luma_products_cost' => [
                'keywords' => 'price, product price, furniture price, how much furniture, price of your products, price of your services, services price, magkano ang serbisyo',
                'en' => "Every product in our Catalog shows its price on its page.\n\nCustom designs are priced by the shop in a written quote after we call you. Nothing is charged until you accept it.\n\nHere are some of our products. Tell me the type (for example door, table or bed) and I'll show you those.",
                'tl' => "Nakasulat po ang presyo ng bawat produkto sa Catalog.\n\nAng custom na disenyo ay pinepresyuhan ng shop sa nakasulat na quote pagkatapos ka naming tawagan. Walang sisingilin hangga't hindi ninyo ito tinatanggap.\n\nHeto po ang ilan sa aming mga produkto. Sabihin lang po ang uri (halimbawa pinto, mesa o kama) at ipapakita ko po.",
                'chips' => 'Price range, Custom design, Labor only, Payment options',
                'handler' => 'products',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_ready_made' => [
                'keywords' => implode(', ', $readyMadeKeywords),
                'en' => "Yes. Our Catalog has ready-made designs for many furniture types.\n\nEverything is made to order, so nothing is built in advance. Production starts once your order is placed and confirmed.",
                'tl' => "Opo. May mga ready-made na disenyo sa aming Catalog para sa iba't ibang uri ng furniture.\n\nGawa po kapag may order ang lahat, kaya wala pong ginagawa nang maaga. Sisimulan ang paggawa pagka-order at kumpirmasyon ninyo.",
                'chips' => 'Prices, Price range, Custom design, How long does it take',
                'handler' => 'products',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_about_business' => [
                'keywords' => 'about your business, tell me about your business, about lp furniture, about the shop, about us, who is lp furniture, what is lp furniture, what do you do, what do you sell, ano ang lp furniture, tungkol sa negosyo, anong negosyo ninyo, kwento ng shop',
                'en' => "LP Furniture is a furniture shop in San Juan, Batangas. We make furniture to order: ready-made designs from our Catalog, and custom pieces designed with you.\n\nWe are open {{hours}}. {{contact}}",
                'tl' => "Ang LP Furniture po ay isang furniture shop sa San Juan, Batangas. Gawa po kapag may order ang aming furniture: mga ready-made na disenyo sa Catalog, at custom na piraso na idinisenyo kasama kayo.\n\nBukas po kami {{hours}}. {{contact}}",
                'chips' => 'Prices, Custom design, Shop hours, Contact us',
                'buttons' => [['View products', 'Tingnan ang mga produkto', 'catalog']],
            ],

            'luma_shop_open' => [
                'keywords' => 'are you open, still open, open now, open today, are you active, still active, is the shop active, store hours, shop hours, business hours, opening hours, office hours, what time do you open, what time do you close, anong oras, oras ng shop, bukas pa ba, bukas kayo, active pa ba, active pa kayo, open pa ba, working hours',
                'en' => "Yes, the shop is open for business. Our hours are {{hours}} (Philippine time).\n\n{{open_status}}",
                'tl' => "Opo, bukas po ang shop. Ang oras po namin ay {{hours}} (oras sa Pilipinas).\n\n{{open_status}}",
                'chips' => 'Contact us, Prices, Custom design',
            ],

            'luma_price_list' => [
                'keywords' => 'price list, list of prices, product list, price catalog, price guide, listahan ng presyo, catalog, all products, show products',
                'en' => "We don't keep one fixed price list, because every piece is made to order.\n\nThe price of each Catalog product is on its page. Custom designs are quoted by the shop after we call you.\n\nHere are some products. Tell me a type, like door or table, for more.",
                'tl' => "Wala po kaming iisang nakapirming price list, dahil gawa kapag may order ang bawat piraso.\n\nNasa pahina ng bawat produkto sa Catalog ang presyo nito. Ang custom na disenyo ay kino-quote ng shop pagkatapos ka naming tawagan.\n\nHeto po ang ilang produkto. Sabihin lang po ang uri, gaya ng pinto o mesa, para sa iba pa.",
                'chips' => 'Price range, Custom design, Labor only',
                'handler' => 'products',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_price_range' => [
                'keywords' => 'price range, range, cheapest, most expensive, lowest price, highest price, starting price, how cheap, pinakamura, pinakamahal, saklaw ng presyo, hanggang magkano, mula magkano',
                'en' => "Tell me the furniture type (for example door, table or bed) and I'll show you the lowest and highest price in our Catalog.",
                'tl' => "Sabihin lang po ang uri ng furniture (halimbawa pinto, mesa o kama) at ipapakita ko po ang pinakamababa at pinakamataas na presyo sa aming Catalog.",
                'chips' => 'Prices, Custom design, Labor only',
                'handler' => 'range',
                'buttons' => [['View more products', 'Tingnan ang iba pang produkto', 'catalog']],
            ],

            'luma_how_long' => [
                'keywords' => 'how long, how many days, how many weeks, when will it be done, when will it be ready, when is it ready, how fast, turnaround, production time, processing time, lead time, gaano katagal, ilang araw, ilang linggo, kailan matatapos, kailan magiging ready, gaano ka bilis',
                'en' => "A piece usually takes roughly {{days}} days to finish, for any furniture type. That is an estimate. The exact expected ready date is in your quote.\n\nEverything is made to order, so nothing is built in advance. Production starts once your order is placed and confirmed, which is once the deposit is received.",
                'tl' => "Karaniwang aabutin ng humigit-kumulang {{days}} araw ang paggawa, para sa anumang uri ng furniture. Pagtataya lamang po iyan. Ang eksaktong petsa kung kailan ito ready ay nasa quote ninyo.\n\nGawa po kapag may order ang lahat, kaya wala pong ginagawa nang maaga. Sisimulan ang paggawa pagka-order at kumpirmasyon ninyo, ibig sabihin kapag natanggap na ang deposito.",
                'chips' => 'Made to order?, Custom design, Payment options',
            ],

            'luma_made_to_order' => [
                'keywords' => 'ready made or made to order, made to order, built in advance, build in advance, do you build in advance, are your items ready made, when start, when do you start making, when do you start, kailan sisimulan, simulan ang paggawa, ready stock, nakahanda na ba',
                'en' => "Everything is made to order. Nothing is built in advance.\n\nProduction starts once your order is placed and confirmed, which is once the deposit is received. Finishing usually takes roughly {{days}} days. That is an estimate, and the exact expected ready date is in your quote.",
                'tl' => "Gawa po kapag may order ang lahat. Wala pong ginagawa nang maaga.\n\nSisimulan ang paggawa pagka-order at kumpirmasyon ninyo, ibig sabihin kapag natanggap na ang deposito. Karaniwang aabutin ito ng humigit-kumulang {{days}} araw. Pagtataya lamang po iyan, at ang eksaktong petsa ay nasa quote ninyo.",
                'chips' => 'How long does it take, Payment options, Custom design',
            ],

            'luma_labor_only' => [
                'keywords' => 'labor only, labor, i will provide the wood, i have my own wood, own wood, my own wood, bring my own wood, supply my own wood, wood provided, wood is provided, customer supplied wood, sariling kahoy, may kahoy na ako, workmanship only, magkano ang labor',
                'en' => "Yes. We can build with wood you provide (labor only).\n\nLabor-only prices vary by furniture type, so the owner discusses them with you by phone. Please send a request or contact us:",
                'tl' => "Opo. Maaari po kaming gumawa gamit ang kahoy na ibibigay ninyo (labor lang).\n\nMagkakaiba po ang presyo ng labor depende sa uri ng furniture, kaya ang may-ari mismo ang makikipag-usap sa inyo sa telepono. Magpadala po ng request o makipag-ugnayan sa amin:",
                'chips' => 'Custom design, Wood choices, Contact us',
                'handler' => 'labor',
                'buttons' => [['Send a request', 'Magpadala ng request', 'services.request']],
            ],

            'luma_free_delivery' => [
                'keywords' => 'free delivery, delivery free, free shipping, is delivery free, libre delivery, libre ang delivery, delivery area, delivery locations, which locations, do you deliver to, how far do you deliver, saan libre ang delivery, hatid',
                'en' => "Delivery is free for locations close to San Juan, Batangas: {{free_area}}.\n\nBeyond that area, {{fee_note}} I can't list every town or barangay here, so please contact us to check an exact location.\n\n{{contact}}",
                'tl' => "Libre po ang delivery sa mga lugar na malapit sa San Juan, Batangas: {{free_area}}.\n\nKung lampas po sa lugar na iyon, {{fee_note}} Hindi ko po mailista ang bawat bayan o barangay dito, kaya makipag-ugnayan po sa amin para malaman ang eksaktong lugar.\n\n{{contact}}",
                'chips' => 'Prices, Contact us, Payment options',
            ],

            'luma_delivery_fee' => [
                'keywords' => 'delivery, delivery fee, delivery price, delivery charge, delivery cost, shipping fee, magkano ang delivery, pickup, pick up, pick up at the shop',
                'en' => "Yes, we deliver. You can also pick up at the shop. You choose at checkout.\n\n- **Free delivery** for locations close to San Juan, Batangas ({{free_area}}).\n- **Delivery beyond that area:** {{fee_note}}\n- **Pickup** at our shop in San Juan, Batangas.\n\n> You get a notification when your order is ready and when it is out for delivery.",
                'tl' => "Opo, nagde-deliver po kami. Maaari rin kayong mag-pickup sa shop. Pipili po kayo sa checkout.\n\n- **Libreng delivery** sa mga lugar na malapit sa San Juan, Batangas ({{free_area}}).\n- **Delivery na lampas sa lugar na iyon:** {{fee_note}}\n- **Pickup** sa aming shop sa San Juan, Batangas.\n\n> Makakatanggap po kayo ng notification kapag ready na ang order at kapag nasa labas na ito para sa delivery.",
                'chips' => 'Free delivery, Payment options, Track my order',
            ],

            'luma_ai_design_steps' => [
                'keywords' => 'how does ai design work, ai design, custom design, custom order, customize, how to customize, how to request a custom design, step by step, steps for custom design, custom furniture, i want a custom, can i customize, design tool, generate design, custom na disenyo, paano ang custom design, gusto kong ipagawa, ipagawa',
                'en' => "Custom design works in these steps:\n\n1. **Choose the furniture type.**\n2. **Enter the size** as Length x Width x Height.\n3. **Choose the wood.**\n4. **Describe what you want.** Our Customize tool generates pictures.\n5. **Review them and pick one**, then send it to our team.\n6. **We call you** to talk about it.\n7. **You receive a quote.** If you agree, you accept the agreement.\n8. **You pay to start.**\n\nEverything is made to order. Production starts once your order is placed and confirmed, and finishing usually takes roughly {{days}} days. That is an estimate, and the exact date is in your quote.\n\nThe full steps are in our guide.",
                'tl' => "Ganito po ang custom na disenyo:\n\n1. **Piliin ang uri ng furniture.**\n2. **Ilagay ang sukat** bilang Haba x Lapad x Taas.\n3. **Piliin ang kahoy.**\n4. **Ilarawan ang gusto ninyo.** Gagawa ng mga larawan ang aming Customize tool.\n5. **Tingnan ang mga ito at pumili ng isa**, saka ipadala sa aming team.\n6. **Tatawagan namin kayo** para pag-usapan.\n7. **Makakatanggap kayo ng quote.** Kung sang-ayon po kayo, tatanggapin ninyo ang agreement.\n8. **Magbabayad kayo para masimulan.**\n\nGawa po kapag may order ang lahat. Sisimulan ang paggawa pagka-order at kumpirmasyon ninyo, at karaniwang aabutin ito ng humigit-kumulang {{days}} araw. Pagtataya lamang po iyan, at ang eksaktong petsa ay nasa quote.\n\nNasa aming gabay ang kumpletong mga hakbang.",
                'chips' => 'Measuring guide, Wood choices, How long does it take, Labor only',
                'buttons' => [['Open the guide', 'Buksan ang gabay', 'guide'], ['Start a custom design', 'Magsimula ng custom na disenyo', 'design.index']],
            ],

            'luma_dimensions' => [
                'keywords' => 'how to measure, how do i measure, measuring guide, how to know the size, how to know the dimensions, what are the dimensions, what size, what sizes, length width height, length x width x height, which side is the length, i dont know the size, paano sukatin, paano malaman ang sukat, paano ang sukat, anong sukat',
                'en' => "Size is always written as Length x Width x Height, in the unit you choose.\n\n- **Length** is the longest horizontal side.\n- **Width** is the other horizontal side.\n- **Height** is from the floor up.\n\nOur guide shows how to measure each type of furniture, with pictures. If you are not sure, a rough size is fine. We will confirm it when we call you.",
                'tl' => "Laging nakasulat ang sukat bilang Haba x Lapad x Taas, sa unit na pipiliin ninyo.\n\n- Ang **Haba** ay ang pinakamahabang gilid na pahalang.\n- Ang **Lapad** ay ang isa pang pahalang na gilid.\n- Ang **Taas** ay mula sa sahig pataas.\n\nIpinapakita po ng aming gabay kung paano sukatin ang bawat uri ng furniture, may mga larawan. Kung hindi po kayo sigurado, ayos lang ang tantiya. Kukumpirmahin namin ito kapag tinawagan namin kayo.",
                'chips' => 'Custom design, Wood choices, Contact us',
                'buttons' => [['Open the measuring guide', 'Buksan ang gabay sa pagsukat', 'guide']],
            ],

            'luma_best_wood' => [
                'keywords' => 'which wood is best, which wood best, best wood, wood best, what wood, wood types, types of wood, wood choice, wood options, gmelina, mahogany, acacia, special request wood, other wood, anong kahoy, ano ang magandang kahoy, alin ang pinakamagandang kahoy, wood',
                'en' => "We build with Gmelina, Mahogany and Acacia, or you can make a special request.\n\n- **Gmelina:** the budget-friendly choice. It takes paint or stain well, so it suits indoor pieces that will not be heavily used.\n- **Mahogany:** a classic reddish-brown. A good choice for a finer, longer-lasting piece, and it usually costs more.\n- **Acacia:** hard and durable. A good pick for tables, chairs and anything used every day.\n\nFor another kind of wood, choose Special request. The team confirms what is possible and the price when we call you.",
                'tl' => "Gumagawa po kami gamit ang Gmelina, Mahogany at Acacia, o maaari kayong mag-special request.\n\n- **Gmelina:** ang pinakamurang opsyon. Maganda ang tanggap nito sa pintura o stain, kaya bagay sa mga gamit sa loob ng bahay na hindi masyadong madalas gamitin.\n- **Mahogany:** klasikong mapula-pulang kayumanggi. Magandang piliin para sa mas pino at pangmatagalang piraso, at karaniwang mas mahal.\n- **Acacia:** matigas at matibay. Magandang piliin para sa mesa, upuan at mga gamit araw-araw.\n\nPara sa ibang uri ng kahoy, piliin ang Special request. Kukumpirmahin ng team ang posibilidad at presyo kapag tinawagan namin kayo.",
                'chips' => 'Custom design, Labor only, Contact us',
            ],

            'luma_payment_methods' => [
                'keywords' => 'payment method, payment methods, payment options, payment option, how to pay, ways to pay, how do i pay, pay, gcash, stripe, credit card, debit card, cash, cash on delivery, cash on pickup, cod, paraan ng bayad, paano magbayad, anong payment, paano bayaran',
                'en' => "You can pay in three ways:\n\n- **Card** through Stripe.\n- **GCash** through PayMongo.\n- **Cash** when you pick up or when it is delivered.\n\nYou choose at checkout. An online payment is confirmed as soon as it goes through.",
                'tl' => "Maaari po kayong magbayad sa tatlong paraan:\n\n- **Card** sa pamamagitan ng Stripe.\n- **GCash** sa pamamagitan ng PayMongo.\n- **Cash** kapag kukunin o ide-deliver na ang order.\n\nPipili po kayo sa checkout. Nakukumpirma po ang online na bayad sa sandaling matagumpay itong maiproseso.",
                'chips' => 'Deposit and installment, Cancel an order, Track my order',
            ],

            'luma_service_invoice_payment' => [
                'keywords' => 'pay my invoice, pay the invoice, pay an invoice, pay my service invoice, how to pay my invoice, how do i pay my invoice, invoice payment, service invoice, other services invoice, pay for repair, pay for my repair, pay for labor, pay for the repair, pay for the service, pay for my service, half now half later, half payment, half and half, how to pay for other services, bayaran ang invoice, bayad sa invoice, paano bayaran ang invoice, bayad sa repair, bayad sa serbisyo',
                'en' => "For Other Services (repairs and labor-only work), we send you an invoice. You can see it in your account under Service Request.\n\n- **Online:** pay by GCash or card.\n- **In person:** pay cash, and our team records it.\n- **Full or half:** pay in full or, for some invoices, half now and half when the service is done.\n\nThe invoice shows which option applies. The second half becomes payable once we mark the service as done, and the invoice shows the status of your payments. {{contact}}",
                'tl' => "Sa Other Services (pagpapaayos at labor lang), nagpapadala po kami ng invoice. Makikita ninyo ito sa inyong account sa Service Request.\n\n- **Online:** magbayad sa GCash o card.\n- **Nang personal:** magbayad ng cash, at ire-record po ito ng aming team.\n- **Buo o kalahati:** magbayad nang buo o, sa ilang invoice, kalahati ngayon at kalahati kapag tapos na ang serbisyo.\n\nNakasulat sa invoice kung alin ang para sa inyo. Magiging bayad na ang ikalawang kalahati kapag minarkahan na naming tapos na ang serbisyo, at makikita sa invoice ang status ng inyong mga bayad. {{contact}}",
                'chips' => 'Labor only, Payment options, Contact us',
                'buttons' => [['Open my service requests', 'Buksan ang aking service requests', 'services.request']],
            ],

            'luma_deposit_rules' => [
                'keywords' => 'deposit, down payment, reservation, installment, installments, pay in parts, split payment, partial payment, pay later, layaway, how much deposit, minimum deposit, magkano ang down, hulugan, hulog, pwede bang hulugan',
                'en' => "For Catalog orders you can pay in full or in installments.\n\n- **Down payment:** the first payment is {{catalog_down}}% of the total.\n- **The rest:** split over {{months}} months, every {{frequency}} days.\n\nFor custom orders, the deposit is written in your quote and is at least {{deposit_min}}% of the total. Production starts once the deposit is received.",
                'tl' => "Sa mga order mula sa Catalog, maaari pong magbayad nang buo o hulugan.\n\n- **Down payment:** ang unang bayad ay {{catalog_down}}% ng kabuuan.\n- **Ang natitira:** hahatiin sa {{months}} buwan, kada {{frequency}} araw.\n\nSa custom na order, nakasulat sa quote ang deposito at hindi bababa sa {{deposit_min}}% ng kabuuan. Sisimulan ang paggawa kapag natanggap na ang deposito.",
                'chips' => 'Payment options, How long does it take, Cancel an order',
            ],

            'luma_cancel_refund' => [
                'keywords' => 'cancel, cancel order, cancel my order, cancellation, refund, return, change my order, i made a mistake, pwede ba icancel, ibalik ang pera, magrefund',
                'en' => "Our cancellation and refund rules are in the Terms and Conditions.\n\nEvery piece is made to order, so please contact us as soon as you can if you need to change or cancel an order. {{contact}}",
                'tl' => "Nakasulat po sa Terms and Conditions ang aming patakaran sa pagkansela at refund.\n\nGawa po kapag may order ang bawat piraso, kaya makipag-ugnayan po sa amin sa lalong madaling panahon kung kailangan ninyong baguhin o kanselahin ang order. {{contact}}",
                'chips' => 'Payment options, Track my order, Contact us',
                'buttons' => [['Read the Terms', 'Basahin ang Terms', 'terms']],
            ],

            'luma_track_order' => [
                'keywords' => 'track, tracking, track my order, order status, where is my order, my orders, check my order, order update, update on my order, saan na ang order ko, kumusta na ang order ko, status ng order',
                'en' => "You can follow your order anytime under My Orders in your account. You also get a notification each time its status changes.\n\nFor a custom order, the order page shows your agreement and payments too.",
                'tl' => "Masusubaybayan po ninyo ang order anumang oras sa My Orders sa inyong account. Makakatanggap din po kayo ng notification tuwing magbabago ang status nito.\n\nSa custom na order, makikita rin po sa pahina ng order ang agreement at mga bayad ninyo.",
                'chips' => 'Contact us, How long does it take',
                'buttons' => [['Go to My Orders', 'Pumunta sa My Orders', 'orders.index']],
            ],

            'luma_change_contact' => [
                'keywords' => 'change my number, change my phone number, update my phone, update my number, change my email, change my address, edit profile, update profile, update contact details, change contact details, wrong number, palitan ang number, baguhin ang contact, palitan ang email',
                'en' => "You can update your phone number, email and other details on your Profile page.\n\nPlease keep your phone number correct. For custom designs, our team confirms the details by phone. To change the details of an order you already placed, contact us. {{contact}}",
                'tl' => "Maaari po ninyong baguhin ang inyong numero, email at iba pang detalye sa pahina ng Profile.\n\nPakitiyak po na tama ang inyong numero. Sa custom na disenyo, tinatawagan namin kayo para kumpirmahin ang mga detalye. Kung kailangan pong baguhin ang detalye ng order na nailagay na, makipag-ugnayan po sa amin. {{contact}}",
                'chips' => 'Track my order, Contact us',
                'buttons' => [['Open my Profile', 'Buksan ang Profile', 'profile']],
            ],

            'luma_warranty' => [
                'keywords' => 'warranty, guarantee, guaranty, defect, defective, damaged, repair, after sales, how long is the warranty, may warranty ba, garantiya, sira, ipaayos',
                'en' => "Warranty and after-sales terms are in our Terms and Conditions.\n\nIf you have a concern about a piece you received, please contact us and we will help. {{contact}}",
                'tl' => "Nakasulat po sa aming Terms and Conditions ang warranty at after-sales.\n\nKung may alalahanin po kayo sa piraso na natanggap ninyo, makipag-ugnayan po sa amin at tutulungan namin kayo. {{contact}}",
                'chips' => 'Cancel an order, Contact us',
                'buttons' => [['Read the Terms', 'Basahin ang Terms', 'terms']],
            ],

            'luma_contact_shop' => [
                'keywords' => 'contact, contact us, contact number, phone number, phone, email, call, call you, talk to a person, talk to a human, human, real person, customer service, speak to someone, facebook, messenger, reach you, makausap, kausapin, tumawag, number ninyo',
                'en' => "You can reach our team here:\n\n[[contact]]\n\nOr keep chatting with me. I can help with most questions.",
                'tl' => "Maaari po ninyong makontak ang aming team dito:\n\n[[contact]]\n\nO magtanong lang po kayo sa akin. Matutulungan ko po kayo sa karamihan ng tanong.",
                'chips' => 'Shop hours, Custom design, Labor only',
            ],
        ], self::otherServices());
    }

    /** True when the reply for this intent carries the shop's contact card. */
    public static function isCard(?string $name): bool
    {
        return in_array($name, self::CARD, true) || ($name && !empty(OtherServicesKnowledge::nodes()[$name]['card']));
    }

    /**
     * The Other Services knowledge nodes (luma_os_*) as default intents. The node's own title is always a keyword, in
     * English and Tagalog, because its follow-up chip sends the title back as the customer's next message.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function otherServices(): array
    {
        $titles = OtherServicesKnowledge::titles();
        $out    = [];

        foreach (OtherServicesKnowledge::nodes() as $id => $node) {
            $out[$id] = [
                'keywords' => $node['kw'] . ', ' . $node['title'] . ', ' . $node['title_tl'],
                'en'       => $node['en'],
                'tl'       => $node['tl'],
                'chips'    => implode(', ', array_map(fn ($child) => $titles[$child]['en'], $node['next'] ?? [])),
                'buttons'  => $node['buttons'] ?? [],
                'handler'  => $node['handler'] ?? null,
                'lead'     => $node['lead'] ?? false,
            ];
        }

        return $out;
    }

    /**
     * Gives the stored rows the new wording, but ONLY where the stored answer is still exactly the old built-in text
     * (database/data/luma-previous-defaults.json) so an admin's own edit is never overwritten. Covers every built-in
     * intent by category and the 20 hand-seeded rows without a category (database/data/luma-legacy-rewrites.php).
     *
     * @return list<string> one line per changed row: "<name> (en|tl)"
     */
    public static function refreshUntouched(): array
    {
        $previous = json_decode((string) @file_get_contents(database_path('data/luma-previous-defaults.json')), true) ?: [];
        $changed  = [];

        foreach (self::all() as $name => $def) {
            $old = $previous[$name] ?? null;
            $row = $old ? ChatbotIntent::where('category', $name)->first() : null;
            if (!$row) {
                continue;
            }
            $update = [];
            if ($row->response === $old['en'] && $def['en'] !== $old['en']) {
                $update['response'] = $def['en'];
                $changed[] = "{$name} (en)";
            }
            if ((string) $row->answer_tl === (string) $old['tl'] && $def['tl'] !== $old['tl']) {
                $update['answer_tl'] = $def['tl'];
                $changed[] = "{$name} (tl)";
            }
            if ($update) {
                $row->timestamps = false;   // the matcher breaks a tie in favour of the newer row: rewording must not change who wins
                $row->update($update);
            }
        }

        $rewrites = require database_path('data/luma-legacy-rewrites.php');
        foreach ($previous['__legacy'] ?? [] as $old) {
            $new = $rewrites[$old['keywords']] ?? null;
            if ($new === null) {
                continue;
            }
            $rows = ChatbotIntent::where('keywords', $old['keywords'])->where('response', $old['response'])
                ->where(fn ($q) => $q->whereNull('category')->orWhere('category', ''))->get();
            foreach ($rows as $row) {
                $row->timestamps = false;
                $row->update(['response' => $new]);
                $changed[] = 'legacy #' . $row->id . ' (' . mb_substr($old['keywords'], 0, 30) . ')';
            }
        }

        return $changed;
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
