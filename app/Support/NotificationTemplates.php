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
