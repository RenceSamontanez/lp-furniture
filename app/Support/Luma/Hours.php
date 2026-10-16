<?php

namespace App\Support\Luma;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Shop hours from config/luma.php, always read in Asia/Manila: "are you open?" is answered from the
 * Manila time of the question, not the server's clock. Public holidays are not known.
 */
final class Hours
{
    private const DAYS_EN = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    private const DAYS_TL = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miyerkules', 4 => 'Huwebes', 5 => 'Biyernes', 6 => 'Sabado', 7 => 'Linggo'];

    public static function now(): Carbon
    {
        return Carbon::now(config('luma.timezone', 'Asia/Manila'));
    }

    public static function isOpen(?CarbonInterface $at = null): bool
    {
        $at = self::inShopTime($at);

        if (!in_array($at->isoWeekday(), config('luma.hours.days'), true)) {
            return false;
        }

        return $at->greaterThanOrEqualTo(self::at($at, config('luma.hours.open')))
            && $at->lessThan(self::at($at, config('luma.hours.close')));
    }

    /** The next moment the shop opens (today if it has not opened yet, otherwise the next working day). */
    public static function nextOpening(?CarbonInterface $at = null): Carbon
    {
        $at  = self::inShopTime($at);
        $day = $at->copy();

        for ($i = 0; $i < 8; $i++) {
            if (in_array($day->isoWeekday(), config('luma.hours.days'), true)) {
                $open = self::at($day, config('luma.hours.open'));
                if ($open->greaterThan($at)) {
                    return $open;
                }
            }
            $day = $day->copy()->addDay();
        }

        return $at; // no working days configured
    }

    /** "8:00 AM to 5:00 PM, Monday to Friday" */
    public static function text(string $lang): string
    {
        $tl   = $lang === Language::TL;
        $from = self::clock(config('luma.hours.open'));
        $to   = self::clock(config('luma.hours.close'));

        return $tl
            ? "{$from} hanggang {$to}, " . self::dayRange(true)
            : "{$from} to {$to}, " . self::dayRange(false);
    }

    /** Whether the shop is open at the Manila time of the question, and who answers when it is not. */
    public static function status(string $lang, ?CarbonInterface $at = null): string
    {
        $tl = $lang === Language::TL;

        if (self::isOpen($at)) {
            return $tl ? 'Bukas po kami ngayon.' : "We're open right now.";
        }

        $at   = self::inShopTime($at);
        $next = self::nextOpening($at);
        $from = self::clock(config('luma.hours.open'));

        if ($next->isSameDay($at)) {
            return $tl
                ? "Sarado pa po kami ngayon. Sasagot po ang aming team pagbukas namin ng {$from} ngayong araw."
                : "We're closed right now. Our team will reply when we open at {$from} today.";
        }

        $names = $tl ? self::DAYS_TL : self::DAYS_EN;
        $day   = $names[$next->isoWeekday()];

        return $tl
            ? "Sarado po kami ngayon. Sasagot po ang aming team sa susunod na araw ng trabaho, {$day}, simula {$from}."
            : "We're closed right now. Our team will reply on the next working day, {$day}, from {$from}.";
    }

    private static function inShopTime(?CarbonInterface $at): Carbon
    {
        return $at ? Carbon::instance($at)->setTimezone(config('luma.timezone', 'Asia/Manila')) : self::now();
    }

    private static function at(CarbonInterface $day, string $hhmm): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return Carbon::instance($day)->setTimezone(config('luma.timezone', 'Asia/Manila'))->setTime($h, $m);
    }

    private static function clock(string $hhmm): string
    {
        [$h, $m] = array_map('intval', explode(':', $hhmm));

        return Carbon::createFromTime($h, $m)->format('g:i A');
    }

    private static function dayRange(bool $tl): string
    {
        $names = $tl ? self::DAYS_TL : self::DAYS_EN;
        $days  = array_values(config('luma.hours.days'));
        sort($days);

        if (count($days) > 1 && $days === range($days[0], end($days))) {
            return $names[$days[0]] . ($tl ? ' hanggang ' : ' to ') . $names[end($days)];
        }

        return implode(', ', array_map(fn ($d) => $names[$d], $days));
    }
}
