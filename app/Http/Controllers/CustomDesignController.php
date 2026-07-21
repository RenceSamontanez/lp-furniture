<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateAiDesign;
use App\Models\AiDesignSuggestion;
use App\Models\CustomDesignRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomDesignController extends Controller
{
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

        return view('design.index', compact(
            'myRequests', 'latestWithImages', 'latestImage', 'historyThumbs', 'totalWithImages'
        ));
    }

    public function submit(Request $request)
    {
        $request->validate([
            'prompt'             => 'required|string|min:15|max:1000',
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