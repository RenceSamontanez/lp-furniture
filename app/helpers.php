<?php

use App\Support\TitleCase;

if (!function_exists('title_case')) {
    /** Title Case for display text; see App\Support\TitleCase. Display only, escape the result like any text. */
    function title_case(?string $text): string
    {
        return TitleCase::apply($text);
    }
}
