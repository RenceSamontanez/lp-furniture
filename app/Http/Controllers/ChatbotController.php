<?php

namespace App\Http\Controllers;

use App\Models\ChatbotLog;
use App\Support\Luma\Luma;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ChatbotController extends Controller
{
    public function respond(Request $request)
    {
        $request->validate(['message' => 'nullable|string|max:500']);

        $message = (string) $request->input('message', '');
        $reply   = Luma::reply($message);

        // Log the conversation. "unmatched" rows are the questions Luma could not answer:
        // Admin > Chatbot Training lists them.
        ChatbotLog::create([
            'user_id'         => Auth::check() ? Auth::id() : null,
            'session_id'      => session()->getId(),
            'message'         => $message,
            'response'        => $reply['text'],
            'intent_detected' => $reply['detected'],
        ]);

        return response()->json([
            'response'    => $reply['text'],
            'suggestions' => $reply['suggestions'],
            'products'    => $reply['products'],
            'cards'       => $reply['cards'],
            'buttons'     => $reply['buttons'],
            'contact'     => $reply['contact'],
            'lang'        => $reply['lang'],
        ]);
    }
}
