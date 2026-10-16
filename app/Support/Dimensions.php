<?php

namespace App\Support;

/**
 * Length x Width x Height, in the customer's unit, stored in centimetres.
 *
 *   Length  the longest horizontal side
 *   Width   the shorter horizontal side
 *   Height  from the floor to the top
 *
 * Conversion happens on the server only. The canonical value is centimetres
 * with 2 decimals.
 *
 * Rounding rule: the value is multiplied by the exact unit factor (cm 1,
 * mm 0.1, m 100, in 2.54, ft 30.48), first rounded to 6 decimals to remove
 * floating point noise, then rounded to 2 decimals with halves rounded up
 * (away from zero). So 1.005 in = 2.5527 cm = 2.55 cm and 0.25 mm = 0.025 cm
 * = 0.03 cm.
 *
 * Old rows: the three numbers stored before this existed were
 * "Width x Depth x Height" in cm. They are read as Length x Width x Height
 * (old Width = Length, old Depth = Width, Height unchanged); see legacy().
 */
final class Dimensions
{
    public const DEFAULT_UNIT = 'cm';

    /** Centimetres in one of each unit. */
    public const FACTORS = [
        'cm' => 1.0,
        'mm' => 0.1,
        'm'  => 100.0,
        'in' => 2.54,
        'ft' => 30.48,
    ];

    /** Labels for the unit selector, default first. */
    public const UNITS = [
        'cm' => 'cm (centimetres)',
        'in' => 'inch',
        'ft' => 'ft (feet)',
        'm'  => 'm (metres)',
        'mm' => 'mm (millimetres)',
    ];

    public const MIN_CM = 5.0;
    public const MAX_CM = 600.0;

    public const SIDES = ['length' => 'Length', 'width' => 'Width', 'height' => 'Height'];

    /** Decimals shown when a centimetre value is written in another unit. */
    private const DISPLAY_PLACES = ['mm' => 0, 'cm' => 2, 'in' => 1, 'm' => 2, 'ft' => 2];

    public static function isUnit(?string $unit): bool
    {
        return $unit !== null && array_key_exists($unit, self::FACTORS);
    }

    /** Centimetres with 2 decimals (rule above). */
    public static function toCm(float|int|string $value, string $unit): float
    {
        if (!self::isUnit($unit)) {
            throw new \InvalidArgumentException("Unknown unit: {$unit}");
        }

        return round(round((float) $value * self::FACTORS[$unit], 6), 2, PHP_ROUND_HALF_UP);
    }

    /** A centimetre value in another unit, rounded for display (mm 0, cm 2, in 1, m 2, ft 2 decimals). */
    public static function fromCm(float|int|string $cm, string $unit): float
    {
        if (!self::isUnit($unit)) {
            throw new \InvalidArgumentException("Unknown unit: {$unit}");
        }

        return round(round((float) $cm / self::FACTORS[$unit], 6), self::DISPLAY_PLACES[$unit], PHP_ROUND_HALF_UP);
    }

    /** "120", "47.2", "2.5": no trailing zeros. */
    public static function number(float|int|string $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, '.', ''), '0'), '.');
    }

    /** "120 cm", "47.2 in". */
    public static function one(float|int|string $cm, string $unit = self::DEFAULT_UNIT): string
    {
        return self::number(self::fromCm($cm, $unit)) . ' ' . $unit;
    }

    /** "120 cm (47.2 in)": centimetres first, then the customer's unit. Plain "120 cm" for cm. */
    public static function display(float|int|string $cm, ?string $unit = null): string
    {
        $unit = self::isUnit($unit) ? $unit : self::DEFAULT_UNIT;
        $base = self::number($cm) . ' cm';

        return $unit === 'cm' ? $base : $base . ' (' . self::one($cm, $unit) . ')';
    }

    /**
     * Length x Width x Height in the customer's unit and in cm:
     * "47.2 x 23.6 x 29.5 in (120 x 60 x 75 cm)", or "120 x 60 x 75 cm" for cm.
     */
    public static function triplet(float|int|string|null $length, float|int|string|null $width, float|int|string|null $height, ?string $unit = null): string
    {
        if ($length === null || $width === null || $height === null || $length === '' || $width === '' || $height === '') {
            return '—';
        }

        $unit = self::isUnit($unit) ? $unit : self::DEFAULT_UNIT;
        $cm = self::number($length) . ' x ' . self::number($width) . ' x ' . self::number($height) . ' cm';

        if ($unit === 'cm') {
            return $cm;
        }

        $own = self::number(self::fromCm($length, $unit)) . ' x ' . self::number(self::fromCm($width, $unit))
            . ' x ' . self::number(self::fromCm($height, $unit)) . ' ' . $unit;

        return "{$own} ({$cm})";
    }

    /**
     * Old rows. "Width x Depth x Height cm" text, read as Length x Width x Height.
     * Returns null when there is nothing usable.
     *
     * @return array{length: float, width: float, height: float}|null
     */
    public static function legacy(?string $text): ?array
    {
        [$a, $b, $c] = DimensionsFormatter::parse($text);

        if ($a === null || !is_numeric($a) || !is_numeric($b) || !is_numeric($c)) {
            return null;
        }

        return ['length' => (float) $a, 'width' => (float) $b, 'height' => (float) $c];
    }

    /** The error for one side that is outside the allowed range, in the customer's unit. */
    public static function rangeMessage(string $side, string $unit): string
    {
        $unit = self::isUnit($unit) ? $unit : self::DEFAULT_UNIT;
        $min = self::number(self::fromCm(self::MIN_CM, $unit)) . ' ' . $unit;
        $max = self::number(self::fromCm(self::MAX_CM, $unit)) . ' ' . $unit;

        return "{$side} must be between {$min} and {$max}.";
    }

    /**
     * Validate and convert what the form posted. Optional: three empty fields give null.
     * All three or none. Every side is converted here and must land between MIN_CM and MAX_CM.
     *
     * @return array{values: ?array{length_cm: float, width_cm: float, height_cm: float, unit: string}, errors: array<string,string>}
     */
    public static function fromInput(mixed $length, mixed $width, mixed $height, mixed $unit): array
    {
        $raw = ['length' => $length, 'width' => $width, 'height' => $height];
        $filled = array_filter($raw, fn ($v) => $v !== null && trim((string) $v) !== '');

        if ($filled === []) {
            return ['values' => null, 'errors' => []];
        }

        $unit = ($unit === null || $unit === '') ? self::DEFAULT_UNIT : (string) $unit;

        if (!self::isUnit($unit)) {
            return ['values' => null, 'errors' => ['dimension_unit' => 'Choose a unit: cm, inch, ft, m or mm.']];
        }

        $errors = [];
        $cm = [];

        foreach ($raw as $key => $v) {
            $label = self::SIDES[$key];

            if ($v === null || trim((string) $v) === '') {
                $errors["dimension_{$key}"] = "Enter the {$key} too, or leave all three size fields empty.";
                continue;
            }

            $v = str_replace(',', '.', trim((string) $v));
            if (!preg_match('/^\d{1,6}(\.\d{1,4})?$/', $v)) {
                $errors["dimension_{$key}"] = "{$label} must be a number, for example 120 or 47.5.";
                continue;
            }

            $value = self::toCm($v, $unit);
            if ($value < self::MIN_CM || $value > self::MAX_CM) {
                $errors["dimension_{$key}"] = self::rangeMessage($label, $unit);
                continue;
            }

            $cm[$key] = $value;
        }

        if ($errors !== []) {
            return ['values' => null, 'errors' => $errors];
        }

        return ['values' => ['length_cm' => $cm['length'], 'width_cm' => $cm['width'], 'height_cm' => $cm['height'], 'unit' => $unit], 'errors' => []];
    }
}
