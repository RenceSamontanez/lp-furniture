<?php

namespace App\Support\Luma;

use App\Models\ChatbotIntent;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * Luma, LP Furniture Shop's AI Assistant: turns one customer message into a structured reply.
 * Everything returned is plain text (the widget escapes it); nothing from the database is trusted as HTML.
 */
final class Luma
{
    /**
     * @return array{text: string, suggestions: list<string>, products: list<array>, cards: list<array>, buttons: list<array{label: string, url: string}>, contact: ?array, lang: string, detected: string}
     */
    public static function reply(string $message): array
    {
        $message = trim($message);
        $lang    = Language::detect($message);

        $intents = ChatbotIntent::query()->where('is_active', true)->get();
        $hit     = IntentMatcher::best($message, $intents);

        if ($hit) {
            return self::finish(self::fromIntent($hit['intent'], $message, $lang));
        }

        $more = self::chips(['View all products', 'Custom design', 'Delivery info'], $lang);

        if ($size = self::sizeAnswer($message, $lang)) {
            return self::finish(self::build($size, $more, 'product_size', $lang));
        }

        if ($budget = self::budgetAnswer($message, $lang)) {
            return self::finish(self::build($budget['text'], $more, 'budget', $lang, cards: $budget['cards'], buttons: $budget['buttons']));
        }

        // "doors" on its own: show the doors (a type we have nothing of is an unanswered question, so it is logged as one)
        if (($type = ProductFinder::detectType($message)) && ProductFinder::available($type)->isNotEmpty()) {
            $reply = self::productsReply(self::build('', self::chips(['Prices', 'Custom design', 'Delivery'], $lang), 'product_type', $lang), $type, false);

            return self::finish($reply);
        }

        return self::fallback($lang);
    }

    /** Adds Luma's closing line, in the language of the answer. The one place it is ever added. */
    public static function finish(array $reply): array
    {
        $closing = config('luma.closing');
        $text    = rtrim($reply['text']);

        foreach ($closing as $line) {
            if (str_ends_with($text, $line)) {
                $text = rtrim(substr($text, 0, -strlen($line)));
            }
        }

        $reply['text'] = $text . "\n\n" . ($closing[$reply['lang']] ?? $closing['en']);

        return $reply;
    }

    /** The quick-reply chips, in Tagalog when the customer wrote in Tagalog (the chip is sent back as their next question). */
    public static function chips(array $chips, string $lang): array
    {
        if ($lang !== Language::TL) {
            return $chips;
        }

        return array_map(fn ($c) => config('luma.chips_tl')[$c] ?? $c, $chips);
    }

    /** The answer of one trained intent, plus the buttons, contact card and live products that go with a built-in one. */
    private static function fromIntent(ChatbotIntent $intent, string $message, string $lang): array
    {
        $answer = $lang === Language::TL && filled($intent->answer_tl) ? $intent->answer_tl : $intent->response;

        $reply = self::build(
            text: self::fill((string) $answer, $lang),
            suggestions: self::chips($intent->suggestions_array, $lang),
            detected: 'intent:' . $intent->id,
            lang: $lang,
        );

        $def = DefaultIntents::find($intent->category);
        if (!$def) {
            return $reply;
        }

        $reply['buttons'] = self::buttons($def['buttons'] ?? [], $lang);

        if (in_array($intent->category, DefaultIntents::CARD, true) && (Contact::phone() || Contact::email())) {
            $reply['contact'] = Contact::card();
        }

        return match ($def['handler'] ?? null) {
            'products' => self::productsReply($reply, ProductFinder::detectType($message), $intent->category === 'luma_ready_made'),
            'range'    => self::rangeReply($reply, ProductFinder::detectType($message)),
            'labor'    => self::laborReply($reply),
            default    => $reply,
        };
    }

    /**
     * Up to four product cards from the live catalog and a "View more products" button. With a furniture type
     * the cards are that type; without one, the featured and newest products. Nothing available: say so.
     */
    private static function productsReply(array $reply, ?string $type, bool $madeToOrder): array
    {
        $lang     = $reply['lang'];
        $tl       = $lang === Language::TL;
        $products = ProductFinder::available($type);

        if ($products->isEmpty()) {
            return self::noProducts($reply, $type);
        }

        if ($type !== null) {
            $label = ProductFinder::label($type, $lang);
            $text  = $tl
                ? "Heto po ang ilan sa aming {$label} sa Catalog. Nasa bawat card ang presyo."
                : "Here are some of our {$label} from the Catalog. The price is on each card.";
            if ($madeToOrder) {
                $text .= $tl
                    ? ' Gawa po kapag may order ang lahat: wala pong ginagawa nang maaga, kaya sisimulan ang paggawa pagka-order at kumpirmasyon ninyo.'
                    : ' Everything is made to order: nothing is built in advance, so production starts as soon as your order is placed and confirmed.';
            }
            $reply['text'] = $text;
        }

        $reply['cards']   = ProductFinder::cards($products);
        $reply['buttons'] = [self::button('View more products', 'Tingnan ang iba pang produkto', ProductFinder::catalogUrl($type), $lang)];

        return $reply;
    }

    /** "Doors range from PHP 5,000 to PHP 18,000", with the cheapest and the most expensive as cards. */
    private static function rangeReply(array $reply, ?string $type): array
    {
        $lang     = $reply['lang'];
        $tl       = $lang === Language::TL;
        $products = ProductFinder::available($type);
        $range    = ProductFinder::range($products);

        if (!$range) {
            return self::noProducts($reply, $type);
        }

        $min = ProductFinder::money($range['min']);
        $max = ProductFinder::money($range['max']);

        if ($type === null) {
            $reply['text'] = $tl
                ? "Sa buong Catalog namin, ang presyo ay mula {$min} hanggang {$max}. Sabihin lang po ang uri ng furniture (halimbawa pinto o mesa) para sa saklaw ng presyo nito."
                : "Across our whole Catalog, prices go from {$min} to {$max}. Tell me a furniture type (for example door or table) for that type's range.";
        } else {
            $label = ProductFinder::label($type, $lang);

            if ($range['count'] === 1) {
                $name  = $range['cheapest']->product_name;
                $one   = $tl ? preg_replace('/^mga /', '', $label) : $type;
                $reply['text'] = $tl
                    ? "Isa lang po ang {$one} sa Catalog ngayon: {$name}, sa {$min}."
                    : "We have just one {$one} in the Catalog right now: {$name}, at {$min}.";
            } elseif ($range['min'] === $range['max']) {
                $reply['text'] = $tl
                    ? "Lahat po ng {$label} namin sa Catalog ay {$min}."
                    : 'All our ' . $label . " in the Catalog are {$min}.";
            } else {
                $reply['text'] = $tl
                    ? "Ang {$label} po ay mula {$min} hanggang {$max}."
                    : ucfirst($label) . " range from {$min} to {$max}.";
            }
        }

        $shown = $range['count'] === 1
            ? collect([$range['cheapest']])
            : collect([$range['cheapest'], $range['dearest']]);

        $reply['cards']   = ProductFinder::cards($shown);
        $reply['buttons'] = [self::button('View more products', 'Tingnan ang iba pang produkto', ProductFinder::catalogUrl($type), $lang)];

        return $reply;
    }

    /** Labor only: the contact card sits under the text; with no phone or email set, the text says "through our website". */
    private static function laborReply(array $reply): array
    {
        if ($reply['contact'] === null) {
            $reply['text'] = rtrim($reply['text'], ': ') . '. ' . Contact::sentence($reply['lang']);
        }

        return $reply;
    }

    /** Nothing available to show: say so, point to the Customize page and give the contact details. */
    private static function noProducts(array $reply, ?string $type): array
    {
        $lang = $reply['lang'];
        $tl   = $lang === Language::TL;

        if ($type !== null) {
            $label = ProductFinder::label($type, $lang);
            $text  = $tl
                ? "Wala pong available na {$label} sa Catalog ngayon."
                : "We don't have any {$label} available in the Catalog right now.";
        } else {
            $text = $tl ? 'Wala pong available na produkto sa Catalog ngayon.' : "We don't have any products available in the Catalog right now.";
        }

        $text .= $tl
            ? ' Maaari po kayong magpagawa ng sarili ninyong disenyo sa Customize page, o makipag-ugnayan sa amin. '
            : ' You can have one made for you on our Customize page, or contact us. ';

        $reply['text']    = $text . Contact::sentence($lang);
        $reply['cards']   = [];
        $reply['buttons'] = Route::has('design.index')
            ? [self::button('Customize a design', 'Mag-customize ng disenyo', route('design.index', [], false), $lang)]
            : [];
        $reply['contact'] = (Contact::phone() || Contact::email()) ? Contact::card() : null;

        return $reply;
    }

    private static function button(string $en, string $tl, string $url, string $lang): array
    {
        return ['label' => $lang === Language::TL ? $tl : $en, 'url' => $url];
    }

    /**
     * Link buttons from [labelEn, labelTl, routeName] triples. A route that does not exist (yet) is left out;
     * "catalog" opens the full catalog.
     *
     * @return list<array{label: string, url: string}>
     */
    private static function buttons(array $defs, string $lang): array
    {
        $out = [];
        foreach ($defs as [$en, $tl, $route]) {
            if (!Route::has($route)) {
                continue;
            }
            $out[] = self::button($en, $tl, route($route, [], false), $lang);
        }

        return $out;
    }

    /** The friendly dead-end answer: four topics to pick and how to reach the shop. */
    public static function fallback(string $lang): array
    {
        $text = $lang === Language::TL
            ? 'Pasensya na po, hindi ko po masagot iyan ngayon, pero matutulungan ko po kayo sa mga paksang ito. Pumili po sa ibaba o subukang itanong sa ibang paraan. ' . Contact::sentence($lang)
            : "I couldn't quite find an answer to that, but I can help with these topics. Pick one below or try asking in a different way. " . Contact::sentence($lang);

        return self::finish(self::build($text, self::chips(['How to order', 'Custom design', 'Prices', 'Delivery'], $lang), 'unmatched', $lang));
    }

    /** Fills the {{tokens}} (contact details, hours, estimates...) as plain text. See Facts. */
    public static function fill(string $text, string $lang): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        $tokens = Facts::tokens($lang);

        return str_replace(array_keys($tokens), array_values($tokens), $text);
    }

    private static function build(string $text, array $suggestions, string $detected, string $lang, array $cards = [], array $buttons = []): array
    {
        return [
            'text'        => $text,
            'suggestions' => array_values(array_filter($suggestions, fn ($s) => $s !== '')),
            'products'    => [],
            'cards'       => $cards,
            'buttons'     => $buttons,
            'contact'     => null,
            'lang'        => $lang,
            'detected'    => $detected,
        ];
    }

    /** A question about the size of one of our products, from its stored size, as length x width x height. */
    private static function sizeAnswer(string $message, string $lang): ?string
    {
        if (!preg_match('/\b(sizes?|dimensions?|measurements?|how (big|large|long|tall|wide))\b/i', $message)) {
            return null;
        }

        $lower = mb_strtolower($message);
        $named = Product::available()->whereNotNull('dimensions')->get()
            ->first(fn ($p) => $p->product_name && str_contains($lower, mb_strtolower($p->product_name)));

        if ($named && $named->dimensions_label) {
            return $lang === Language::TL
                ? "Ang {$named->product_name} po ay {$named->dimensions_label}, sa pagkakasunod na haba x lapad x taas."
                : "The {$named->product_name} measures {$named->dimensions_label}, as length x width x height.";
        }

        return null;
    }

    /**
     * "5000", "PHP 5,000" or "5k": pieces at or under that amount (of the furniture type, when one is named).
     * A small bare number is not a budget.
     *
     * @return array{text: string, cards: list<array>, buttons: list<array>}|null
     */
    private static function budgetAnswer(string $message, string $lang): ?array
    {
        if (!preg_match_all('/(?:(?:php|₱|pesos?)\s*)?(\d[\d,]*(?:\.\d+)?)\s*(k\b|pesos?\b|php\b|₱)?/iu', $message, $all, PREG_SET_ORDER)) {
            return null;
        }

        $budget = 0;
        foreach ($all as $m) {
            $n = (float) str_replace(',', '', $m[1]);
            if (strtolower($m[2] ?? '') === 'k') {
                $n *= 1000;
            }
            $marked = preg_match('/^(php|₱|pesos?)/iu', $m[0]) || !empty($m[2]);
            if ($n >= 1000 || ($marked && $n > 0)) {
                $budget = max($budget, (int) $n);
            }
        }
        if ($budget <= 0) {
            return null;
        }

        $type  = ProductFinder::detectType($message);
        $found = ProductFinder::available($type)
            ->filter(fn (Product $p) => ProductFinder::price($p) <= $budget)
            ->sortByDesc(fn (Product $p) => ProductFinder::price($p))
            ->values();
        if ($found->isEmpty()) {
            return null;
        }

        return [
            'text'    => $lang === Language::TL
                ? 'Heto po ang ilang piraso na pasok sa budget ninyong ' . ProductFinder::money($budget) . ':'
                : 'Great! Here are some pieces within your ' . ProductFinder::money($budget) . ' budget:',
            'cards'   => ProductFinder::cards($found),
            'buttons' => [self::button('View more products', 'Tingnan ang iba pang produkto', ProductFinder::catalogUrl($type, $budget), $lang)],
        ];
    }
}
