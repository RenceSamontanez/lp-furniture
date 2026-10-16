<?php

namespace App\Support;

use App\Models\CustomDesignRequest;

/**
 * The fixed customer messages for the admin's "Send call update" tool.
 * The admin picks an outcome and a number of attempts; the wording is
 * not editable (only an optional extra line is appended). The wording lives
 * in NotificationTemplates; the page's live preview is given the same
 * templates and values, so the preview is the message that is sent.
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

    public static function needsAttempts(string $outcome): bool
    {
        return $outcome !== self::ANSWERED;
    }

    /** "1 time" / "3 times" */
    public static function times(int $n): string
    {
        return $n . ' ' . ($n === 1 ? 'time' : 'times');
    }

    public static function templateKey(string $outcome): string
    {
        return 'call_' . $outcome;
    }

    /** Title and message for the customer's notification. */
    public static function render(string $outcome, CustomDesignRequest $request, int $attempts, ?string $note = null): array
    {
        $t = NotificationTemplates::render(self::templateKey($outcome), [
            'order'    => $request->order_name,
            'times'    => self::times(max(1, $attempts)),
            'customer' => NameFormat::proper(trim($request->user->first_name . ' ' . $request->user->last_name)),
        ]);

        $note = $note !== null ? trim($note) : '';
        if ($note !== '') {
            $t['message'] .= "\n" . $note;
        }

        return $t;
    }

    public static function build(string $outcome, CustomDesignRequest $request, int $attempts, ?string $note = null): string
    {
        return self::render($outcome, $request, $attempts, $note)['message'];
    }

    /** What the page's live preview needs: the templates and the filled-in values. */
    public static function previewData(CustomDesignRequest $request): array
    {
        return [
            'templates' => NotificationTemplates::callTemplates(),
            'titles'    => NotificationTemplates::callTitles(),
            'values'    => [
                'order'    => $request->order_name,
                'customer' => NameFormat::proper(trim($request->user->first_name . ' ' . $request->user->last_name)),
                'contact'  => NotificationTemplates::contact(),
            ],
        ];
    }
}
