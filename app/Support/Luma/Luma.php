<?php

namespace App\Support\Luma;

use App\Models\ChatbotIntent;
use App\Models\Product;
use App\Models\ServiceInquiry;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Luma, LP Furniture Shop's AI Assistant: turns one customer message into a structured reply.
 * Everything returned is plain text (the widget escapes it); nothing from the database is trusted as HTML.
 *
 * ── How a message is handled (first match wins) ───────────────────────────────────────────────────────────────
 *   1. an open guided flow (cancellation / refund) gets the message first;
 *   2. a reference number (REF-/INV-/SI-YYYY-NNNN) is looked up for the signed-in owner, nobody else;
 *   3. the answer to a question Luma just asked ("Other Services job or custom furniture order?");
 *   4. thanks, goodbye, and follow-ups to the last topic ("yes", "how", "when", "sige", "ano po?");
 *   5. the trained intents (IntentMatcher), where a bare "refund", "cancel", "payment" or "status" is asked about once;
 *   6. product size, budget and furniture-type questions; 7. the fallback.
 *
 * ── Tone rules (docs/luma-tone-rules.md; tests/Feature/LumaToneTest.php) ─────────────────────────────────────
 *   Calm and never defensive. Apologise once, never repeatedly, never blame the customer. Never promise a refund or a
 *   date, and never use the words "approved" or "guaranteed": a request is "sent", the team "reviews it and replies".
 *   Stressed or angry message: one empathy line first, then the facts, then the next step. Plain words, short
 *   sentences, "po" in Tagalog (Taglish gets simple Tagalog with the customer's English words). Numbers come from
 *   Facts tokens, never typed in. A button only for something the code allows.
 */
final class Luma
{
    /** What "yes" / "sige" does after a topic: show this next node (or start the flow). */
    private const OFFERS = [
        'luma_os_what_is'           => 'luma_os_make_ticket',
        'luma_os_make_ticket'       => 'luma_os_describe_problem',
        'luma_os_describe_problem'  => 'luma_os_make_ticket',
        'luma_os_after_ticket'      => 'luma_os_invoice_what',
        'luma_os_where_work'        => 'luma_os_visit_pickup_fee',
        'luma_os_duration'          => 'luma_os_check_status',
        'luma_os_invoice_what'      => 'luma_os_invoice_find',
        'luma_os_pay_how'           => 'luma_os_pay_full_half',
        'luma_os_pay_full_half'     => 'luma_os_second_half',
        'luma_os_second_half'       => 'luma_os_pay_status',
        'luma_os_no_pay_no_work'    => 'luma_os_pay_how',
        'luma_os_refund_rule'       => 'refund_flow',
        'luma_os_refund_after_start' => 'refund_flow',
        'luma_os_work_started_meaning' => 'luma_os_check_status',
        'luma_os_refund_timeline'   => 'luma_os_refund_status',
        'luma_os_cancel_notice'     => 'luma_os_refund_status',
        'luma_os_taking_too_long'   => 'refund_flow',
        'luma_os_partial_refund'    => 'refund_flow',
    ];

    /** What "how" / "paano po" / "ano po?" does after a topic. */
    private const HOW = [
        'luma_os_refund_rule'       => 'refund_flow',
        'luma_os_refund_after_start' => 'refund_flow',
        'luma_os_refund_declined'   => 'luma_os_refund_status',
        'luma_os_taking_too_long'   => 'refund_flow',
        'luma_os_pay_how'           => 'luma_os_pay_full_half',
        'luma_os_what_is'           => 'luma_os_make_ticket',
    ];

    private const REFUND_FAMILY = [
        'luma_os_refund_rule', 'luma_os_work_started_meaning', 'luma_os_refund_how', 'luma_os_refund_timeline', 'luma_os_refund_status',
        'luma_os_wrong_gcash', 'luma_os_refund_declined', 'luma_os_refund_after_start', 'luma_os_cancel_unpaid', 'luma_os_half_paid_cancel',
        'luma_os_partial_refund', 'luma_os_change_of_mind', 'luma_os_duplicate_request', 'luma_os_taking_too_long', 'luma_os_cancel_notice',
    ];

    /** Furniture answers that settle the conversation on the furniture side once Luma has shown them. */
    private const FURNITURE_ANSWERS = ['luma_cancel_refund', 'luma_payment_methods', 'luma_track_order', 'luma_deposit_rules'];

    /**
     * @return array{text: string, suggestions: list<string>, products: list<array>, cards: list<array>, buttons: list<array{label: string, url: string}>, contact: ?array, form: ?array, reference: ?string, lang: string, detected: string}
     */
    public static function reply(string $message): array
    {
        $message = trim($message);
        $state   = ChatState::load();
        $user    = Auth::user();   // the customer guard only: Luma never answers from an admin session
        $lang    = self::languageFor($message, $state);
        $turn    = $state->nextTurn();

        $reply = self::route($message, $lang, $state, $user);
        $reply = self::withEmpathy($reply, $message, $state, $turn);

        $state->set('misses', ($reply['detected'] ?? '') === 'unmatched' ? (int) $state->get('misses', 0) + 1 : 0);
        $state->set('lang', $lang);
        $state->save();

        return self::finish($reply);
    }

    /** English or Tagalog (Taglish counts as Tagalog and gets simple Tagalog with the customer's English words). */
    private static function languageFor(string $message, ChatState $state): string
    {
        [$tl, $en] = Language::scores($message);

        // "yes", "ok", "how", a reference number: no language of their own, so keep the one the conversation is in.
        $neutral = Context::isAffirmation($message) || Context::isHow($message) || Context::isWhen($message)
            || Context::isThanks($message) || Context::isGoodbye($message) || Reference::strip($message) === '';
        if ($tl === 0 && $en === 0 && $neutral && $state->get('lang')) {
            return (string) $state->get('lang');
        }

        return $tl > 0 && $tl >= $en ? Language::TL : Language::EN;
    }

    private static function route(string $message, string $lang, ChatState $state, ?User $user): array
    {
        // 1. an open guided flow
        if ($state->inFlow() && ($body = RefundFlow::handle($message, $lang, $state, $user))) {
            return self::wrap($body, $lang, 'flow:refund');
        }

        // 2. reference numbers
        if ($refs = Reference::find($message)) {
            return self::referenceReply($refs, $message, $lang, $state, $user);
        }

        $words = Text::words($message);

        // 3. the answer to the question just asked
        if ($pending = $state->get('pending')) {
            $state->set('pending', null);
            if ($side = Disambiguation::answer($words)) {
                return self::resolveAsked((string) $pending, $side, $message, $lang, $state, $user);
            }
        }

        // 4. thanks, goodbye, follow-ups
        if (Context::isThanks($message) || Context::isGoodbye($message)) {
            return self::build($lang === Language::TL ? 'Walang anuman po.' : "You're welcome.", [], 'goodbye', $lang, closing: 'goodbye');
        }
        if (($topic = $state->get('topic')) && ($follow = self::followUp($message, (string) $topic, $lang, $state, $user))) {
            return $follow;
        }

        // 5. trained intents
        $intents = ChatbotIntent::query()->where('is_active', true)->get();
        $hit     = IntentMatcher::best($message, $intents);

        if ($hit) {
            $category = (string) $hit['intent']->category;

            // "refund policy for my order": a furniture word and no Other Services word sends the shared topics to the furniture answers.
            if ($furniture = self::furnitureCounterpart($category, $words)) {
                $state->set('domain', Disambiguation::FURNITURE);

                return self::nodeReply($furniture, $message, $lang, $state, $user);
            }

            // Bare words that mean two different things: ask once (unless the conversation already says which).
            if ($kind = self::ambiguousKind($category, $words)) {
                return self::askOrRoute($kind, $message, $lang, $state, $user, $hit['intent']);
            }

            return self::fromIntent($hit['intent'], $message, $lang, $state, $user);
        }

        $more = self::chips(['View all products', 'Custom design', 'Delivery info'], $lang);

        if ($size = self::sizeAnswer($message, $lang)) {
            return self::build($size, $more, 'product_size', $lang);
        }

        if ($budget = self::budgetAnswer($message, $lang)) {
            return self::build($budget['text'], $more, 'budget', $lang, cards: $budget['cards'], buttons: $budget['buttons']);
        }

        // "doors" on its own: show the doors (a type we have nothing of is an unanswered question, so it is logged as one)
        if (($type = ProductFinder::detectType($message)) && ProductFinder::available($type)->isNotEmpty()) {
            return self::productsReply(self::build('', self::chips(['Prices', 'Custom design', 'Delivery'], $lang), 'product_type', $lang), $type, false);
        }

        // Nothing matched, but the whole message is one payment or status word ("payment"): still ask, never a miss.
        if (Disambiguation::isBarePayment($words)) {
            return self::askOrRoute('payment', $message, $lang, $state, $user, new ChatbotIntent());
        }
        if (Disambiguation::isBareStatus($words)) {
            return self::askOrRoute('status', $message, $lang, $state, $user, new ChatbotIntent());
        }

        return self::fallback($lang, $message, (int) $state->get('misses', 0));
    }

    /* ───────────── asking once, and remembering the answer ───────────── */

    /** The furniture answer for an Other Services node, when the message is clearly about a furniture order. */
    private static function furnitureCounterpart(string $category, array $words): ?string
    {
        if (!Disambiguation::mentionsFurniture($words) || Disambiguation::mentionsOtherServices($words)) {
            return null;
        }

        return match (true) {
            in_array($category, self::REFUND_FAMILY, true) && $category !== 'luma_os_taking_too_long' => 'luma_cancel_refund',
            $category === 'luma_os_check_status' => 'luma_track_order',
            $category === 'luma_os_pay_how' => 'luma_payment_methods',
            default => null,
        };
    }

    /** refund | cancel | payment | status when the winning intent is a furniture answer to a bare, context-free word. */
    private static function ambiguousKind(string $category, array $words): ?string
    {
        if (Disambiguation::mentionsFurniture($words) || Disambiguation::mentionsOtherServices($words)) {
            return null;
        }

        return match (true) {
            $category === 'luma_cancel_refund' => in_array('refund', $words, true) ? 'refund' : 'cancel',
            $category === 'luma_payment_methods' && Disambiguation::isBarePayment($words) => 'payment',
            in_array($category, ['luma_os_check_status', 'luma_track_order'], true) && Disambiguation::isBareStatus($words) => 'status',
            default => null,
        };
    }

    private static function askOrRoute(string $kind, string $message, string $lang, ChatState $state, ?User $user, ChatbotIntent $intent): array
    {
        // The conversation already says which side this is on: no question.
        if ($side = $state->domain()) {
            return self::resolveAsked($kind, $side, $message, $lang, $state, $user);
        }

        $state->set('pending', $kind);
        $ask = Disambiguation::ask($kind, $lang);

        return self::build($ask['text'], $ask['suggestions'], $ask['detected'], $lang, closing: 'flow');
    }

    private static function resolveAsked(string $kind, string $side, string $message, string $lang, ChatState $state, ?User $user): array
    {
        $state->set('domain', $side);

        $category = match ([$kind, $side]) {
            ['payment', Disambiguation::OS]        => 'luma_os_pay_how',
            ['payment', Disambiguation::FURNITURE] => 'luma_payment_methods',
            ['status', Disambiguation::OS]         => 'luma_os_check_status',
            ['status', Disambiguation::FURNITURE]  => 'luma_track_order',
            [$kind, Disambiguation::OS]            => 'luma_os_refund_how',
            default                                => 'luma_cancel_refund',
        };

        return self::nodeReply($category, $message, $lang, $state, $user);
    }

    /** The reply of one named intent (the stored row if active, otherwise the built-in default). */
    private static function nodeReply(string $category, string $message, string $lang, ChatState $state, ?User $user): array
    {
        $intent = ChatbotIntent::query()->where('is_active', true)->where('category', $category)->first();

        if (!$intent && ($def = DefaultIntents::find($category))) {
            $intent = new ChatbotIntent(['category' => $category, 'keywords' => $def['keywords'], 'response' => $def['en'], 'answer_tl' => $def['tl'], 'suggestions' => $def['chips'] ?? null]);
        }
        if (!$intent) {
            return self::fallback($lang, $message, 0);
        }

        return self::fromIntent($intent, $message, $lang, $state, $user);
    }

    /* ───────────── reference numbers and follow-ups ───────────── */

    private static function referenceReply(array $refs, string $message, string $lang, ChatState $state, ?User $user): array
    {
        $ref = $refs[0];
        $state->set('domain', Disambiguation::OS)->set('topic', 'reference');

        // "cancel SI-2026-0003": go straight to the guided flow for that ticket (owner only).
        $rest = Text::words(Reference::strip($message));
        $asksStatus = array_intersect($rest, ['where', 'status', 'track', 'check', 'update', 'kumusta', 'when', 'how', 'long', 'much', 'received', 'sent', 'due', 'left', 'balance', 'paid']) !== [];
        if ($user && !$asksStatus && array_intersect($rest, ['cancel', 'refund']) !== []) {
            if ($ticket = RefundFlow::ticketForReference($ref, $user)) {
                return self::wrap(RefundFlow::afterPick($ticket, $state, $user, $lang), $lang, 'flow:refund');
            }
        }

        // A ticket picked from "which one?": answer the question that was being asked about it.
        if ($user && ($pickFor = $state->get('pick_for'))) {
            $ticket = RefundFlow::ticketForReference($ref, $user);
            if ($ticket) {
                $state->set('pick_for', null)->set('ticket_id', $ticket->id);

                return self::wrap(StatusAnswers::forTicket((string) $pickFor, $ticket, $lang), $lang, 'reference:' . $ref['type']);
            }
        }

        if ($user && $ref['type'] === 'SI' && ($t = ServiceInquiry::where('user_id', $user->id)->where('reference_number', $ref['number'])->first())) {
            $state->set('ticket_id', $t->id);
        }

        return self::wrap(StatusAnswers::forReference($ref, $user, $lang), $lang, 'reference:' . $ref['type']);
    }

    private static function followUp(string $message, string $topic, string $lang, ChatState $state, ?User $user): ?array
    {
        $target = null;

        if (Context::isAffirmation($message)) {
            $target = self::OFFERS[$topic] ?? (OtherServicesKnowledge::nodes()[$topic]['next'][0] ?? null);
        } elseif (Context::isHow($message)) {
            $target = self::HOW[$topic] ?? self::OFFERS[$topic] ?? null;
        } elseif (Context::isWhen($message)) {
            $target = in_array($topic, self::REFUND_FAMILY, true) ? 'luma_os_refund_timeline' : (str_starts_with($topic, 'luma_os_pay') || $topic === 'luma_os_second_half' ? 'luma_os_second_half' : (str_starts_with($topic, 'luma_os_') ? 'luma_os_duration' : null));
        } elseif (Context::isPaidFollowUp($message) && in_array($topic, self::REFUND_FAMILY, true)) {
            $target = $user ? 'refund_flow' : 'luma_os_refund_rule';
        }

        if ($target === null) {
            return null;
        }
        if ($target === 'refund_flow') {
            return self::nodeReply('luma_os_refund_how', $message, $lang, $state, $user);
        }

        return self::nodeReply($target, $message, $lang, $state, $user);
    }

    /* ───────────── building replies ───────────── */

    /** One empathy line first when the customer sounds stressed or angry; one apology at most, never stacked. */
    private static function withEmpathy(array $reply, string $message, ChatState $state, int $turn): array
    {
        $apologised = Context::startsWithApology($reply['text']);

        if (!$apologised && Context::isStressed($message) && $reply['text'] !== '') {
            $recent = $turn - (int) $state->get('apologised_turn', -99) <= 2;
            $line   = Context::empathyLine($reply['lang'], !$recent);
            $reply['text'] = $line . ' ' . $reply['text'];
            if (!$recent) {
                $state->set('apologised_turn', $turn);
            }
        } elseif ($apologised) {
            $state->set('apologised_turn', $turn);
        }

        return $reply;
    }

    /** Turns a body from StatusAnswers / RefundFlow (text, buttons, cards, chips, contact flag, form, reference) into a reply. */
    private static function wrap(array $b, string $lang, string $detected): array
    {
        $contact = $b['contact'] ?? null;
        if ($contact === true) {
            $contact = (Contact::phone() || Contact::email()) ? Contact::card() : null;
        } elseif (!is_array($contact)) {
            $contact = null;
        }

        $reply = self::build(
            $b['text'], $b['suggestions'] ?? [], $b['detected'] ?? $detected, $lang,
            cards: $b['cards'] ?? [], buttons: array_values(array_filter($b['buttons'] ?? [])),
            closing: str_ends_with((string) ($b['detected'] ?? ''), 'exit') ? 'goodbye' : 'flow',
        );
        $reply['contact']   = $contact;
        $reply['form']      = $b['form'] ?? null;
        $reply['reference'] = $b['reference'] ?? null;

        return $reply;
    }

    /**
     * The last step of every reply: removes a retired sign-off an admin may still have typed at the end of an answer
     * (config luma.retired_closings), then adds the safe HTML version of the text (Formatter). No sign-off line is added.
     */
    public static function finish(array $reply): array
    {
        $text = rtrim((string) ($reply['text'] ?? ''));
        do {
            $stripped = false;
            foreach (config('luma.retired_closings', []) as $line) {
                if ($line !== '' && str_ends_with($text, $line)) {
                    $text     = rtrim(substr($text, 0, -strlen($line)));
                    $stripped = true;
                }
            }
        } while ($stripped);

        $reply['text'] = $text;
        $reply['html'] = Formatter::toHtml($text);

        return $reply;
    }

    /** The quick-reply chips, in Tagalog when the customer wrote in Tagalog (the chip is sent back as their next question). */
    public static function chips(array $chips, string $lang): array
    {
        if ($lang !== Language::TL) {
            return $chips;
        }

        $map = config('luma.chips_tl') + OtherServicesKnowledge::chipTranslations();

        return array_map(fn ($c) => $map[$c] ?? $c, $chips);
    }

    /**
     * The answer of one trained intent, plus the buttons, contact card, live products and personal status that go
     * with a built-in one. A node that reads the customer's own data shows a Log in button to a guest instead.
     */
    private static function fromIntent(ChatbotIntent $intent, string $message, string $lang, ChatState $state, ?User $user): array
    {
        $category = (string) $intent->category;
        $answer   = $lang === Language::TL && filled($intent->answer_tl) ? $intent->answer_tl : $intent->response;

        $reply = self::build(
            text: self::fill((string) $answer, $lang),
            suggestions: self::chips($intent->suggestions_array, $lang),
            detected: 'intent:' . ($intent->id ?? $category),
            lang: $lang,
        );

        if ($category !== '') {
            $state->set('topic', $category);
            if (str_starts_with($category, 'luma_os_')) {
                $state->set('domain', Disambiguation::OS);
            } elseif (in_array($category, self::FURNITURE_ANSWERS, true)) {
                $state->set('domain', Disambiguation::FURNITURE);
            }
        }

        $def = DefaultIntents::find($category);
        if (!$def) {
            return $reply;
        }

        $reply['buttons'] = self::buttons($def['buttons'] ?? [], $lang);

        if (DefaultIntents::isCard($category) && (Contact::phone() || Contact::email())) {
            $reply['contact'] = Contact::card();
        }

        return match ($def['handler'] ?? null) {
            'products'     => self::productsReply($reply, ProductFinder::detectType($message), $category === 'luma_ready_made'),
            'range'        => self::rangeReply($reply, ProductFinder::detectType($message)),
            'labor'        => self::laborReply($reply),
            'refund_flow'  => self::refundFlowReply($reply, $state, $user, $lang),
            'status_ticket', 'status_invoice', 'status_pay', 'status_refund', 'status_schedule', 'status_late'
                           => self::personalReply($reply, (string) $def['handler'], !empty($def['lead']), $state, $user, $lang),
            default        => $reply,
        };
    }

    /** The node's own short lead-in, then the guided flow (or the guest's Log in buttons). */
    private static function refundFlowReply(array $reply, ChatState $state, ?User $user, string $lang): array
    {
        $flow = RefundFlow::start($state, $user, $lang);

        $reply['text'] = rtrim($reply['text']) . "\n\n" . $flow['text'];
        $reply['buttons'] = array_slice(array_merge(array_values(array_filter($flow['buttons'] ?? [])), $reply['buttons']), 0, 3);
        $reply['cards'] = $flow['cards'] ?? [];
        $reply['form'] = $flow['form'] ?? null;
        if (!empty($flow['suggestions'])) {
            $reply['suggestions'] = $flow['suggestions'];
        }
        if (!empty($flow['contact'])) {
            $reply['contact'] = Contact::card();
        }
        $reply['closing'] = 'flow';

        return $reply;
    }

    /** The signed-in customer's own status after the node's general answer. A guest gets the general answer and Log in / Register. */
    private static function personalReply(array $reply, string $handler, bool $lead, ChatState $state, ?User $user, string $lang): array
    {
        $tl = $lang === Language::TL;

        if (!$user) {
            $reply['buttons'] = array_slice(array_merge(
                [['label' => $tl ? 'Mag-log in' : 'Log in', 'url' => route('login', [], false)], ['label' => $tl ? 'Gumawa ng account' : 'Create an account', 'url' => route('register', [], false)]],
                $reply['buttons'],
            ), 0, 3);

            return $reply;
        }

        $body = StatusAnswers::personal($handler, $user, $lang, $state->get('ticket_id') ? (int) $state->get('ticket_id') : null);
        $state->set('pick_for', !empty($body['cards']) ? $handler : null);

        // A lead-in node ("I can check your ticket") is replaced by the facts; it keeps only an opening apology.
        $general = rtrim($reply['text']);
        if ($lead) {
            $first   = preg_split('/(?<=[.!?])\s+/u', $general)[0] ?? '';
            $general = Context::startsWithApology($first) ? $first : '';
        }
        $reply['text'] = trim($general . "\n\n" . $body['text']);

        $buttons = array_values(array_filter(array_merge($body['buttons'] ?? [], $reply['buttons'])));
        $seen    = [];
        $reply['buttons'] = array_slice(array_values(array_filter($buttons, function ($b) use (&$seen) {
            return !isset($seen[$b['url']]) && ($seen[$b['url']] = true);
        })), 0, 3);

        if (!empty($body['cards'])) {
            $reply['cards'] = $body['cards'];
        }
        if (!empty($body['suggestions'])) {
            $reply['suggestions'] = $body['suggestions'];
        }
        if (!empty($body['contact'])) {
            $reply['contact'] = (Contact::phone() || Contact::email()) ? Contact::card() : null;
        }

        return $reply;
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
        $tl      = $reply['lang'] === Language::TL;
        $account = $tl
            ? 'Kailangan po ng account para makapagpadala ng request, para makontak namin kayo.'
            : 'You need an account to send a request, so that we can contact you.';

        $reply['text'] = rtrim($reply['text'], ': ') . '. ' . $account;

        if ($reply['contact'] === null) {
            $reply['text'] .= ' ' . Contact::sentence($reply['lang']);
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
            // The request form needs an account: a guest is sent to log in first and comes back to it.
            if ($route === 'services.request' && !Auth::check()) {
                [$en, $tl] = ['Log in to send a request', 'Mag-log in para magpadala ng request'];
            }
            $out[] = self::button($en, $tl, route($route, [], false), $lang);
        }

        return $out;
    }

    /**
     * The dead-end answer, and the moment the question is kept for the team (UnansweredQuestions::record). It always says
     * why Luma cannot answer, gives the shop's contact details as label/value rows from Contact Settings, and says what she
     * can help with. "I saved your question" is added only when the question was really saved (junk, links, profanity and
     * rate-limited visitors are skipped).
     */
    public static function fallback(string $lang, string $message = '', int $misses = 0): array
    {
        $tl    = $lang === Language::TL;
        $saved = $message !== '' && UnansweredQuestions::record($message);

        $text = $tl
            ? 'Pasensya na po, hindi ko po masagot iyan. Hindi pa po ako naturuan tungkol dito, at ayaw ko pong manghula at magbigay ng maling impormasyon.' . ($saved ? ' Na-save ko po ang tanong ninyo para maituro ito ng aming team sa akin.' : '')
            : "Sorry, I can't answer that one. I haven't been trained on that topic yet, and I don't want to guess and give you wrong information." . ($saved ? ' I saved your question so our team can teach me the answer.' : '');
        $text .= $tl
            ? "\n\nKung kailangan ninyo ng tulong agad, makipag-ugnayan po kayo sa shop:\n\n[[contact:short]]\n\nMaaari rin po kayong magtanong tungkol sa aming mga produkto, presyo, pag-order, delivery o bayad."
            : "\n\nIf you need help sooner, you can reach the business directly:\n\n[[contact:short]]\n\nYou can also ask me about our products, prices, ordering, delivery or payment.";

        return self::build(self::fill($text, $lang), self::chips(self::fallbackChips($message), $lang), 'unmatched', $lang);
    }

    /** Chips that fit what was typed first, then the usual topics, then always the way to a person. */
    public static function fallbackChips(string $message): array
    {
        $w     = Text::words($message);
        $chips = [];
        if (array_intersect($w, ['refund', 'cancel', 'return', 'money', 'cancelled']) !== []) {
            $chips[] = 'Refund or cancel';
        }
        if (array_intersect($w, ['pay', 'payment', 'invoice', 'gcash', 'cash', 'card', 'paid', 'receipt']) !== []) {
            $chips[] = 'Pay my invoice';
        }
        if (array_intersect($w, ['service', 'repair', 'labor', 'ticket', 'request', 'photo', 'restoration', 'fix']) !== []) {
            $chips[] = 'Other Services';
        }
        foreach (['Other Services', 'Refund or cancel', 'Pay my invoice', 'How to order', 'Custom design'] as $default) {
            if (count($chips) >= 4) {
                break;
            }
            if (!in_array($default, $chips, true)) {
                $chips[] = $default;
            }
        }
        $chips[] = 'Talk to a person';

        return $chips;
    }

    /**
     * Fills the {{tokens}} (hours, estimates, a contact sentence...) as plain text (see Facts), and the [[contact]] /
     * [[contact:short]] tokens as label/value lines built from Contact Settings.
     */
    public static function fill(string $text, string $lang): string
    {
        if (str_contains($text, '[[contact')) {
            $text = str_replace(['[[contact:short]]', '[[contact]]'], [Contact::block($lang, false), Contact::block($lang, true)], $text);
        }
        if (!str_contains($text, '{{')) {
            return $text;
        }

        $tokens = Facts::tokens($lang);

        return str_replace(array_keys($tokens), array_values($tokens), $text);
    }

    /** @param ?string $closing  which closing line to end on: answer (default), fallback, flow or goodbye */
    private static function build(string $text, array $suggestions, string $detected, string $lang, array $cards = [], array $buttons = [], ?string $closing = null): array
    {
        return [
            'text'        => $text,
            'suggestions' => array_values(array_filter($suggestions, fn ($s) => $s !== '')),
            'products'    => [],
            'cards'       => $cards,
            'buttons'     => $buttons,
            'contact'     => null,
            'form'        => null,
            'reference'   => null,
            'lang'        => $lang,
            'detected'    => $detected,
            'closing'     => $closing,
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
                : 'Here are some pieces within your ' . ProductFinder::money($budget) . ' budget:',
            'cards'   => ProductFinder::cards($found),
            'buttons' => [self::button('View more products', 'Tingnan ang iba pang produkto', ProductFinder::catalogUrl($type, $budget), $lang)],
        ];
    }
}
