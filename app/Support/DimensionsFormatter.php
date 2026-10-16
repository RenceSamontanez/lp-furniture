<?php

namespace App\Support;

// Shared by Admin\ProductController and CustomDesignController — both
// split a single "L x W x H cm" text column into three numeric form
// fields (Length/Width/Height) and recombine them on save. Originally
// lived only on Admin\ProductController; extracted here once the AI
// Design form needed the exact same pattern, so the parse/format rules
// can't drift between the two forms.
class DimensionsFormatter
{
    public static function parse(?string $dimensions): array
    {
        if ($dimensions && preg_match('/^\s*([\d.]+)\s*[x×*]\s*([\d.]+)\s*[x×*]\s*([\d.]+)\s*(?:cm)?\s*$/i', $dimensions, $m)) {
            return [$m[1], $m[2], $m[3]];
        }

        return [null, null, null];
    }

    public static function format($length, $width, $height): string
    {
        $fmt = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');

        return "{$fmt($length)} x {$fmt($width)} x {$fmt($height)} cm";
    }

    // All-or-nothing combine: three separate optional inputs only ever
    // produce a meaningful "L x W x H cm" string when all three are
    // present — a partial set (e.g. width but no height) is discarded
    // rather than stored as a misleading half-complete value.
    public static function combine($length, $width, $height): ?string
    {
        return ($length !== null && $width !== null && $height !== null)
            ? self::format($length, $width, $height)
            : null;
    }
}
