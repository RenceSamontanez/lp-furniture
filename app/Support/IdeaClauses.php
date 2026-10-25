<?php

namespace App\Support;

/**
 * Finds the parts of a customer's description we have no vocabulary for, so the team can review them
 * (UnansweredIdea, kind "idea"). Deliberately conservative: a part only counts when it is a short phrase
 * of two or more words that touches nothing in the vocabulary below, and at most three are kept per description.
 * This is a heuristic for a review list, never a reason to refuse a description.
 */
final class IdeaClauses
{
    public const MAX_PER_DESCRIPTION = 3;
    public const MAX_LENGTH = 60;

    /** Word stems: a clause that contains any of them is considered understood. */
    private const KNOWN = [
        // size, shape, finish, place (the same groups the page's description meter looks for)
        'seat', 'small', 'large', 'big', 'tall', 'wide', 'king', 'queen', 'single', 'double', 'cm', 'inch', 'feet', 'foot', 'meter', 'metre', 'size', 'long', 'short', 'thick', 'thin', 'slim', 'compact', 'height',
        'round', 'square', 'rect', 'curve', 'straight', 'edge', 'leg', 'panel', 'shape', 'oval', 'corner', 'angle', 'arch', 'carv', 'turn',
        'finish', 'matte', 'gloss', 'polish', 'stain', 'varnish', 'color', 'colour', 'tone', 'dark', 'light', 'natural', 'brown', 'white', 'black', 'grain', 'texture', 'paint', 'lacquer', 'oil', 'wax', 'distress', 'tone',
        'living', 'bedroom', 'dining', 'kitchen', 'office', 'room', 'outdoor', 'space', 'wall', 'entrance', 'bath', 'garden', 'patio', 'porch', 'apartment', 'house', 'hall',
        // parts and furniture words
        'drawer', 'shelf', 'shelves', 'door', 'handle', 'knob', 'hinge', 'cushion', 'upholster', 'fabric', 'leather', 'foam', 'storage', 'compartment', 'glass', 'metal', 'steel', 'iron', 'brass',
        'wood', 'narra', 'mahogany', 'acacia', 'gmelina', 'rattan', 'cane', 'bamboo', 'top', 'back', 'arm', 'frame', 'base', 'rail', 'post', 'headboard', 'footboard', 'mattress', 'table', 'chair', 'sofa', 'bed', 'desk', 'cabinet',
        'style', 'modern', 'rustic', 'classic', 'minimal', 'contemporary', 'vintage', 'farmhouse', 'industrial', 'scandinavian', 'design', 'look',
    ];

    /** @return list<string> */
    public static function unmatched(string $description): array
    {
        $found = [];

        foreach (preg_split('/[\n.,;:!?]+|\band\b|\bwith\b/iu', $description, -1, PREG_SPLIT_NO_EMPTY) as $part) {
            $clause = trim(preg_replace('/\s+/u', ' ', $part));
            $lower = mb_strtolower($clause);

            if (TextSanitizer::wordCount($clause) < 2 || mb_strlen($clause) > self::MAX_LENGTH || preg_match('/\d/', $clause)) {
                continue;
            }
            foreach (self::KNOWN as $stem) {
                if (str_contains($lower, $stem)) {
                    continue 2;
                }
            }

            $found[mb_strtolower($clause)] = $clause;
            if (count($found) >= self::MAX_PER_DESCRIPTION) {
                break;
            }
        }

        return array_values($found);
    }
}
