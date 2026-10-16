<?php

namespace App\Support\Luma;

/**
 * Tagalog or English? A message is Tagalog when its Tagalog marker words and particles outweigh its
 * common English words; "po" and "opo" count triple. No marker words at all means English.
 */
final class Language
{
    public const EN = 'en';
    public const TL = 'tl';

    /** Tagalog markers (plain, lower case). Strong ones weigh 2, "po"/"opo" 3, the rest 1. */
    private const STRONG = [
        'magkano', 'presyo', 'paano', 'pano', 'gaano', 'katagal', 'kailan', 'saan', 'meron', 'mayroon',
        'kahoy', 'libre', 'salamat', 'kumusta', 'kamusta', 'pwede', 'puwede', 'gusto', 'ano', 'bakit',
        'pintuan', 'pinto', 'mesa', 'upuan', 'kama', 'ipagawa', 'pagawa', 'bayad', 'hulugan', 'kayo',
        'ninyo', 'niyo', 'nyo', 'namin', 'natin', 'hindi', 'wala', 'sino', 'ilang', 'magandang', 'tulong',
        'sukat', 'aparador', 'silya', 'lamesa', 'estante', 'sopa',
    ];

    private const WEAK = [
        'ako', 'ko', 'mo', 'ba', 'ang', 'ng', 'sa', 'ay', 'na', 'mga', 'yung', 'iyong', 'kung', 'para',
        'kasi', 'lang', 'naman', 'dito', 'doon', 'ito', 'iyan', 'sige', 'din', 'rin', 'po', 'opo', 'nyo',
        'kami', 'tayo', 'mag', 'umaga', 'hapon', 'gabi', 'araw', 'buwan', 'at', 'ni', 'kay', 'sana',
    ];

    private const ENGLISH = [
        'the', 'is', 'are', 'do', 'does', 'you', 'your', 'i', 'can', 'to', 'of', 'how', 'what', 'where',
        'when', 'much', 'long', 'have', 'for', 'my', 'me', 'we', 'it', 'a', 'an', 'and', 'or', 'will',
        'would', 'could', 'there', 'this', 'that', 'with', 'about', 'please', 'want', 'need', 'about',
    ];

    public static function detect(string $message): string
    {
        $tl = 0;
        $en = 0;

        foreach (explode(' ', Text::plain($message)) as $word) {
            if ($word === '') {
                continue;
            }
            if ($word === 'po' || $word === 'opo') {
                $tl += 3;
            } elseif (in_array($word, self::STRONG, true)) {
                $tl += 2;
            } elseif (in_array($word, self::WEAK, true)) {
                $tl += 1;
            } elseif (in_array($word, self::ENGLISH, true)) {
                $en += 1;
            }
        }

        return $tl > 0 && $tl >= $en ? self::TL : self::EN;
    }
}
