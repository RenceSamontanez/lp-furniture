<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiDesign;
use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Rules\FurnitureTopic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomDesignController extends Controller
{
    // Counted against CustomDesignRequest rows created today, not a
    // separate tracking table — every submit() call creates exactly
    // one row and dispatches exactly one generation job, so the rows
    // themselves are already an accurate, already-existing record of
    // "generations used today." Resets at midnight in the app's
    // configured timezone (Asia/Manila) — server time, per-visitor
    // browser timezone isn't tracked anywhere in this schema and
    // would be disproportionate to add just for this.
    private const DAILY_GENERATION_LIMIT = 3;

    private function generationsUsedToday(): int
    {
        return CustomDesignRequest::where('user_id', Auth::id())
            ->whereDate('created_at', today())
            ->count();
    }

    public function index()
    {
        $myRequests = CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->latest()
            ->get();

        // Most recent request that has actual generated images — shown as the hero preview
        $latestWithImages = $myRequests->first(fn($r) => $r->suggestions->count() > 0);
        $latestImage = null;
        if ($latestWithImages) {
            $latestImage = $latestWithImages->suggestions->firstWhere('is_selected', true)
                ?? $latestWithImages->suggestions->first();
        }

        // Small history strip — up to 4 most recent OTHER requests with images
        $historyThumbs = $myRequests
            ->filter(fn($r) => $r->suggestions->count() > 0 && (!$latestWithImages || $r->id !== $latestWithImages->id))
            ->take(6)
            ->map(function ($r) {
                $img = $r->suggestions->firstWhere('is_selected', true) ?? $r->suggestions->first();
                return ['request' => $r, 'image' => $img];
            });

        $totalWithImages = $myRequests->filter(fn($r) => $r->suggestions->count() > 0)->count();

        $dailyUsed  = $this->generationsUsedToday();
        $dailyLimit = self::DAILY_GENERATION_LIMIT;

        return view('design.index', compact(
            'myRequests', 'latestWithImages', 'latestImage', 'historyThumbs', 'totalWithImages',
            'dailyUsed', 'dailyLimit'
        ));
    }

    public function submit(Request $request)
    {
        // Daily generation cap — checked before validation/dispatch
        // so an over-limit request never reaches the queue, same
        // reasoning as the furniture-topic check.
        if ($this->generationsUsedToday() >= self::DAILY_GENERATION_LIMIT) {
            return back()->withInput()->withErrors([
                'prompt' => "You've used all " . self::DAILY_GENERATION_LIMIT . " AI design generations for today. Your limit resets at midnight — come back tomorrow for more!",
            ]);
        }

        $request->validate([
            'prompt'             => ['required', 'string', 'min:15', 'max:1000', new FurnitureTopic()],
            'furniture_type'     => 'nullable|string|max:100',
            'preferred_material' => 'nullable|string|max:100',
            'dimensions'         => 'nullable|string|max:200',
            'additional_info'    => 'nullable|string|max:1000',
            'reference_image'    => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        $description = trim($request->prompt);
        if ($request->filled('additional_info')) {
            $description .= "\n\nAdditional details: " . trim($request->additional_info);
        }

        $refImagePath = null;
        if ($request->hasFile('reference_image')) {
            $refImagePath = $request->file('reference_image')
                ->store('design-references/' . Auth::id(), 'public');
        }

        $designRequest = CustomDesignRequest::create([
            'user_id'            => Auth::id(),
            'title'              => ($request->furniture_type ?: 'Custom Furniture') . ' — ' . Auth::user()->first_name,
            'furniture_type'     => $request->furniture_type,
            'description'        => $description,
            'preferred_material' => $request->preferred_material,
            'dimensions'         => $request->dimensions,
            'reference_image'    => $refImagePath,
            'status'             => 'pending',
        ]);

        GenerateAiDesign::dispatch($designRequest->id);

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', 'Your design request has been submitted!');
    }

    public function show($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->findOrFail($id);

        return view('design.show', compact('designRequest'));
    }

    public function selectDesign(Request $request, $id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        $request->validate(['suggestion_id' => 'required|exists:ai_design_suggestions,id']);

        AiDesignSuggestion::where('design_id', $id)->update(['is_selected' => false]);
        AiDesignSuggestion::where('id', $request->suggestion_id)->where('design_id', $id)->update(['is_selected' => true]);

        $designRequest->update(['status' => 'approved']);

        return back()->with('success', 'Design selected! Our team will contact you shortly to discuss pricing and production details.');
    }

    public function regenerate($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())->findOrFail($id);

        AiDesignSuggestion::where('design_id', $id)->delete();
        $designRequest->update(['status' => 'pending']);

        GenerateAiDesign::dispatch($designRequest->id);

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', 'Generating new design concepts...');
    }

    /* ════════════════════════════════
       FAVORITE TOGGLE — AJAX
    ════════════════════════════════ */
    public function toggleFavorite(Request $request, $suggestionId)
    {
        $suggestion = AiDesignSuggestion::whereHas('request', function ($q) {
            $q->where('user_id', Auth::id());
        })->findOrFail($suggestionId);

        $suggestion->update(['is_favorited' => !$suggestion->is_favorited]);

        return response()->json(['favorited' => (bool) $suggestion->is_favorited]);
    }

    /* ════════════════════════════════
       GALLERY — History tab + Favorites tab
    ════════════════════════════════ */
    public function gallery(Request $request)
    {
        $tab = $request->get('tab', 'history');

        if ($tab === 'favorites') {
            $items = AiDesignSuggestion::whereHas('request', function ($q) {
                    $q->where('user_id', Auth::id());
                })
                ->where('is_favorited', true)
                ->with('request')
                ->latest('generated_at')
                ->get();
        } else {
            $items = CustomDesignRequest::where('user_id', Auth::id())
                ->with('suggestions')
                ->whereHas('suggestions')
                ->latest()
                ->get();
        }

        return view('design.gallery', compact('tab', 'items'));
    }
}