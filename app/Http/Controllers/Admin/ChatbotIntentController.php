<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChatbotIntent;
use App\Models\LumaUnansweredQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ChatbotIntentController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->get('search');

        $intents = ChatbotIntent::query()
            ->when($search, fn($q) => $q->where('keywords', 'like', "%{$search}%")->orWhere('response', 'like', "%{$search}%"))
            ->orderBy('category')
            ->paginate(15);

        // Questions Luma could not answer: most asked first, then most recent; dismissed and taught ones are hidden.
        $unanswered = Schema::hasTable('luma_unanswered_questions')
            ? LumaUnansweredQuestion::open()->orderByDesc('times_asked')->orderByDesc('last_asked_at')->limit(50)->get()
            : collect();

        return view('admin.chatbot.index', compact('intents', 'search', 'unanswered'));
    }

    /** Hides a question from the list (it stays in the table until the 30-day cleanup). */
    public function dismissUnanswered($id)
    {
        LumaUnansweredQuestion::findOrFail($id)->forceFill(['dismissed_at' => now()])->save();

        return back()->with('success', 'Question dismissed.');
    }

    public function create()
    {
        return view('admin.chatbot.form', ['intent' => new ChatbotIntent()]);
    }

    public function store(Request $request)
    {
        $validated = $this->validateIntent($request);
        $validated['is_active'] = $request->boolean('is_active');

        ChatbotIntent::create($validated);

        return redirect()->route('admin.chatbot.index')->with('success', 'Intent added successfully.');
    }

    public function edit($id)
    {
        $intent = ChatbotIntent::findOrFail($id);
        return view('admin.chatbot.form', compact('intent'));
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