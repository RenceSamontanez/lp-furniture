<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Topic filter, not a content-safety filter: rejects prompts that
 * don't contain any furniture-related language before they ever
 * reach the AI image providers, so a clearly off-topic request
 * (a website, an essay, an unrelated image) doesn't waste API
 * quota. A single keyword match is enough to pass — this is
 * intentionally permissive, not trying to judge relevance beyond
 * "is this about furniture at all."
 */
class FurnitureTopic implements ValidationRule
{
    private const KEYWORDS = [
        // Furniture pieces
        'table', 'chair', 'cabinet', 'shelf', 'shelves', 'shelving',
        'bookshelf', 'bookcase', 'desk', 'wardrobe', 'closet', 'armoire',
        'bed', 'bedframe', 'headboard', 'footboard', 'sofa', 'couch',
        'loveseat', 'settee', 'sectional', 'futon', 'ottoman', 'pouf',
        'drawer', 'dresser', 'nightstand', 'bench', 'stool', 'barstool',
        'recliner', 'chaise', 'credenza', 'sideboard', 'hutch', 'buffet',
        'console', 'vanity', 'crib', 'cot', 'playpen', 'divider', 'trunk',
        'chest', 'banquette',

        // Multi-word furniture terms
        'coffee table', 'dining table', 'side table', 'end table',
        'tv stand', 'entertainment center', 'display case', 'display cabinet',
        'room divider', 'storage bench', 'bunk bed', 'rocking chair',
        'office chair', 'patio set', 'garden bench', 'porch swing',
        'bar stool', 'home office', 'living room set',

        // Materials / construction terms
        'wood', 'wooden', 'hardwood', 'softwood', 'oak', 'narra', 'mahogany',
        'teak', 'pine', 'acacia', 'walnut', 'rattan', 'bamboo', 'plywood',
        'veneer', 'upholstery', 'upholstered', 'leather', 'cushion',
        'joinery', 'woodwork', 'woodworking', 'cabinetry', 'tabletop',
        'armrest', 'headrest',

        // Style / general furniture-request terms
        'rustic', 'farmhouse', 'industrial', 'minimalist', 'scandinavian',
        'mid-century', 'vintage', 'contemporary', 'handcrafted',
        'custom-built', 'built-in', 'furniture', 'furnishing', 'furnishings',
        'dining set', 'seating',
    ];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $normalized = strtolower($value);

        foreach (self::KEYWORDS as $keyword) {
            if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $normalized)) {
                return;
            }
        }

        $fail("This doesn't look like a furniture request. Try describing a piece you'd like us to build, like 'a rustic oak dining table' or 'a modern minimalist bookshelf.'");
    }
}
