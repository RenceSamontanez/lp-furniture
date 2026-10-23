<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\CustomOrderEvent;
use App\Models\CustomQuote;
use App\Models\DesignAgreement;
use App\Models\Order;
use App\Models\PaymentRecord;
use Illuminate\Support\Carbon;

/**
 * The status chip and the "next step" banner on the customer's concept page (design.show),
 * decided from the real state of the request, its quote, agreement and order, so a banner
 * can never keep saying "next step is payment" after the payment arrived.
 *
 * Returns: key, chip, tone (ok|gold|bad|muted), variant (an <x-notice> variant), title,
 * text, actions [['label', 'href', 'primary' => bool, 'loading' => ?string]].
 */
class ConceptBanner
{
    private const TZ = 'Asia/Manila';

    public static function for(CustomDesignRequest $r, ?CustomQuote $quote = null, ?DesignAgreement $agreement = null): array
    {
        $pipeline = $r->pipeline_status;

        if ($pipeline === null) {
            return self::make('sent_legacy', 'SENT TO OUR TEAM', 'gold', 'success', 'Design selected! Our team will contact you shortly to discuss pricing and production.', '');
        }

        return match ($pipeline) {
            'submitted', 'contacting', 'unreachable' => self::request($r),
            'quoted'               => self::quoted($r, $quote),
            'declined_by_customer' => self::make('quote_declined', 'QUOTE DECLINED', 'muted', 'blocked', 'You declined this quote.', 'Nothing is due. Contact us if you would like a new quote.'),
            'declined_by_admin'    => self::make('declined_by_admin', 'NOT ACCEPTED', 'muted', 'blocked', "We're not able to take this on.",
                $r->admin_notes ?: "After review, our team isn't able to produce this particular design. Feel free to try a different idea, or reach out if you'd like to discuss it further."),
            'expired'              => self::expired($r, $quote, $agreement),
            'accepted', 'ordered'  => self::agreed($r, $agreement),
            'cancelled'            => self::make('cancelled', 'CANCELLED', 'muted', 'blocked', 'This request was cancelled.', 'Contact us if you have questions.'),
            default                => self::make('open', 'IN PROGRESS', 'gold', 'info', 'Our team is working on your request.', ''),
        };
    }

    private static function request(CustomDesignRequest $r): array
    {
        $edit = $r->isEditableByCustomer()
            ? [['label' => 'Edit my request', 'href' => route('design.edit', $r->id), 'primary' => false]]
            : [];

        if ($r->pipeline_status === 'contacting') {
            return self::make('contacting', 'WE ARE IN TOUCH', 'gold', 'waiting', 'Our team has been in touch with you.',
                'We will send your quote after we have talked through the details. No payment is due yet.', $edit);
        }

        if ($r->pipeline_status === 'unreachable') {
            return self::make('unreachable', 'WE COULD NOT REACH YOU', 'bad', 'problem', "We couldn't reach you by phone.",
                'Please check that your phone number is correct, or contact us and we will call you again.', $edit);
        }

        return self::make('submitted', 'WAITING FOR OUR CALL', 'gold', 'waiting',
            'Request sent, we will call you at ' . $r->contact_phone . ' during ' . $r->callWindowLabel() . '.',
            "This is a request, not an order. No payment is due yet. We'll discuss the price and details on the call.", $edit);
    }

    private static function quoted(CustomDesignRequest $r, ?CustomQuote $quote): array
    {
        if (!$quote) {
            return self::make('quoted', 'QUOTE READY', 'gold', 'info', 'Your quote is ready.', '');
        }

        $sum = QuoteTerms::summary($quote);
        $actions = $quote->status === 'sent'
            ? [['label' => 'Review and accept', 'href' => route('design.quote.review', [$quote->request_id, $quote->id]), 'primary' => true]]
            : [];

        if ($quote->status !== 'sent') {
            return self::expiredQuote($quote);
        }

        return self::make('quoted', 'QUOTE READY', 'gold', 'info', 'Your quote for ' . $r->order_name . ' is ready.',
            'Total ₱' . number_format($sum['total'], 2) . ', valid until ' . $quote->expires_at->timezone(self::TZ)->format('M d, Y h:i A') . '.', $actions);
    }

    private static function expired(CustomDesignRequest $r, ?CustomQuote $quote, ?DesignAgreement $agreement): array
    {
        $last = CustomOrderEvent::where('request_id', $r->id)->whereIn('type', ['quote_expired', 'agreement_expired'])->orderByDesc('id')->first();

        if ($last && $last->type === 'agreement_expired') {
            return self::make('agreement_expired', 'AGREEMENT EXPIRED', 'muted', 'blocked', 'The time to start payment passed, so the agreement expired.',
                "Contact us if you'd still like to proceed and we will prepare a new quote.");
        }

        return $quote ? self::expiredQuote($quote)
            : self::make('quote_expired', 'QUOTE EXPIRED', 'muted', 'blocked', 'This quote expired.', "Contact us if you'd still like to proceed.");
    }

    private static function expiredQuote(CustomQuote $quote): array
    {
        return self::make('quote_expired', 'QUOTE EXPIRED', 'muted', 'blocked',
            'This quote expired on ' . $quote->expires_at->timezone(self::TZ)->format('M d, Y') . '.', "Contact us if you'd still like to proceed.");
    }

    private static function agreed(CustomDesignRequest $r, ?DesignAgreement $agreement): array
    {
        if (!$agreement) {
            return self::make('agreed', 'AGREED', 'gold', 'info', 'Your agreement is recorded.', '');
        }

        $order = $agreement->order;
        $agreementBtn = ['label' => 'View agreement', 'href' => route('design.agreement', $agreement->id), 'primary' => false];
        $when = $agreement->accepted_at->timezone(self::TZ)->format('M d, Y h:i A');

        if (!$order) {
            $text = 'Agreement ' . $agreement->agreement_number . '.';
            if ($r->payment_due_at) {
                $text .= ' Start your payment by ' . $r->payment_due_at->timezone(self::TZ)->format('M d, Y h:i A') . '.';
            }

            return self::make('payment_due', 'WAITING FOR PAYMENT', 'gold', 'waiting', 'Agreed on ' . $when . ', next step is payment.', $text, [
                ['label' => 'Proceed to payment', 'href' => route('custom.checkout', $agreement->id), 'primary' => true, 'loading' => 'Opening payment...'],
                $agreementBtn,
            ]);
        }

        $viewOrder = ['label' => 'View order', 'href' => route('orders.show', $order->id), 'primary' => true];
        $actions = [$viewOrder, $agreementBtn];
        $ref = 'Order ' . $order->order_number . '. ';
        $paid = $order->order_status !== 'pending' || (float) $order->amount_paid > 0;

        if ($order->order_status === 'cancelled') {
            return self::make('order_cancelled', 'ORDER CANCELLED', 'muted', 'blocked', 'Your order was cancelled.', $ref . 'Contact us if you have questions.', $actions);
        }

        if (!$paid) {
            return self::make('order_created', 'WAITING FOR PAYMENT', 'gold', 'waiting', 'Agreed on ' . $when . ', next step is payment.',
                'Agreement ' . $agreement->agreement_number . '. Order ' . $order->order_number . ' has been created.', $actions);
        }

        $ready = $order->expected_ready_date ? ' We expect it to be ready by ' . Carbon::parse($order->expected_ready_date)->format('M d, Y') . '.' : '';
        $owing = (float) $order->total_amount - (float) $order->amount_paid;
        $balance = $owing > 0.009 ? ' A balance of ₱' . number_format($owing, 2) . ' is still due, see your order for the schedule.' : '';
        $delivery = $order->delivery_option === 'delivery' ? 'We will arrange the delivery with you.' : 'We will contact you about picking it up at our shop.';

        [$key, $chip, $title, $text] = match ($order->order_status) {
            'in_production'    => ['in_production', 'IN PRODUCTION', 'We are building your order.', $ref . 'Your payment was received.' . $ready . $balance],
            'ready'            => ['ready', 'ORDER READY', 'Your order is ready.', $ref . $delivery . $balance],
            'out_for_delivery' => ['out_for_delivery', 'OUT FOR DELIVERY', 'Your order is out for delivery.', $ref . $balance],
            'completed'        => ['completed', 'COMPLETED', 'Your order is completed.', $ref . 'Thank you for ordering from LP Furniture.' . $balance],
            default            => $order->order_status === 'confirmed'
                ? ['paid', 'PAYMENT RECEIVED', 'Payment received. We will start building your order.', $ref . 'Agreement ' . $agreement->agreement_number . '.' . $ready . $balance]
                : ['paid_pending', 'PAYMENT RECEIVED', 'Payment received. We are confirming your order.', $ref . 'You will be notified when it is confirmed.' . $balance],
        };

        return self::make($key, $chip, 'ok', 'success', $title, $text, $actions);
    }

    private static function make(string $key, string $chip, string $tone, string $variant, string $title, string $text, array $actions = []): array
    {
        return compact('key', 'chip', 'tone', 'variant', 'title', 'text', 'actions');
    }
}
