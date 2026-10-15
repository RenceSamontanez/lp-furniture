<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiDesign;
use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use App\Rules\FurnitureTopic;
use App\Support\DimensionsFormatter;
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
    //
    // A failed attempt doesn't cost one of the 3 real generations —
    // only requests that didn't end up 'rejected' count toward that.
    // DAILY_ATTEMPT_LIMIT is a separate, higher hard ceiling on TOTAL
    // rows created today regardless of outcome, so repeated failures
    // (or someone intentionally burning attempts) can't turn an
    // unlimited number of tries into an unlimited number of provider
    // calls on our end.
    private const DAILY_GENERATION_LIMIT = 3;
    private const DAILY_ATTEMPT_LIMIT = 6;

    private function generationsUsedToday(): int
    {
        return CustomDesignRequest::where('user_id', Auth::id())
            ->whereDate('created_at', today())
            ->where('status', '!=', 'rejected')
            ->count();
    }

    private function attemptsUsedToday(): int
    {
        return CustomDesignRequest::where('user_id', Auth::id())
            ->whereDate('created_at', today())
            ->count();
    }

    public function index()
    {
        // Lazy staleness check — see PART 2 of the reliability fix.
        // Runs here (and in gallery()/show()) so stuck 'pending' rows
        // get cleaned up even with no scheduler wired up.
        CustomDesignRequest::failStale();

        $myRequests = CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->latest()
            ->get();

        // Most recent request with an image that's actually loadable
        // — shown as the hero preview. Not just "has suggestion rows":
        // see AiDesignSuggestion::isLoadable().
        $latestWithImages = $myRequests->first(fn($r) => $r->hasLoadableSuggestion());
        $latestImage = null;
        if ($latestWithImages) {
            $loadable = $latestWithImages->loadableSuggestions();
            $latestImage = $loadable->firstWhere('is_selected', true) ?? $loadable->first();
        }

        // Small history strip — up to 6 most recent OTHER requests with loadable images
        $historyThumbs = $myRequests
            ->filter(fn($r) => $r->hasLoadableSuggestion() && (!$latestWithImages || $r->id !== $latestWithImages->id))
            ->take(6)
            ->map(function ($r) {
                $loadable = $r->loadableSuggestions();
                $img = $loadable->firstWhere('is_selected', true) ?? $loadable->first();
                return ['request' => $r, 'image' => $img];
            });

        $totalWithImages = $myRequests->filter(fn($r) => $r->hasLoadableSuggestion())->count();

        $dailyUsed  = $this->generationsUsedToday();
        $dailyLimit = self::DAILY_GENERATION_LIMIT;

        // "Try again" on a failed history row links here with the
        // original prompt as a query string, prefilling the form
        // instead of resubmitting the dead request.
        $prefillPrompt = request()->query('prompt');

        return view('design.index', compact(
            'myRequests', 'latestWithImages', 'latestImage', 'historyThumbs', 'totalWithImages',
            'dailyUsed', 'dailyLimit', 'prefillPrompt'
        ));
    }

    public function submit(Request $request)
    {
        // Hard ceiling first — this one counts failures too, so it's
        // checked regardless of how many of today's attempts actually
        // succeeded.
        if ($this->attemptsUsedToday() >= self::DAILY_ATTEMPT_LIMIT) {
            return back()->withInput()->withErrors([
                'prompt' => "You've reached today's limit of " . self::DAILY_ATTEMPT_LIMIT . " design attempts. Your limit resets at midnight — come back tomorrow.",
            ]);
        }

        // Daily generation cap — checked before validation/dispatch
        // so an over-limit request never reaches the queue, same
        // reasoning as the furniture-topic check. Failed attempts
        // don't count against this one.
        if ($this->generationsUsedToday() >= self::DAILY_GENERATION_LIMIT) {
            return back()->withInput()->withErrors([
                'prompt' => "You've used all " . self::DAILY_GENERATION_LIMIT . " AI design generations for today. Your limit resets at midnight — come back tomorrow for more!",
            ]);
        }

        $request->validate([
            'prompt'             => ['required', 'string', 'min:15', 'max:1000', new FurnitureTopic()],
            'furniture_type'     => 'nullable|string|max:100',
            'preferred_material' => 'nullable|string|max:100',
            'dimensions_length'  => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'dimensions_width'   => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'dimensions_height'  => ['nullable', 'numeric', 'min:0', 'max:9999'],
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

        // Same optional 3-field Width/Depth/Height (cm) pattern as the
        // admin product form, combined via the same shared helper —
        // unlike a product listing, a customer describing a custom
        // piece may not know exact dimensions yet, so all three stay
        // nullable and simply produce no `dimensions` value if unset.
        $dimensions = DimensionsFormatter::combine(
            $request->dimensions_length,
            $request->dimensions_width,
            $request->dimensions_height
        );

        $designRequest = CustomDesignRequest::create([
            'user_id'            => Auth::id(),
            'title'              => ($request->furniture_type ?: 'Custom Furniture') . ' — ' . Auth::user()->first_name,
            'furniture_type'     => $request->furniture_type,
            'description'        => $description,
            'preferred_material' => $request->preferred_material,
            'dimensions'         => $dimensions,
            'reference_image'    => $refImagePath,
            'status'             => 'pending',
        ]);

        GenerateAiDesign::dispatch($designRequest->id);

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', 'Your design request has been submitted!');
    }

    public function show($id)
    {
        CustomDesignRequest::failStale();

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

    /* ════════════════════════════════
       SEND TO TEAM WITHOUT AN IMAGE — PART 3 of the provider-chain
       rescue. Only available on a request generation genuinely
       failed for (status 'rejected'); reaches the same 'approved'
       state selectDesign() uses, so it shows up in the admin's normal
       follow-up queue, just with no suggestion to display. Keeps the
       prompt/furniture details exactly as submitted — nothing here
       touches them.
    ════════════════════════════════ */
    public function sendWithoutImage($id)
    {
        $designRequest = CustomDesignRequest::where('user_id', Auth::id())
            ->where('status', 'rejected')
            ->findOrFail($id);

        $designRequest->update(['status' => 'approved']);

        return redirect()->route('design.show', $designRequest->id)
            ->with('success', "Sent to our team without an image — we'll reach out to discuss your idea directly.");
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
        CustomDesignRequest::failStale();

        $tab = $request->get('tab', 'history');

        if ($tab === 'favorites') {
            $items = AiDesignSuggestion::whereHas('request', function ($q) {
                    $q->where('user_id', Auth::id());
                })
                ->where('is_favorited', true)
                ->with('request')
                ->latest('generated_at')
                ->get();

            return view('design.gallery', compact('tab', 'items'));
        }

        // Every past attempt, not just the ones that produced an
        // image — this is a log of what was requested, not just
        // a gallery of what generated. design.gallery classifies
        // each row into success/failed/in-progress from here.
        // Paginated at 15 and grouped by day for the playlist-style
        // history redesign (PART 3).
        $items = CustomDesignRequest::where('user_id', Auth::id())
            ->with('suggestions')
            ->latest()
            ->paginate(15);

        $grouped = $items->getCollection()->groupBy(function ($r) {
            if ($r->created_at->isToday()) return 'Today';
            if ($r->created_at->isYesterday()) return 'Yesterday';
            return 'Earlier';
        });

        return view('design.gallery', compact('tab', 'items', 'grouped'));
    }
}