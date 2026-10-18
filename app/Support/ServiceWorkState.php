<?php

namespace App\Support;

use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;

/**
 * Where the actual work on an Other Services ticket stands: not_started, started or done.
 * Started = the admin tapped "Work started" (or "Service done", which starts it if it was not). Done = the
 * live invoice has "Service done". Used by every admin and customer page and by the refund rules, so there is
 * one definition of "has the laborer started".
 */
final class ServiceWorkState
{
    public const NOT_STARTED = 'not_started';
    public const STARTED     = 'started';
    public const DONE        = 'done';

    public const LABELS = [
        self::NOT_STARTED => 'Not started',
        self::STARTED     => 'Work started',
        self::DONE        => 'Done',
    ];

    public static function for(ServiceInquiry $ticket): string
    {
        $done = ServiceInvoice::where('service_inquiry_id', $ticket->id)
            ->whereIn('status', [ServiceInvoice::ISSUED, ServiceInvoice::PAID])
            ->whereNotNull('service_done_at')->exists();

        return match (true) {
            $done                       => self::DONE,
            (bool) $ticket->service_started_at => self::STARTED,
            default                     => self::NOT_STARTED,
        };
    }

    public static function label(string $state): string
    {
        return self::LABELS[$state] ?? $state;
    }

    /** Refunds are automatic-eligible (can be approved normally) only before the work starts. */
    public static function refundable(string $state): bool
    {
        return $state === self::NOT_STARTED;
    }
}
