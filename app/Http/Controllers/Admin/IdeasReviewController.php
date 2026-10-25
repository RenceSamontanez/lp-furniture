<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignIdea;
use App\Models\ExtraFurnitureType;
use App\Models\UnansweredIdea;
use App\Rules\NoLinkOrEmail;
use App\Support\FurnitureTypes;
use App\Support\IdeaText;
use App\Support\StyleChoices;
use App\Support\TextSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Admin "Ideas to review": what customers typed on the Customize page that we had no answer for.
 * Same access as the custom order pages (the `admin` route group). Every change is server-validated.
 */
class IdeasReviewController extends Controller
{
    private const PER_PAGE = 15;

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), UnansweredIdea::STATUSES, true) ? $request->query('status') : (($request->has('status') && $request->query('status') === 'all') ? null : 'new');
        $kind = in_array($request->query('kind'), UnansweredIdea::KINDS, true) ? $request->query('kind') : null;
        $sort = $request->query('sort') === 'newest' ? 'newest' : 'most';
        $search = trim((string) $request->query('q', ''));

        $query = UnansweredIdea::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($search !== '', fn ($q) => $q->where(function ($w) use ($search) {
                $like = '%' . addcslashes(mb_strtolower($search), '\\%_') . '%';
                $w->where('text_key', 'like', $like)->orWhere('answer', 'like', $like);
            }));

        $sort === 'newest'
            ? $query->orderByDesc('first_seen_at')->orderByDesc('id')
            : $query->orderByDesc('times_asked')->orderByDesc('last_seen_at')->orderByDesc('id');

        $rows = $query->paginate(self::PER_PAGE)->withQueryString();

        $counts = UnansweredIdea::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all();

        return view('admin.ideas.index', [
            'rows' => $rows, 'status' => $status, 'kind' => $kind, 'sort' => $sort, 'search' => $search,
            'counts' => $counts, 'total' => array_sum($counts),
            'extraTypeNames' => ExtraFurnitureType::chips(),
            'typeChoices' => FurnitureTypes::CHOICES, 'styles' => StyleChoices::LIST,
            'undo' => session('undo_idea') ? UnansweredIdea::find(session('undo_idea')) : null,
        ]);
    }

    public function answer(Request $request, int $id)
    {
        $row = UnansweredIdea::findOrFail($id);
        $request->merge(['answer' => TextSanitizer::block($request->input('answer'))]);

        $data = $request->validate([
            'answer' => ['required', 'string', 'min:3', 'max:' . UnansweredIdea::ANSWER_MAX, new NoLinkOrEmail()],
        ], [
            'answer.required' => 'Write what the customer should be told.',
            'answer.min'      => 'The answer is too short. Write at least 3 characters.',
            'answer.max'      => 'The answer is too long. Keep it under ' . UnansweredIdea::ANSWER_MAX . ' characters.',
        ]);

        $row->forceFill([
            'answer' => $data['answer'],
            // an entry already added as an option stays "added as option" (it just gains a note); everything else becomes answered
            'status' => $row->status === UnansweredIdea::STATUS_ADDED ? UnansweredIdea::STATUS_ADDED : UnansweredIdea::STATUS_ANSWERED,
            'answered_at' => now(), 'handled_by' => Auth::guard('admin')->id(), 'handled_at' => now(),
        ])->save();

        return $this->back('Saved. The next customer who types this gets your answer.');
    }

    /**
     * Add as option. kind idea -> a quick idea (design_ideas); kind furniture type -> an extra "Something else" chip.
     * Wood is refused: the wood list is fixed to Gmelina, Mahogany and Acacia.
     */
    public function addAsOption(Request $request, int $id)
    {
        $row = UnansweredIdea::findOrFail($id);

        if ($row->kind === UnansweredIdea::KIND_WOOD) {
            return $this->back('The wood list is fixed to Gmelina, Mahogany and Acacia, so a wood cannot be added as an option. Write an answer or dismiss it.', 'error');
        }
        if ($row->status === UnansweredIdea::STATUS_DISMISSED) {
            return $this->back('This one is dismissed. Undo the dismissal first.', 'error');
        }

        if ($row->kind === UnansweredIdea::KIND_TYPE) {
            $request->merge(['wording' => IdeaText::line($request->input('wording'))]);
            $data = $request->validate(['wording' => IdeaText::lineRules(ExtraFurnitureType::NAME_MAX)], ['wording.required' => 'Type the name to show as a quick pick.']);

            if (ExtraFurnitureType::isTaken($data['wording'])) {
                return $this->back('"' . $data['wording'] . '" is already an option.', 'error');
            }

            DB::transaction(function () use ($row, $data) {
                ExtraFurnitureType::create([
                    'name' => $data['wording'], 'name_key' => ExtraFurnitureType::keyFor($data['wording']),
                    'sort_order' => (int) ExtraFurnitureType::max('sort_order') + 1, 'is_active' => true, 'source_idea_id' => $row->id,
                ]);
                $this->markAdded($row);
            });

            return $this->back('Added "' . $data['wording'] . '" as a quick pick under "Something else".');
        }

        // kind idea
        $request->merge(['wording' => IdeaText::line($request->input('wording'))]);
        $data = $request->validate([
            'scope'   => ['required', Rule::in(['type', 'style'])],
            'key'     => ['required', 'string'],
            'wording' => IdeaText::lineRules(IdeaText::IDEA_MAX),
        ], [
            'scope.required' => 'Choose whether this idea is for a furniture type or a style.',
            'key.required'   => 'Choose which furniture type or style it belongs to.',
            'wording.required' => 'Type the wording of the idea.',
        ]);

        $allowed = $data['scope'] === 'type' ? array_keys(FurnitureTypes::CHOICES) : StyleChoices::LIST;
        if (!in_array($data['key'], $allowed, true)) {
            return $this->back('Choose a ' . ($data['scope'] === 'type' ? 'furniture type' : 'style') . ' from the list.', 'error');
        }
        if (QuickIdeasController::exists($data['scope'], $data['key'], $data['wording'])) {
            return $this->back('That idea already exists for ' . $data['key'] . '.', 'error');
        }

        DB::transaction(function () use ($row, $data) {
            DesignIdea::create([
                'scope' => $data['scope'], 'idea_key' => $data['key'], 'text' => $data['wording'],
                'sort_order' => (int) DesignIdea::where('scope', $data['scope'])->where('idea_key', $data['key'])->max('sort_order') + 1, 'is_active' => true,
            ]);
            $this->markAdded($row);
        });

        return $this->back('Added "' . $data['wording'] . '" to the quick ideas for ' . $data['key'] . '.');
    }

    public function dismiss(int $id)
    {
        $row = UnansweredIdea::findOrFail($id);

        if ($row->status !== UnansweredIdea::STATUS_DISMISSED) {
            $row->forceFill([
                'previous_status' => $row->status, 'status' => UnansweredIdea::STATUS_DISMISSED,
                'handled_by' => Auth::guard('admin')->id(), 'handled_at' => now(),
            ])->save();
        }

        return $this->back('Dismissed "' . $row->text . '".')->with('undo_idea', $row->id);
    }

    public function undo(int $id)
    {
        $row = UnansweredIdea::findOrFail($id);

        if ($row->status === UnansweredIdea::STATUS_DISMISSED) {
            $restore = in_array($row->previous_status, [UnansweredIdea::STATUS_NEW, UnansweredIdea::STATUS_ANSWERED, UnansweredIdea::STATUS_ADDED], true) ? $row->previous_status : UnansweredIdea::STATUS_NEW;
            // an "answered" row without an answer would show nothing to customers: send it back to new instead
            if ($restore === UnansweredIdea::STATUS_ANSWERED && blank($row->answer)) {
                $restore = UnansweredIdea::STATUS_NEW;
            }
            $row->forceFill(['status' => $restore, 'previous_status' => null, 'handled_by' => Auth::guard('admin')->id(), 'handled_at' => now()])->save();
        }

        return $this->back('Restored "' . $row->text . '".');
    }

    public function destroy(int $id)
    {
        $row = UnansweredIdea::findOrFail($id);
        $text = $row->text;
        $row->delete();

        return $this->back('Deleted "' . $text . '".');
    }

    private function markAdded(UnansweredIdea $row): void
    {
        $row->forceFill(['status' => UnansweredIdea::STATUS_ADDED, 'handled_by' => Auth::guard('admin')->id(), 'handled_at' => now()])->save();
    }

    private function back(string $message, string $key = 'success')
    {
        return back()->with($key, $message);
    }
}
