<?php

namespace App\Support\Luma;

/**
 * "Refund", "cancel", "payment", "gcash" or "status" with nothing around it could be about an Other Services job
 * (repair or labor) or about a custom furniture order. Luma asks ONCE, with two chips, remembers the answer for the
 * conversation, and routes to the right flow. The furniture answers (luma_cancel_refund, luma_payment_methods,
 * luma_track_order) are not touched: choosing the furniture chip simply shows them.
 */
final class Disambiguation
{
    public const OS        = 'os';
    public const FURNITURE = 'furniture';

    /** Words that make a message clearly about a furniture order (no question needed). */
    private const FURNITURE_WORDS = ['order', 'custom', 'catalog', 'cart', 'checkout', 'delivery', 'design', 'product', 'deposit', 'installment', 'furniture', 'quote', 'agreement', 'pickup', 'door', 'table', 'chair', 'bed', 'cabinet', 'sofa'];

    /** Words that make a message clearly about an Other Services job. */
    private const OS_WORDS = ['service', 'repair', 'labor', 'job', 'ticket', 'invoice', 'photo', 'restoration', 'laborer', 'request'];

    /** Bare payment words: a message made of only these is a one-word payment question. */
    private const PAYMENT_WORDS = ['pay', 'payment', 'gcash', 'cash', 'card', 'stripe', 'paymongo', 'method', 'paid'];

    public static function mentionsFurniture(array $words): bool
    {
        return array_intersect($words, self::FURNITURE_WORDS) !== [];
    }

    public static function mentionsOtherServices(array $words): bool
    {
        return array_intersect($words, self::OS_WORDS) !== [];
    }

    /** True when the message is only payment words ("gcash", "cash po", "payment", "bayad"). */
    public static function isBarePayment(array $words): bool
    {
        return $words !== [] && array_diff($words, self::PAYMENT_WORDS) === [] && array_intersect($words, ['method']) === [];
    }

    /** True when the message is only "status" / "track" words. */
    public static function isBareStatus(array $words): bool
    {
        return $words !== [] && array_diff($words, ['status', 'track', 'tracking', 'update', 'check', 'my', 'where', 'kumusta', 'lagay']) === [];
    }

    /** The one question. Returns the reply body; the caller records `pending` in the chat state. */
    public static function ask(string $kind, string $lang): array
    {
        $tl = $lang === Language::TL;

        $text = match ($kind) {
            'payment' => $tl
                ? 'Tungkol po ba ang bayad na ito sa invoice ng Other Services (pagpapaayos o labor) o sa order ng furniture?'
                : 'Is this payment for an Other Services invoice (repair or labor) or for a furniture order?',
            'status' => $tl
                ? 'Tungkol po ba ito sa request ng Other Services (pagpapaayos o labor) o sa order ng furniture?'
                : 'Is this about an Other Services request (repair or labor) or a furniture order?',
            default => $tl
                ? 'Tungkol po ba ito sa trabaho ng Other Services (pagpapaayos o labor) o sa custom na order ng furniture?'
                : 'Is this about an Other Services job (repair or labor) or a custom furniture order?',
        };

        return [
            'text'        => $text,
            'suggestions' => $tl ? ['Other Services na trabaho', 'Custom na order ng furniture'] : ['Other Services job', 'Custom furniture order'],
            'detected'    => 'disambiguation:' . $kind,
        ];
    }

    /**
     * The customer's answer to the question: 'os', 'furniture', or null when it was not an answer (so the message is
     * handled as an ordinary question instead).
     */
    public static function answer(array $words): ?string
    {
        $os = self::mentionsOtherServices($words) || array_intersect($words, ['trabaho', 'ayos', 'pagpapaayos']) !== [];
        $fu = self::mentionsFurniture($words);

        return match (true) {
            $os && !$fu => self::OS,
            $fu && !$os => self::FURNITURE,
            default     => null,
        };
    }
}
