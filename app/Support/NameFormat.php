<?php

namespace App\Support;

/**
 * Display helpers for names. Stored data is never changed.
 */
final class NameFormat
{
    /** "RENCE SAMONTANEZ" or "rence samontanez" -> "Rence Samontanez" (also after hyphens and apostrophes). */
    public static function proper(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8');
    }
}
