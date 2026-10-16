<?php

namespace App\Http\Controllers;

use App\Models\BusinessSetting;
use App\Models\ChatbotIntent;
use App\Models\ChatbotLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatbotController extends Controller
{
    public function respond(Request $request)
    {
        $message  = strtolower(trim($request->input('message', '')));
        $response = null;
        $suggestions = [];
        $products = [];

        // Match against active intents
        $intents = ChatbotIntent::where('is_active', true)->get();

        foreach ($intents as $intent) {
            $keywords = array_map('trim', explode(',', strtolower($intent->keywords)));
            foreach ($keywords as $keyword) {
                if ($keyword && str_contains($message, $keyword)) {
                    // {{phone}} / {{email}} / {{facebook}} → Admin → Contact Settings
                    $response    = BusinessSetting::applyPlaceholders($intent->response);
                    $suggestions = array_map('trim', explode(',', $intent->suggestions ?? ''));
                    break 2;
                }
            }
        }

        // A question about the size of one of our products, answered from its stored size,
        // always in the shop's order: length x width x height, in cm with inches in brackets.
        if (!$response && preg_match('/\b(sizes?|dimensions?|measurements?|how (big|large|long|tall|wide))\b/i', $message)) {
            $named = Product::available()->whereNotNull('dimensions')->get()
                ->first(fn ($p) => $p->product_name && str_contains($message, strtolower($p->product_name)));

            if ($named && $named->dimensions_label) {
                $response    = "The {$named->product_name} measures {$named->dimensions_label}, as length x width x height.";
                $suggestions = ['View all products', 'Custom design', 'Delivery info'];
            }
        }

        // Budget detection
        if (!$response && preg_match('/(\d[\d,]*)\s*(pesos?|php|₱)?/i', $message, $matches)) {
            $budget = (int) str_replace(',', '', $matches[1]);
            if ($budget > 0) {
                $matchedProducts = Product::available()
                    ->where('price', '<=', $budget)
                    ->orderBy('price', 'desc')
                    ->take(4)
                    ->get();

                if ($matchedProducts->count()) {
                    $response = "Great! Here are some pieces within your ₱" . number_format($budget) . " budget:";
                    $products = $matchedProducts->map(fn($p) => [
                        'id'           => $p->id,
                        'product_name' => $p->product_name,
                        'price'        => $p->price,
                    ])->toArray();
                    $suggestions = ['View all products', 'Custom design', 'Delivery info'];
                }
            }
        }

      // Default fallback — offer a helpful menu instead of a dead end
if (!$response) {
    $response = "I couldn't quite find an answer to that — but here's what I can help with! Pick a topic below, or try rephrasing your question.";
    $suggestions = [
        'How to order',
        'Custom design',
        'Payment & installment',
        'Delivery & pickup',
        'Track my order',
        'Talk to a human',
    ];
}

        // Log the conversation
        ChatbotLog::create([
            'user_id'          => Auth::check() ? Auth::id() : null,
            'session_id'       => session()->getId(),
            'message'          => $request->input('message'),
            'response'         => $response,
            'intent_detected'  => 'matched',
        ]);

        return response()->json(compact('response', 'suggestions', 'products'));
    }
}