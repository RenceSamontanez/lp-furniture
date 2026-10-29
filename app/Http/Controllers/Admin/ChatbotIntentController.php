<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotIntent;
use App\Models\LumaUnansweredQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatbotIntentController extends Controller
{
    public function index(Request $request)
    {
        $search   = trim((string) $request->get('search'));
        $category = trim((string) $request->get('category'));
        $tab      = $request->get('tab') === 'unanswered' ? 'unanswered' : 'intents';

        $intents = ChatbotIntent::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('keywords', 'like', "%{$search}%")->orWhere('response', 'like', "%{$search}%")))
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->orderBy('category')
            ->orderBy('id')
            ->paginate(15);

        $categories = ChatbotIntent::query()->whereNotNull('category')->where('category', '!=', '')->distinct()->orderBy('category')->pluck('category');

        // Questions Luma could not answer: most asked first, then most recent; dismissed and taught ones are hidden.
        $unanswered = Schema::hasTable('luma_unanswered_questions')
            ? LumaUnansweredQuestion::open()->orderByDesc('times_asked')->orderByDesc('last_asked_at')->limit(50)->get()
            : collect();

        // The old Edit URL lands here as ?edit=ID: that intent's modal opens even when it is not on this page of the list.
        $modalIntents = $intents->getCollection()->keyBy('id');
        $editId = (int) $request->get('edit');
        if ($editId > 0 && ! $modalIntents->has($editId) && ($target = ChatbotIntent::find($editId))) {
            $modalIntents->put($target->id, $target);
        }
        // a rejected save that came from "Answer": the note at the top of the modal needs the question again
        $answerRow = old('unanswered_id') && Schema::hasTable('luma_unanswered_questions') ? LumaUnansweredQuestion::find((int) old('unanswered_id')) : null;
        $openModal = old('_modal') ?: ($editId > 0 && $modalIntents->has($editId) ? 'intent-edit-' . $editId : ($request->boolean('add') ? 'intent-add' : null));

        return view('admin.chatbot.index', compact('intents', 'search', 'category', 'categories', 'unanswered', 'tab', 'modalIntents', 'openModal', 'answerRow'));
    }
    /** Hides a question from the list (it stays in the table until the 30-day cleanup). */
    public function dismissUnanswered($id)
    {
        LumaUnansweredQuestion::findOrFail($id)->forceFill(['dismissed_at' => now()])->save();

        return back()->with('success', 'Question dismissed.');
    }

    /** The Add page is now a modal on the list; the old URL still works and opens it. */
    public function create()
    {
        return redirect()->route('admin.chatbot.index', ['add' => 1]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateIntent($request);
        $validated['is_active'] = $request->boolean('is_active');
        $answered = (int) $request->input('unanswered_id');

        DB::transaction(function () use ($validated, $answered) {
            ChatbotIntent::create($validated);

            // Answered from the Unanswered Questions tab: the question is marked taught (the same state an intent with a matching keyword
            // gives it) so it leaves the list. A question that is already gone, dismissed or taught by another admin is simply skipped.
            if ($answered > 0) {
                LumaUnansweredQuestion::open()->whereKey($answered)->update(['taught_at' => now()]);
            }
        });

        return redirect()->route('admin.chatbot.index', $answered > 0 ? ['tab' => 'unanswered'] : [])->with('success', 'Intent added successfully.');
    }

    /** The Edit page is now a modal on the list; the old URL still works and opens it. */
    public function edit($id)
    {
        ChatbotIntent::findOrFail($id);

        return redirect()->route('admin.chatbot.index', ['edit' => (int) $id]);
    }

    public function update(Request $request, $id)
    {
        $intent = ChatbotIntent::findOrFail($id);
        $validated = $this->validateIntent($request);
        $validated['is_active'] = $request->boolean('is_active');

        $intent->update($validated);

        return redirect()->route('admin.chatbot.index')->with('success', 'Intent updated successfully.');
    }

    public function destroy($id)
    {
        ChatbotIntent::findOrFail($id)->delete();
        return back()->with('success', 'Intent deleted.');
    }

    public function toggleActive($id)
    {
        $intent = ChatbotIntent::findOrFail($id);
        $intent->update(['is_active' => !$intent->is_active]);

        return back()->with('success', 'Intent status updated.');
    }

    private function validateIntent(Request $request): array
    {
        return $request->validate([
            'keywords'    => 'required|string',
            'response'    => 'required|string',
            'answer_tl'   => 'nullable|string',
            'suggestions' => 'nullable|string',
            'category'    => 'nullable|string|max:100',
        ]);
    }
}