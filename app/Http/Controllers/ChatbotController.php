<?php

namespace App\Http\Controllers;

use App\Models\ChatbotLog;
use App\Support\Luma\ChatState;
use App\Support\Luma\Language;
use App\Support\Luma\Luma;
use App\Support\Luma\RefundFlow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ChatbotController extends Controller
{
    public function respond(Request $request)
    {
        $request->validate(['message' => 'nullable|string|max:500']);

        $message = (string) $request->input('message', '');
        $reply   = Luma::reply($message);

        // Log the conversation. "unmatched" rows are the questions Luma could not answer:
        // Admin > Chatbot Training lists them. A GCash-style number typed into the chat is masked before it is stored.
        ChatbotLog::create([
            'user_id'         => Auth::check() ? Auth::id() : null,
            'session_id'      => session()->getId(),
            'message'         => self::redact($message),
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
            'form'        => $reply['form'] ?? null,
            'reference'   => $reply['reference'] ?? null,
            'lang'        => $reply['lang'],
        ]);
    }

    /**
     * The chat's cancellation / refund form card posts here (customers only, rate limited, CSRF protected). Two steps:
     * `review` validates with ServiceRefundRequestService::validate() and returns the confirm summary; `confirm` creates
     * the request once. The GCash number and name are never logged: only a plain "form sent" line is written.
     */
    public function refund(Request $request): JsonResponse
    {
        $user = Auth::user();
        $lang = $request->input('lang') === 'tl' ? Language::TL : Language::EN;

        if (!$user) {
            return response()->json(['message' => $lang === Language::TL ? 'Mag-log in po muna.' : 'Please log in first.'], 401);
        }

        $step = (string) $request->input('step');

        try {
            [$status, $body] = match ($step) {
                'review'  => RefundFlow::review($request->all(), $user, $lang),
                'confirm' => RefundFlow::confirm((string) $request->input('token', ''), $request->boolean('checked'), $user, $lang, ChatState::load()),
                default   => [422, ['message' => 'Unknown step.']],
            };
        } catch (ValidationException $e) {
            [$status, $body] = [422, ['errors' => $e->errors()]];
        }

        if ($step === 'confirm' && $status === 200) {
            $state = ChatState::load();
            $state->set('flow', null)->save();
        }

        ChatbotLog::create([
            'user_id'         => $user->id,
            'session_id'      => session()->getId(),
            'message'         => '[Cancellation form: ' . $step . ']',
            'response'        => isset($body['reply']) ? mb_substr((string) $body['reply']['text'], 0, 500) : ($status === 200 ? '[summary shown]' : '[form not accepted]'),
            'intent_detected' => 'flow:refund_form_' . $step,
        ]);

        if (isset($body['reply'])) {
            $body['reply'] = Luma::finish(['text' => $body['reply']['text'], 'lang' => $lang, 'closing' => 'flow'] + $body['reply'] + ['detected' => 'flow:refund_done']) + $body['reply'];
        }

        return response()->json($body, $status);
    }

    /** Masks Philippine mobile-number-shaped text so a GCash number typed into the chat is never stored. */
    public static function redact(string $message): string
    {
        return preg_replace_callback('/(?<!\d)(?:\+\s?63|63|0)[\s\-]?9[\s\-]?\d{2}[\s\-]?\d{3}[\s\-]?\d{4}(?!\d)/', function ($m) {
            $last10 = substr(preg_replace('/\D+/', '', $m[0]), -10);

            return '0' . substr($last10, 0, 3) . ' *** ' . substr($last10, -4);
        }, $message) ?? $message;
    }
}
