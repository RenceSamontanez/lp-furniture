<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Production time counts Monday to Saturday (D3); only Sundays are
 * skipped. No holiday calendar.
 */
final class WorkingDays
{
    /** The date that is $days working days after $from (day 0 is not counted). */
    public static function add(CarbonInterface $from, int $days): Carbon
    {
        $date = Carbon::instance($from)->startOfDay();

        while ($days > 0) {
            $date->addDay();
            if (!$date->isSunday()) {
                $days--;
            }
        }

        return $date;
    }
}
