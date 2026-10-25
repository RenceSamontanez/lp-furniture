<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignIdea;
use App\Models\ExtraFurnitureType;
use App\Support\FurnitureTypes;
use App\Support\IdeaText;
use App\Support\StyleChoices;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin editor for the Customize page's quick ideas (design_ideas) and the extra "Something else" quick picks
 * (extra_furniture_types). The customer page reads both tables, so a change shows on the next page load.
 */
class QuickIdeasController extends Controller
{
    public static function exists(string $scope, string $key, string $text, ?int $exceptId = null): bool
    {
        return DesignIdea::where('scope', $scope)->where('idea_key', $key)
            ->whereRaw('LOWER(text) = ?', [mb_strtolower($text)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
    }

    public function index()
    {
        $ideas = DesignIdea::orderBy('sort_order')->orderBy('id')->get()->groupBy(fn ($i) => $i->scope . '|' . $i->idea_key);

        $groups = [];
        foreach (FurnitureTypes::CHOICES as $value => $label) {
            $groups['type'][] = ['key' => $value, 'label' => $label, 'rows' => $ideas->get('type|' . $value, collect())];
        }
        foreach (StyleChoices::LIST as $style) {
            $groups['style'][] = ['key' => $style, 'label' => $style, 'rows' => $ideas->get('style|' . $style, collect())];
        }
        $groups['general'][] = ['key' => '', 'label' => 'Anything else (no type or style yet)', 'rows' => $ideas->get('general|', collect())];

        return view('admin.ideas.quick', [
            'groups' => $groups,
            'extraTypes' => ExtraFurnitureType::orderBy('sort_order')->orderBy('id')->get(),
            'max' => IdeaText::IDEA_MAX, 'typeMax' => ExtraFurnitureType::NAME_MAX, 'chipsShown' => ExtraFurnitureType::CHIPS_SHOWN,
        ]);
    }

    /* ───────────── quick ideas ───────────── */

    public function store(Request $request)
    {
        $request->merge(['text' => IdeaText::line($request->input('text')), 'idea_key' => (string) $request->input('idea_key', '')]);
        $data = $request->validate([
            'scope'    => ['required', Rule::in(['type', 'style', 'general'])],
            'idea_key' => ['nullable', 'string'],
            'text'     => IdeaText::lineRules(IdeaText::IDEA_MAX),
        ], $this->messages());

        $this->assertKey($data['scope'], $data['idea_key'] ?? '');
        $key = $data['scope'] === 'general' ? '' : $data['idea_key'];

        if (self::exists($data['scope'], $key, $data['text'])) {
            return back()->withInput()->with('error', 'That idea is already in this list.');
        }

        DesignIdea::create([
            'scope' => $data['scope'], 'idea_key' => $key, 'text' => $data['text'], 'is_active' => true,
            'sort_order' => (int) DesignIdea::where('scope', $data['scope'])->where('idea_key', $key)->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Added "' . $data['text'] . '".');
    }

    public function update(Request $request, int $id)
    {
        $idea = DesignIdea::findOrFail($id);
        $request->merge(['text' => IdeaText::line($request->input('text'))]);
        $data = $request->validate(['text' => IdeaText::lineRules(IdeaText::IDEA_MAX)], $this->messages());

        if (self::exists($idea->scope, $idea->idea_key, $data['text'], $idea->id)) {
            return back()->with('error', 'That idea is already in this list.');
        }

        $idea->update(['text' => $data['text']]);

        return back()->with('success', 'Saved.');
    }

    public function toggle(int $id)
    {
        $idea = DesignIdea::findOrFail($id);
        $idea->update(['is_active' => !$idea->is_active]);

        return back()->with('success', $idea->is_active ? 'Turned on: customers will see it.' : 'Turned off: customers will not see it.');
    }

    public function move(Request $request, int $id)
    {
        $idea = DesignIdea::findOrFail($id);
        $data = $request->validate(['dir' => ['required', Rule::in(['up', 'down'])]]);

        $this->swap(DesignIdea::where('scope', $idea->scope)->where('idea_key', $idea->idea_key), $idea, $data['dir']);

        return back();
    }

    public function destroy(int $id)
    {
        $idea = DesignIdea::findOrFail($id);
        $idea->delete();

        return back()->with('success', 'Deleted "' . $idea->text . '".');
    }

    /* ───────────── extra furniture types ("Something else" quick picks) ───────────── */

    public function storeType(Request $request)
    {
        $request->merge(['name' => IdeaText::line($request->input('name'))]);
        $data = $request->validate(['name' => IdeaText::lineRules(ExtraFurnitureType::NAME_MAX)], ['name.required' => 'Type the name of the furniture.', 'name.min' => 'The name is too short.', 'name.max' => 'The name is too long (at most ' . ExtraFurnitureType::NAME_MAX . ' characters).']);

        if (ExtraFurnitureType::isTaken($data['name'])) {
            return back()->withInput()->with('error', '"' . $data['name'] . '" is already an option.');
        }

        ExtraFurnitureType::create([
            'name' => $data['name'], 'name_key' => ExtraFurnitureType::keyFor($data['name']),
            'sort_order' => (int) ExtraFurnitureType::max('sort_order') + 1, 'is_active' => true,
        ]);

        return back()->with('success', 'Added "' . $data['name'] . '".');
    }

    public function updateType(Request $request, int $id)
    {
        $type = ExtraFurnitureType::findOrFail($id);
        $request->merge(['name' => IdeaText::line($request->input('name'))]);
        $data = $request->validate(['name' => IdeaText::lineRules(ExtraFurnitureType::NAME_MAX)], ['name.required' => 'Type the name of the furniture.', 'name.min' => 'The name is too short.']);

        if (ExtraFurnitureType::isTaken($data['name'], $type->id)) {
            return back()->with('error', '"' . $data['name'] . '" is already an option.');
        }

        $type->update(['name' => $data['name'], 'name_key' => ExtraFurnitureType::keyFor($data['name'])]);

        return back()->with('success', 'Saved.');
    }

    public function toggleType(int $id)
    {
        $type = ExtraFurnitureType::findOrFail($id);
        $type->update(['is_active' => !$type->is_active]);

        return back()->with('success', $type->is_active ? 'Turned on.' : 'Turned off.');
    }

    public function moveType(Request $request, int $id)
    {
        $type = ExtraFurnitureType::findOrFail($id);
        $data = $request->validate(['dir' => ['required', Rule::in(['up', 'down'])]]);

        $this->swap(ExtraFurnitureType::query(), $type, $data['dir']);

        return back();
    }

    public function destroyType(int $id)
    {
        $type = ExtraFurnitureType::findOrFail($id);
        $type->delete();

        return back()->with('success', 'Deleted "' . $type->name . '".');
    }

    /* ───────────── helpers ───────────── */

    private function messages(): array
    {
        return [
            'scope.required' => 'Choose where the idea belongs.',
            'text.required'  => 'Type the wording of the idea.',
            'text.min'       => 'The idea is too short.',
            'text.max'       => 'The idea is too long (at most ' . IdeaText::IDEA_MAX . ' characters).',
        ];
    }

    private function assertKey(string $scope, string $key): void
    {
        $ok = match ($scope) {
            'type'    => in_array($key, array_keys(FurnitureTypes::CHOICES), true),
            'style'   => in_array($key, StyleChoices::LIST, true),
            default   => true,
        };
        if (!$ok) {
            throw \Illuminate\Validation\ValidationException::withMessages(['idea_key' => 'Choose a ' . ($scope === 'type' ? 'furniture type' : 'style') . ' from the list.']);
        }
    }

    /** Swaps sort positions with the neighbour, renumbering the list first so equal positions cannot stall it. */
    private function swap($scopeQuery, $row, string $dir): void
    {
        $list = (clone $scopeQuery)->orderBy('sort_order')->orderBy('id')->get()->values();
        foreach ($list as $i => $item) {
            if ((int) $item->sort_order !== $i + 1) {
                $item->update(['sort_order' => $i + 1]);
            }
        }
        $pos = $list->search(fn ($i) => $i->id === $row->id);
        $other = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($pos === false || $other < 0 || $other >= $list->count()) {
            return;
        }

        $a = $list[$pos];
        $b = $list[$other];
        [$sa, $sb] = [$a->fresh()->sort_order, $b->fresh()->sort_order];
        $a->update(['sort_order' => $sb]);
        $b->update(['sort_order' => $sa]);
    }
}
