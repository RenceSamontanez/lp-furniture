<?php

namespace App\Support;

use App\Models\BusinessSetting;

/**
 * Every notification the custom order flow sends, in one place.
 *
 * Wording rules: say what happened, what happens next and who to contact.
 * Plain sentences, no emojis, no exclamation marks, no shouting capitals, no
 * internal terms. The business phone and email come from Contact Settings;
 * when they are not set, the message says to contact the team through the
 * website instead of printing a placeholder.
 *
 * To change a message, edit TEMPLATES below. Placeholders are {braced}:
 *   {order}    the custom order name ("Custom Furniture — Rence")
 *   {contact}  one ready-made sentence with the business contact details
 * and every key ending in _line is an optional sentence that is empty when
 * its data is missing. render() returns ['title' => ..., 'message' => ...].
 */
final class NotificationTemplates
{
    /** Contact Settings values that mean "nobody filled these in yet". */
    private const PLACEHOLDER_PHONE = '0917-000-0000';
    private const PLACEHOLDER_EMAIL = 'contact@example.com';

    public const TEMPLATES = [
        /* ── customer: the request ── */
        'request_received' => [
            'title' => 'Custom Request Received',
            'body'  => 'We received your request for {order}. Our team will call you at {phone} {window_phrase}to discuss the details. No payment is due at this stage. {contact}',
        ],
        'request_updated' => [
            'title' => 'Custom Request Updated',
            'body'  => 'We saved the changes to your request for {order}. Our team will use the updated details when they call you. {contact}',
        ],
        'call_unreachable' => [
            'title' => 'We Could Not Reach You',
            'body'  => 'We tried to call you {times} about your request for {order}, but could not reach you. Please call or email us so we can continue. Mention your account name ({customer}) so we can find your request. {contact}',
        ],
        'call_ringing' => [
            'title' => 'We Called You',
            'body'  => 'We called you {times} about your request for {order}, but there was no answer. Please call us back or send us an email. Mention your account name ({customer}) so we can find your request. {contact}',
        ],
        'call_answered' => [
            'title' => 'Update on Your Custom Request',
            'body'  => 'Thank you for speaking with us about your request for {order}. We are preparing your quote and will notify you here as soon as it is ready. {contact}',
        ],
        'marked_unreachable' => [
            'title' => 'We could not reach you',
            'body'  => 'We could not reach you by phone about your request for {order}. Please check that {phone} is the right number. {contact}',
        ],
        'request_declined' => [
            'title' => 'Custom Request Update',
            'body'  => 'We are unable to take on your request for {order}.{reason_line} If you would like to talk about other options, we are happy to help. {contact}',
        ],

        /* ── customer and admin: installment plans (App\Support\InstallmentNotifier) ── */
        'installment_plan_started' => [
            'title' => 'Installment Plan Started',
            'body'  => 'Your plan for order {order_number}: {count} installments of {each_text}, every {every} days. Your down payment of {down_text} was received. The first installment is due {first_due}{method_line}. We will remind you {reminder_days} days before each date. {contact}',
        ],
        'installment_reminder' => [
            'title' => 'Payment Reminder',
            'body'  => 'Your installment {no} of {of} for order {order_number} ({amount_text}) is due on {due_date}{method_line}. Nothing to do unless you want to pay early. {contact}',
        ],
        'installment_receipt' => [
            'title' => 'Payment Received',
            'body'  => 'We received {amount_text} for installment {no} of {of} on order {order_number}. Remaining balance: {balance_text}. Thank you. {contact}',
        ],
        'installment_charge_failed' => [
            'title' => 'Payment Failed, We Will Try Again',
            'body'  => 'We could not charge your card for installment {no} of {of} on order {order_number}. We will try again tomorrow. You can also pay now. {contact}',
        ],
        'installment_pay_link' => [
            'title' => 'Please Pay Your Installment',
            'body'  => 'Your second attempt did not go through. Pay {amount_text} for installment {no} of {of} on order {order_number} with Card or GCash to keep your plan on track. {contact}',
        ],
        'installment_gcash_due' => [
            'title' => 'Pay Now With GCash',
            'body'  => 'Your GCash installment {no} of {of} for order {order_number} ({amount_text}) is due today. Tap Pay Now and approve it in GCash. {contact}',
        ],
        'installment_overdue' => [
            'title' => 'Installment Overdue',
            'body'  => 'Installment {no} of {of} for order {order_number} is overdue. Please pay as soon as you can or contact us. {contact}',
        ],
        'admin_installment_attention' => [
            'title' => 'Installment Needs Attention',
            'body'  => '{customer}: installment {no} of {of} on order {order_number} could not be charged twice. A Pay Now link was sent to the customer.',
        ],
        'admin_installment_overdue' => [
            'title' => 'Installment Overdue',
            'body'  => '{customer}: installment {no} of {of} on order {order_number} is now Overdue.',
        ],

        /* ── customer: quotes and agreements ── */
        'quote_sent' => [
            'title' => 'Your Custom Quote Is Ready',
            'body'  => 'Your quote for {order} is ready. The total is {total}.{expiry_line} {contact}',
        ],
        'quote_updated' => [
            'title' => 'Your Quote Was Updated',
            'body'  => 'We updated your quote for {order}. The new total is {total}. The earlier quote can no longer be accepted.{expiry_line} {contact}',
        ],
        'quote_expiring' => [
            'title' => 'Your Quote Expires Soon',
            'body'  => 'Your quote for {order} expires on {expiry}. Please review and accept it before then. If you need more time, let us know. {contact}',
        ],
        'quote_expired' => [
            'title' => 'Your Quote Has Expired',
            'body'  => 'Your quote for {order} expired on {when}. If you would still like to go ahead, we can prepare a new quote. {contact}',
        ],
        'quote_declined' => [
            'title' => 'Quote Declined',
            'body'  => 'You declined the quote for {order}. Nothing is due. If you change your mind, let us know. {contact}',
        ],
        'agreement_accepted' => [
            'title' => 'Agreement Accepted',
            'body'  => 'You accepted agreement {agreement} for {order} on {when}. The next step is to pay {upfront} by {due} so we can start production. {contact}',
        ],
        'agreement_expired' => [
            'title' => 'Agreement Expired',
            'body'  => 'The time to start payment for agreement {agreement} ({order}) ended on {when}, so the agreement has expired. If you would still like to go ahead, let us know. {contact}',
        ],

        /* ── customer: orders and payments ── */
        'order_created' => [
            'title' => 'Order Created',
            'body'  => 'Order {order_number} was created for {order} under agreement {agreement}. Please pay {upfront} by {due} so we can start production. {contact}',
        ],
        'payment_received' => [
            'title' => 'Payment Received',
            'body'  => 'We received your payment of {amount} for order {order_number}.{balance_line}{confirmed_line}{ready_line} {contact}',
        ],
        'payment_confirmed' => [
            'title' => 'Payment Successful',
            'body'  => 'We received your payment for order {order_number}, and your order is now confirmed. {contact}',
        ],
        'payment_failed' => [
            'title' => 'Payment Not Completed',
            'body'  => 'Your payment for order {order_number} was not completed, and no charge was made. You can try again from your order page. {contact}',
        ],
        'payment_expired' => [
            'title' => 'Order Cancelled — Payment Not Completed',
            'body'  => 'Your order {order_number} was cancelled because payment was not completed in time. No payment was taken. You are welcome to place a new order at any time. {contact}',
        ],
        'order_confirmed' => [
            'title' => 'Order Update — {order_number}',
            'body'  => 'Your order {order_number} is confirmed. Production will begin shortly, and we will notify you when there is an update. {contact}',
        ],
        'order_in_production' => [
            'title' => 'Order Update — {order_number}',
            'body'  => 'Your order {order_number} is now in production. We will notify you when it is ready. {contact}',
        ],
        'order_ready' => [
            'title' => 'Order Update — {order_number}',
            'body'  => 'Your order {order_number} is ready for pickup. Please get in touch to arrange a pickup time. {contact}',
        ],
        'order_out_for_delivery' => [
            'title' => 'Order Update — {order_number}',
            'body'  => 'Your order {order_number} is out for delivery. Please keep your phone nearby so the delivery team can reach you. {contact}',
        ],
        'order_completed' => [
            'title' => 'Order Update — {order_number}',
            'body'  => 'Your order {order_number} is complete. Thank you for choosing LP Furniture. If anything is not right, let us know. {contact}',
        ],
        'order_cancelled' => [
            'title' => 'Order Update — {order_number}',
            'body'  => 'Your order {order_number} was cancelled. If you have questions about this, we are happy to help. {contact}',
        ],

        /* ── customer: Other Services inquiries ── */
        'inquiry_received' => [
            'title' => 'Service Request Received',
            'body'  => 'We received your service request {reference}. Our team will call you at {phone} {window_phrase}on a weekday between 8:00 AM and 5:00 PM to discuss it. No payment is due at this stage. {contact}',
        ],
        'inquiry_call_unreachable' => [
            'title' => 'We Could Not Reach You',
            'body'  => 'We tried to call you {times} about your service request {reference}, but could not reach you. Please call or email us so we can continue. {contact}',
        ],
        'inquiry_call_ringing' => [
            'title' => 'We Called You',
            'body'  => 'We called you {times} about your service request {reference}, but there was no answer. Please call us back or send us an email. {contact}',
        ],
        'inquiry_call_answered' => [
            'title' => 'Update on Your Service Request',
            'body'  => 'Thank you for speaking with us about your service request {reference}. Our team will follow up on the next steps. {contact}',
        ],

        /* ── customer: Other Services invoices ── */
        'service_invoice_issued' => [
            'title' => 'Your Invoice Is Ready',
            'body'  => 'Invoice {invoice} for {service} is ready. The total is {total}. You can view it and pay it online (GCash or card) from your service requests page, or pay in cash in person. {contact}',
        ],
        'service_invoice_cancelled' => [
            'title' => 'Your Invoice Was Cancelled',
            'body'  => 'Invoice {invoice} was cancelled. You do not need to pay it. Tap to see why. {contact}',
        ],
        'service_invoice_payment_received' => [
            'title' => 'Payment Received',
            'body'  => 'We received your payment of {amount} for invoice {invoice}.{balance_note} {contact}',
        ],
        'service_invoice_balance_due' => [
            'title' => 'Balance Due Now',
            'body'  => 'Your service is done. The remaining balance of {amount} for invoice {invoice} is due. You can pay it online from your invoice page. {contact}',
        ],
        'service_invoice_fully_paid' => [
            'title' => 'Invoice Fully Paid',
            'body'  => 'Invoice {invoice} is fully paid. Thank you. We received {amount} in total. {contact}',
        ],
        'service_invoice_payment_failed' => [
            'title' => 'Payment Could Not Be Completed',
            'body'  => 'Your payment of {amount} for invoice {invoice} could not be completed and you were not charged. You can try again from your invoice page. {contact}',
        ],
        'service_invoice_paid' => [
            'title' => 'Payment Recorded',
            'body'  => 'We recorded your payment of {amount} for invoice {invoice} on {when}. Thank you. The paid invoice is available on your service requests page. {contact}',
        ],

        /* ── customer: Other Services cancellations and refunds (Stage 21f) ── */
        'service_request_cancelled' => [
            'title' => 'Your Service Request Was Cancelled',
            'body'  => 'Our team cancelled your service request {reference}. You do not need to pay anything more for it. Tap to see the details. {contact}',
        ],
        'refund_received' => [
            'title' => 'Cancellation Request Received',
            'body'  => 'We received your cancellation request {request} for service request {reference}. Payments on this service are paused until we reply. If a refund is approved, it takes up to 7 days after approval. {contact}',
        ],
        'refund_needs_info' => [
            'title' => 'We Need a Little More Information',
            'body'  => 'About your cancellation request {request}: {message} Please reply from the request page. {contact}',
        ],
        'refund_approved' => [
            'title' => 'Your Request Was Approved',
            'body'  => 'Your cancellation request {request} was approved. {refund_line} {contact}',
        ],
        'refund_refunded' => [
            'title' => 'Your Refund Was Sent',
            'body'  => 'We sent your refund of {amount} for request {request} by {method}. Transaction number: {transaction}. {contact}',
        ],
        'refund_problem' => [
            'title' => 'Your Refund Needs Your Attention',
            'body'  => 'We could not send your refund for request {request}. {problem} Please send the correct details from the request page and we will try again. {contact}',
        ],
        'refund_declined' => [
            'title' => 'Your Request Was Not Approved',
            'body'  => 'We could not approve your cancellation request {request}. {explanation} Your service request stays open. {contact}',
        ],
        'refund_cancelled_no_refund' => [
            'title' => 'Service Request Cancelled',
            'body'  => 'Your service request {reference} was cancelled (request {request}). No payment was made, so there is nothing to refund. {contact}',
        ],

        /* ── admin: cancellations and refunds ── */
        'admin_refund_request' => [
            'title' => 'New Cancellation Request',
            'body'  => '{customer} asked to cancel service request {reference} ({request}). {amount_line} Work state: {work}.',
        ],
        'admin_refund_reply' => [
            'title' => 'Customer Replied on a Cancellation Request',
            'body'  => '{customer} replied on cancellation request {request}.',
        ],

        /* ── admin ── */
        'admin_new_request' => [
            'title' => 'New Custom Order Request',
            'body'  => '{customer} sent a request for {order}.{window_line} Phone: {phone}.',
        ],
        'admin_new_inquiry' => [
            'title' => 'New Service Request',
            'body'  => '{customer} sent a service request ({reference}) about {service}.{window_line} Phone: {phone}.',
        ],
        'admin_request_updated' => [
            'title' => 'Custom Request Updated',
            'body'  => '{customer} updated their request for {order}.',
        ],
        'admin_quote_declined' => [
            'title' => 'Quote Declined',
            'body'  => '{customer} declined the quote for {order}.{reason_line}',
        ],
        'admin_quote_expired' => [
            'title' => 'Quote Expired',
            'body'  => 'The quote for {order} ({customer}) expired without a reply.',
        ],
        'admin_agreement_accepted' => [
            'title' => 'Agreement Accepted',
            'body'  => '{customer} accepted agreement {agreement} for {order}. The total is {total}. Agreed on {when}.',
        ],
        'admin_agreement_expired' => [
            'title' => 'Agreement Expired Unpaid',
            'body'  => 'Agreement {agreement} for {order} ({customer}) expired because no payment was started by {when}.',
        ],
        'admin_integrity_failed' => [
            'title' => 'Agreement Failed Its Integrity Check',
            'body'  => 'Agreement {agreement} no longer matches its recorded SHA-256 and was blocked from display. Please investigate before relying on it.',
        ],
    ];

    /** @return array{title: string, message: string} */
    public static function render(string $key, array $data = [], ?BusinessSetting $business = null): array
    {
        $template = self::TEMPLATES[$key] ?? throw new \InvalidArgumentException("Unknown notification template: {$key}");

        $vars = self::variables($data, $business ?? BusinessSetting::current());

        return [
            'title'   => self::fill($template['title'], $vars),
            'message' => self::fill($template['body'], $vars),
        ];
    }

    public static function message(string $key, array $data = [], ?BusinessSetting $business = null): string
    {
        return self::render($key, $data, $business)['message'];
    }

    /** The configured business phone, or '' when it is empty or the built-in placeholder. */
    public static function phone(?BusinessSetting $business = null): string
    {
        return self::real(($business ?? BusinessSetting::current())->phone, self::PLACEHOLDER_PHONE);
    }

    /** The configured business email, or '' when it is empty or the built-in placeholder. */
    public static function email(?BusinessSetting $business = null): string
    {
        return self::real(($business ?? BusinessSetting::current())->email, self::PLACEHOLDER_EMAIL);
    }

    /** "You can reach us at 0917 or hello@x.com." or the website fallback. */
    public static function contact(?BusinessSetting $business = null): string
    {
        $business ??= BusinessSetting::current();

        $phone = self::real($business->phone, self::PLACEHOLDER_PHONE);
        $email = self::real($business->email, self::PLACEHOLDER_EMAIL);

        return match (true) {
            $phone !== '' && $email !== '' => "You can reach us at {$phone} or {$email}.",
            $phone !== ''                  => "You can reach us at {$phone}.",
            $email !== ''                  => "You can reach us at {$email}.",
            default                        => 'You can reach our team through our website.',
        };
    }

    /** The unfilled templates for the admin's live preview (placeholders stay, values are separate). */
    public static function callTemplates(): array
    {
        return [
            'unreachable' => self::TEMPLATES['call_unreachable']['body'],
            'ringing'     => self::TEMPLATES['call_ringing']['body'],
            'answered'    => self::TEMPLATES['call_answered']['body'],
        ];
    }

    public static function callTitles(): array
    {
        return [
            'unreachable' => self::TEMPLATES['call_unreachable']['title'],
            'ringing'     => self::TEMPLATES['call_ringing']['title'],
            'answered'    => self::TEMPLATES['call_answered']['title'],
        ];
    }

    /* ───────────── internals ───────────── */

    private static function real(?string $value, string $placeholder): string
    {
        $value = trim((string) $value);

        return ($value === '' || $value === $placeholder) ? '' : $value;
    }

    private static function variables(array $d, BusinessSetting $business): array
    {
        $peso = fn ($n) => '₱' . number_format((float) $n, 2);
        $has  = fn (string $k) => isset($d[$k]) && $d[$k] !== '' && $d[$k] !== null;

        $vars = [
            'order'        => $has('order') ? (string) $d['order'] : CustomOrderName::BASE,
            'customer'     => $has('customer') ? (string) $d['customer'] : 'your account',
            'phone'        => $has('phone') ? (string) $d['phone'] : 'the phone number you gave us',
            'order_number' => $has('order_number') ? (string) $d['order_number'] : 'your order',
            'agreement'    => $has('agreement') ? (string) $d['agreement'] : 'your agreement',
            'reference'    => $has('reference') ? (string) $d['reference'] : 'shown on your request',
            'invoice'      => $has('invoice') ? (string) $d['invoice'] : 'your invoice',
            'service'      => $has('service') ? (string) $d['service'] : 'an Other Services request',
            'times'        => $has('times') ? (string) $d['times'] : 'a few times',
            'when'         => $has('when') ? (string) $d['when'] : 'the date shown on your account',
            'due'          => $has('due') ? (string) $d['due'] : 'the due date shown on your account',
            'expiry'       => $has('expiry') ? (string) $d['expiry'] : 'the date shown on your quote',
            'total'        => $has('total') ? $peso($d['total']) : 'shown on your quote',
            'upfront'      => $has('upfront') ? $peso($d['upfront']) : 'the amount shown on your agreement',
            'amount'       => $has('amount') ? $peso($d['amount']) : 'your payment',
            'contact'      => self::contact($business),
            'window_phrase' => $has('window') ? 'during your preferred time (' . $d['window'] . ') ' : '',
        ];

        // Optional sentences: empty when their data is missing.
        $vars['window_line']   = $has('window') ? ' Preferred call time: ' . $d['window'] . '.' : '';
        $vars['reason_line']   = $has('reason') ? ' Reason: ' . rtrim(trim((string) $d['reason']), '.') . '.' : '';
        $vars['expiry_line']   = $has('expiry') ? ' Please review and accept it by ' . $d['expiry'] . '.' : ' Please review and accept it as soon as you can.';
        $vars['previous_line'] = $has('previous_total') ? ' The earlier total was ' . $peso($d['previous_total']) . '.' : '';
        $vars['confirmed_line'] = !empty($d['confirmed']) ? ' Your order is now confirmed.' : '';
        $vars['ready_line']    = $has('ready_date') ? ' The expected ready date is about ' . $d['ready_date'] . '.' : '';

        if (array_key_exists('balance', $d) && $d['balance'] !== null) {
            $vars['balance_line'] = (float) $d['balance'] > 0.004
                ? ' The remaining balance is ' . $peso($d['balance']) . '.'
                : ' Your order is paid in full.';
        } else {
            $vars['balance_line'] = '';
        }

        // Anything else passed in (order_number, window, ...) is available verbatim.
        foreach ($d as $k => $v) {
            if (is_scalar($v) && !array_key_exists($k, $vars)) {
                $vars[$k] = (string) $v;
            }
        }

        return $vars;
    }

    private static function fill(string $text, array $vars): string
    {
        $map = [];
        foreach ($vars as $k => $v) {
            $map['{' . $k . '}'] = $v;
        }

        $text = strtr($text, $map);
        $text = (string) preg_replace('/\{[a-z_]+\}/', '', $text);          // never leave a placeholder behind
        $text = (string) preg_replace('/[ \t]{2,}/', ' ', $text);
        $text = (string) preg_replace('/\s+([.,])/u', '$1', $text);

        return trim($text);
    }
}
