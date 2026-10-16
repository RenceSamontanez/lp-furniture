<?php

namespace App\Support;

use App\Models\BusinessSetting;
use App\Models\CustomDesignRequest;

/**
 * The fixed customer messages for the admin's "Send call update" tool.
 * The admin picks an outcome and a number of attempts; the wording is
 * not editable (only an optional extra line is appended). The same
 * templates are given to the page for the live preview, so the preview
 * is the message that is sent.
 */
final class CallUpdateMessage
{
    public const UNREACHABLE = 'unreachable';
    public const RINGING     = 'ringing';
    public const ANSWERED    = 'answered';

    public const LABELS = [
        self::UNREACHABLE => 'Unreachable',
        self::RINGING     => 'Ringing but not answering',
        self::ANSWERED    => 'Answered',
    ];

    /** Pipeline status each outcome leaves the request in. */
    public const STATUS = [
        self::UNREACHABLE => 'unreachable',
        self::RINGING     => 'contacting',
        self::ANSWERED    => 'contacting',
    ];

    public const TEMPLATES = [
        self::UNREACHABLE => 'We tried calling you {times} about your custom furniture request ({design}), but your phone seems to be out of service or not ringing. Please contact us at {phone} or {email}. Tell us your account name ({customer}) and remind us which custom furniture you wanted to discuss, so we can continue the negotiation.',
        self::RINGING     => 'We called you {times} about your custom furniture request ({design}), but nobody answered. Please call us back at {phone} or email {email}. Tell us your account name ({customer}) and remind us which custom furniture you wanted to discuss.',
        self::ANSWERED    => 'Thank you for speaking with us about your custom furniture request ({design}). Our team is preparing your quote and you will be notified here as soon as it is ready.',
    ];

    public static function needsAttempts(string $outcome): bool
    {
        return $outcome !== self::ANSWERED;
    }

    /** "1 time" / "3 times" */
    public static function times(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'time' : 'times');
    }

    public static function build(string $outcome, CustomDesignRequest $request, int $attempts, ?string $note = null): string
    {
        $business = BusinessSetting::current();
        $customer = NameFormat::proper(trim($request->user->first_name . ' ' . $request->user->last_name));

        $text = strtr(self::TEMPLATES[$outcome], [
            '{times}'    => self::times(max(1, $attempts)),
            '{design}'   => $request->order_name,
            '{phone}'    => $business->phone,
            '{email}'    => $business->email,
            '{customer}' => $customer,
        ]);

        $note = $note !== null ? trim($note) : '';

        return $note !== '' ? $text . "\n" . $note : $text;
    }

    /** What the page's live preview needs: the templates and the filled-in values. */
    public static function previewData(CustomDesignRequest $request): array
    {
        $business = BusinessSetting::current();

        return [
            'templates' => self::TEMPLATES,
            'values'    => [
                'design'   => $request->order_name,
                'phone'    => $business->phone,
                'email'    => $business->email,
                'customer' => NameFormat::proper(trim($request->user->first_name . ' ' . $request->user->last_name)),
            ],
        ];
    }
}
