<?php

namespace App\Support;

use Carbon\Carbon;

/** Shop hours as a short sentence, read from config('luma.hours') so the footer never hard-codes them. */
class ShopHours
{
    private const DAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** e.g. "Mon to Fri, 8:00 AM to 5:00 PM"; null when no hours are configured. */
    public static function label(): ?string
    {
        $h = config('luma.hours');
        if (!is_array($h) || empty($h['days']) || empty($h['open']) || empty($h['close'])) {
            return null;
        }

        $days = array_values(array_unique(array_map('intval', $h['days'])));
        sort($days);
        $days = array_values(array_filter($days, fn ($d) => isset(self::DAYS[$d])));
        if (!$days) {
            return null;
        }

        $contiguous = count($days) > 2 && $days === range($days[0], $days[0] + count($days) - 1);
        $dayText = $contiguous
            ? self::DAYS[$days[0]] . ' to ' . self::DAYS[end($days)]
            : implode(', ', array_map(fn ($d) => self::DAYS[$d], $days));

        return $dayText . ', ' . self::time($h['open']) . ' to ' . self::time($h['close']);
    }

    private static function time(string $hhmm): string
    {
        return Carbon::createFromFormat('H:i', $hhmm)->format('g:i A');
    }
}
