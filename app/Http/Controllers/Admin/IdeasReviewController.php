<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DesignIdea;
use App\Models\ExtraFurnitureType;
use App\Models\UnansweredIdea;
use App\Rules\NoLinkOrEmail;
use App\Support\FurnitureTypes;
use App\Support\IdeaOrigins;
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
    /** The page lists up to this many ideas (newest activity first) and filters them in the browser. */
    private const LIST_LIMIT = 500;

    public function index(Request $request)
    {
        // Old links keep working: ?status=answered|dismissed|added_as_option|all, ?kind=, ?q=, ?sort=newest
        $status = $request->query('status');
        $tab = in_array($status, UnansweredIdea::STATUSES, true) ? $status : ($status === 'all' ? 'all' : 'new');
        $kind = in_array($request->query('kind'), UnansweredIdea::KINDS, true) ? $request->query('kind') : '';
        $sort = $request->query('sort') === 'newest' ? 'newest' : 'most';
        $search = trim((string) $request->query('q', ''));

        $ideas = UnansweredIdea::query()->orderByDesc('times_asked')->orderByDesc('last_seen_at')->orderByDesc('id')->limit(self::LIST_LIMIT)->get();
        $counts = UnansweredIdea::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status')->all();
        $origins = IdeaOrigins::for($ideas);

        $rows = $ideas->map(function (UnansweredIdea $i) use ($origins) {
            $o = $origins[$i->id];

            return [
                'id' => $i->id, 'text' => $i->text, 'kind' => $i->kind, 'kindLabel' => ['furniture_type' => 'Furniture Type', 'wood' => 'Wood', 'idea' => 'Idea Phrase'][$i->kind] ?? $i->kind,
                'times' => (int) $i->times_asked, 'status' => $i->status,
                'last' => $i->last_seen_at?->format('M j, Y'), 'lastTs' => $i->last_seen_at?->timestamp ?? 0,
                'answer' => $i->answer,
                'askedFor' => IdeaOrigins::askedFor($o['furniture']), 'furniture' => $o['furniture'],
                'requests' => $o['requests'], 'requestTotal' => $o['total'],
                'canOption' => $i->kind !== UnansweredIdea::KIND_WOOD && $i->status !== UnansweredIdea::STATUS_DISMISSED && $i->status !== UnansweredIdea::STATUS_ADDED,
            ];
        })->values();

        $reopen = null;
        if (old('_modal') && old('_idea')) {
            $reopen = ['modal' => old('_modal'), 'id' => (int) old('_idea'), 'old' => collect(old())->only(['answer', 'wording', 'scope', 'key', 'ok'])->all(), 'errors' => session('errors') ? session('errors')->getBag('default')->toArray() : []];
        }

        return view('admin.ideas.index', [
            'rows' => $rows, 'tab' => $tab, 'kind' => $kind, 'sort' => $sort, 'search' => $search,
            'counts' => $counts, 'total' => array_sum($counts),
            'extraTypeNames' => ExtraFurnitureType::chips(),
            'typeChoices' => FurnitureTypes::CHOICES, 'styles' => StyleChoices::LIST,
            'undo' => session('undo_idea') ? UnansweredIdea::find(session('undo_idea')) : null,
            'reopen' => $reopen, 'hideGlobalErrorToast' => true,
        ]);
    }

    public function answer(Request $request, int $id)
    {
        $row = UnansweredIdea::findOrFail($id);
        $request->merge(['answer' => TextSanitizer::block($request->input('answer'))]);

        $data = $request->validate([
            'answer' => ['required', 'string', 'min:3', 'max:' . UnansweredIdea::ANSWER_MAX, new NoLinkOrEmail(), 'not_regex:/\[[^\]]*\]/u'],
        ], [
            'answer.not_regex' => 'Replace the [brackets] with your own words before saving.',
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
            return $this->optionFail('The wood list is fixed to Gmelina, Mahogany and Acacia, so a wood cannot be added as an option. Write an answer or dismiss it.');
        }
        if ($row->status === UnansweredIdea::STATUS_DISMISSED) {
            return $this->optionFail('This one is dismissed. Undo the dismissal first.');
        }

        if ($row->kind === UnansweredIdea::KIND_TYPE) {
            $request->merge(['wording' => IdeaText::line($request->input('wording'))]);
            $data = $request->validate(['wording' => IdeaText::lineRules(ExtraFurnitureType::NAME_MAX)], ['wording.required' => 'Type the name to show as a quick pick.']);

            if (ExtraFurnitureType::isTaken($data['wording'])) {
                return $this->optionFail('"' . $data['wording'] . '" is already an option.');
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
            return $this->optionFail('Choose a ' . ($data['scope'] === 'type' ? 'furniture type' : 'style') . ' from the list.', 'key');
        }
        if (QuickIdeasController::exists($data['scope'], $data['key'], $data['wording'])) {
            return $this->optionFail('That idea already exists for ' . $data['key'] . '.');
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

    /** A refused "Add as option": back to the page with the Add as Option window open again, the typed values and the reason under the field. */
    private function optionFail(string $message, string $field = 'wording')
    {
        return back()->withInput(request()->all() + ['_modal' => 'option', '_idea' => request()->route('id')])->withErrors([$field => $message])->with('error', $message);
    }

    private function back(string $message, string $key = 'success')
    {
        return back()->with($key, $message);
    }
}
