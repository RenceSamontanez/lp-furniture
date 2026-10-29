<?php

namespace App\Support;

use App\Models\CustomDesignRequest;
use App\Models\UnansweredIdea;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which Customize requests asked for an idea. Nothing stores that link (the idea table only keeps the text and a count), so it is
 * derived from the requests themselves, the same way the idea was captured:
 *   - furniture type: the "Something else" text of a request (furniture_type = Other) equals the idea, case and spaces ignored
 *   - wood:           the "Other wood" name of a request equals the idea
 *   - idea phrase:    the idea text appears inside the request's description (phrases are cut out of descriptions on submit)
 * One query per kind, no query per idea. A phrase match is the one approximate case (a phrase can also be part of a longer word).
 */
final class IdeaOrigins
{
    /** Requests listed per idea in the View window (the total is still counted). */
    public const SHOWN = 8;
    /** Furniture names named in the muted "Asked for" line. */
    public const FURNITURE_NAMED = 2;

    /**
     * @param  Collection<int, UnansweredIdea>  $ideas
     * @return array<int, array{total: int, requests: list<array>, furniture: list<string>}>
     */
    public static function for(Collection $ideas): array
    {
        $keys = ['furniture_type' => [], 'wood' => [], 'idea' => []];
        foreach ($ideas as $idea) {
            if (isset($keys[$idea->kind])) {
                $keys[$idea->kind][$idea->text_key][] = $idea->id;
            }
        }

        $columns = ['id', 'user_id', 'furniture_type', 'furniture_type_other', 'created_at'];
        /** @var array<int, array<int, object>> $hits idea id => request rows */
        $hits = [];
        $add = function (array $ideaIds, $request) use (&$hits) {
            foreach ($ideaIds as $ideaId) {
                $hits[$ideaId][$request->id] = $request;
            }
        };

        if ($keys['furniture_type'] !== []) {
            CustomDesignRequest::query()->where('furniture_type', FurnitureTypes::OTHER)->whereNotNull('furniture_type_other')
                ->select($columns)->orderBy('id')->chunkById(500, function ($rows) use ($keys, $add) {
                    foreach ($rows as $r) {
                        $k = UnansweredIdea::keyFor((string) $r->furniture_type_other);
                        if (isset($keys['furniture_type'][$k])) {
                            $add($keys['furniture_type'][$k], $r);
                        }
                    }
                });
        }

        if ($keys['wood'] !== []) {
            CustomDesignRequest::query()->where('preferred_material', WoodChoices::SPECIAL)->whereNotNull('wood_special_details')
                ->select(array_merge($columns, ['wood_special_details']))->orderBy('id')->chunkById(500, function ($rows) use ($keys, $add) {
                    foreach ($rows as $r) {
                        $k = UnansweredIdea::keyFor((string) $r->wood_special_details);
                        if (isset($keys['wood'][$k])) {
                            $add($keys['wood'][$k], $r);
                        }
                    }
                });
        }

        if ($keys['idea'] !== []) {
            CustomDesignRequest::query()->whereNotNull('description')->where('description', '!=', '')
                ->select(array_merge($columns, ['description']))->orderBy('id')->chunkById(500, function ($rows) use ($keys, $add) {
                    foreach ($rows as $r) {
                        $haystack = UnansweredIdea::keyFor((string) $r->description);
                        foreach ($keys['idea'] as $phrase => $ideaIds) {
                            if ($phrase !== '' && str_contains($haystack, $phrase)) {
                                $add($ideaIds, $r);
                            }
                        }
                    }
                });
        }

        $names = [];
        $userIds = collect($hits)->flatten(1)->pluck('user_id')->filter()->unique()->all();
        if ($userIds !== []) {
            $names = User::whereIn('id', $userIds)->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name . ' ' . mb_substr((string) $u->last_name, 0, 1) . ($u->last_name ? '.' : ''))])->all();
        }

        $out = [];
        foreach ($ideas as $idea) {
            $rows = collect($hits[$idea->id] ?? [])->sortByDesc('id')->values();
            $furniture = $rows->map(fn ($r) => self::furnitureOf($r))->filter()->unique()->values()->all();
            $out[$idea->id] = [
                'total'     => $rows->count(),
                'furniture' => $furniture,
                'requests'  => $rows->take(self::SHOWN)->map(fn ($r) => [
                    'id'    => $r->id,
                    'who'   => $names[$r->user_id] ?? 'A customer',
                    'item'  => self::furnitureOf($r) ?: 'a piece of furniture',
                    'date'  => $r->created_at?->format('M j, Y'),
                    'url'   => route('admin.design-requests.show', $r->id),
                ])->all(),
            ];
        }

        return $out;
    }

    private static function furnitureOf($request): string
    {
        if ($request->furniture_type === FurnitureTypes::OTHER) {
            return trim((string) $request->furniture_type_other);
        }

        return trim((string) $request->furniture_type);
    }

    /** "Table and Cabinet" / "Bed, Table and 2 more"; empty string when nothing is known. */
    public static function askedFor(array $furniture): string
    {
        $n = count($furniture);
        if ($n === 0) {
            return '';
        }
        if ($n <= self::FURNITURE_NAMED) {
            return implode(' and ', $furniture);
        }

        return implode(', ', array_slice($furniture, 0, self::FURNITURE_NAMED)) . ' and ' . ($n - self::FURNITURE_NAMED) . ' more';
    }
}
