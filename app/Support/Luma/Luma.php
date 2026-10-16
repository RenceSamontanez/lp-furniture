<?php

namespace App\Support\Luma;

use App\Models\ChatbotIntent;
use App\Models\Product;
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
            return self::finish(self::build($budget['text'], $more, 'budget', $lang, $budget['products']));
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

        $reply['text'] = $text . "

" . ($closing[$reply['lang']] ?? $closing['en']);

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

    /** The answer of one trained intent, plus the buttons and contact card that go with a built-in one. */
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
        if ($def) {
            $reply['buttons'] = self::buttons($def['buttons'] ?? [], $lang);

            if (in_array($intent->category, DefaultIntents::CARD, true) && (Contact::phone() || Contact::email())) {
                $reply['contact'] = Contact::card();
            }
        }

        return $reply;
    }

    /**
     * Link buttons from [labelEn, labelTl, routeName] triples. A route that does not exist (yet) is left out.
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
            $out[] = ['label' => $lang === Language::TL ? $tl : $en, 'url' => route($route, [], false)];
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

    private static function build(string $text, array $suggestions, string $detected, string $lang, array $products = []): array
    {
        return [
            'text'        => $text,
            'suggestions' => array_values(array_filter($suggestions, fn ($s) => $s !== '')),
            'products'    => $products,
            'cards'       => [],
            'buttons'     => [],
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

    /** "5000", "PHP 5,000" or "5k": pieces at or under that amount. A small bare number is not a budget. */
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

        $found = Product::available()->where('price', '<=', $budget)->orderBy('price', 'desc')->take((int) config('luma.max_cards', 4))->get();
        if ($found->isEmpty()) {
            return null;
        }

        return [
            'text'     => $lang === Language::TL
                ? 'Heto po ang ilang piraso na pasok sa budget ninyong PHP ' . number_format($budget) . ':'
                : 'Great! Here are some pieces within your PHP ' . number_format($budget) . ' budget:',
            'products' => $found->map(fn ($p) => ['id' => $p->id, 'product_name' => $p->product_name, 'price' => $p->price])->all(),
        ];
    }
}
