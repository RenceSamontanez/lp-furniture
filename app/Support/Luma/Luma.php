<?php

namespace App\Support\Luma;

use App\Models\ChatbotIntent;
use App\Models\Product;

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
        $lang    = 'en';

        $intents = ChatbotIntent::query()->where('is_active', true)->get();
        $hit     = IntentMatcher::best($message, $intents);

        if ($hit) {
            $intent = $hit['intent'];

            return self::build(
                text: self::fill((string) $intent->response, $lang),
                suggestions: $intent->suggestions_array,
                detected: 'intent:' . $intent->id,
                lang: $lang,
            );
        }

        if ($size = self::sizeAnswer($message)) {
            return self::build($size, ['View all products', 'Custom design', 'Delivery info'], 'product_size', $lang);
        }

        if ($budget = self::budgetAnswer($message)) {
            return self::build($budget['text'], ['View all products', 'Custom design', 'Delivery info'], 'budget', $lang, $budget['products']);
        }

        return self::fallback($lang);
    }

    /** The friendly dead-end answer: four topics to pick and how to reach the shop. */
    public static function fallback(string $lang): array
    {
        $text = "I couldn't quite find an answer to that, but I can help with these topics. Pick one below or try asking in a different way. " . Contact::sentence($lang);

        return self::build($text, ['How to order', 'Custom design', 'Prices', 'Delivery'], 'unmatched', $lang);
    }

    /** {{phone}} / {{email}} / {{facebook}} / {{contact}} from Contact Settings, written as plain text. */
    public static function fill(string $text, string $lang): string
    {
        $site = $lang === 'tl' ? 'aming website' : 'our website';

        return str_replace(
            ['{{phone}}', '{{email}}', '{{facebook}}', '{{contact}}'],
            [Contact::phone() ?? $site, Contact::email() ?? $site, Contact::facebook() ?? $site, Contact::sentence($lang)],
            $text
        );
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
    private static function sizeAnswer(string $message): ?string
    {
        if (!preg_match('/\b(sizes?|dimensions?|measurements?|how (big|large|long|tall|wide))\b/i', $message)) {
            return null;
        }

        $lower = mb_strtolower($message);
        $named = Product::available()->whereNotNull('dimensions')->get()
            ->first(fn ($p) => $p->product_name && str_contains($lower, mb_strtolower($p->product_name)));

        if ($named && $named->dimensions_label) {
            return "The {$named->product_name} measures {$named->dimensions_label}, as length x width x height.";
        }

        return null;
    }

    /** "5000", "PHP 5,000" or "5k": pieces at or under that amount. A small bare number is not a budget. */
    private static function budgetAnswer(string $message): ?array
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
            'text'     => 'Great! Here are some pieces within your PHP ' . number_format($budget) . ' budget:',
            'products' => $found->map(fn ($p) => ['id' => $p->id, 'product_name' => $p->product_name, 'price' => $p->price])->all(),
        ];
    }
}
