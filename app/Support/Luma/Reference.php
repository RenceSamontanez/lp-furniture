<?php

namespace App\Support\Luma;

/**
 * Finds REF-YYYY-NNNN (cancellation request), INV-YYYY-NNNN (invoice) and SI-YYYY-NNNN (service request) numbers in a
 * message: any case, with or without dashes, spaces or dots, short digit groups padded ("ref 2026 2" = REF-2026-0002),
 * pasted alone or in a sentence. It only reads the text; whether the number belongs to the customer is decided
 * by StatusAnswers, which answers a stranger exactly like a number that does not exist.
 */
final class Reference
{
    private const PREFIXES = [
        'REF' => 'REF', 'REFF' => 'REF', 'RF' => 'REF',
        'INV' => 'INV', 'INVC' => 'INV', 'INVOICE' => 'INV',
        'SI' => 'SI', 'S1' => 'SI',
    ];

    private const PATTERN = '/(?<![A-Za-z0-9])(REFF|REF|RF|INVOICE|INVC|INV|SI|S1)[\s\-_.#:]*((?:19|20)\d{2})[\s\-_.]*(\d{1,6})(?!\d)/i';

    /**
     * @return list<array{type: string, number: string}> distinct references in the order written (three at most)
     */
    public static function find(string $message): array
    {
        if (!preg_match_all(self::PATTERN, $message, $all, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($all as $m) {
            $type   = self::PREFIXES[strtoupper($m[1])];
            $number = $type . '-' . $m[2] . '-' . str_pad(ltrim($m[3], '0') === '' ? '0' : $m[3], 4, '0', STR_PAD_LEFT);
            $out[$number] = ['type' => $type, 'number' => $number];
        }

        return array_slice(array_values($out), 0, 3);
    }

    /** The message with every reference number blanked out, for the logs and for matching what else was said. */
    public static function strip(string $message): string
    {
        return trim(preg_replace(self::PATTERN, ' ', $message) ?? $message);
    }
}
