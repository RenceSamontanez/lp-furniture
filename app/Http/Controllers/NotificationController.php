<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    private const FEED_LIMIT = 20;

    /** Full page (also what phones use). Filters live in the query string so a filtered view can be shared: ?unread=1&from=2026-01-01&to=2026-02-28 */
    public function index(Request $request)
    {
        $filters = Notification::filtersFrom($request);
        $notifications = Notification::where('user_id', Auth::id())
            ->filtered($filters)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();
        $unreadCount = Notification::where('user_id', Auth::id())->unread()->count();
        $hasAny = Notification::where('user_id', Auth::id())->exists();

        return view('notifications.index', compact('notifications', 'filters', 'unreadCount', 'hasAny'));
    }

    /** Navbar dropdown: the signed-in customer's latest notifications as plain JSON (the page builds the rows with textContent, never HTML). */
    public function feed(Request $request)
    {
        $filters = Notification::filtersFrom($request);
        $items = Notification::where('user_id', Auth::id())
            ->filtered($filters)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::FEED_LIMIT)
            ->get()
            ->map(fn (Notification $n) => [
                'id'          => $n->id,
                'title'       => (string) $n->title,
                'message'     => (string) $n->message,
                'time'        => $n->timeLabel(),
                'iso'         => $n->created_at->toIso8601String(),
                'read'        => (bool) $n->is_read,
                'kind'        => $n->kind(),
                'details_url' => $n->detailsUrl(),
            ])->values();

        return response()->json(
            ['unread' => Notification::where('user_id', Auth::id())->unread()->count(), 'items' => $items],
            200,
            ['Cache-Control' => 'no-store, private'],
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * Read-only details for an Other Services notification (Stage 21h). Owner only (someone else's id is a plain 404);
     * opening it marks it read. Other kinds of notification keep going to their own page.
     */
    public function show($id)
    {
        $notif = Notification::where('user_id', Auth::id())->findOrFail($id);

        $details = \App\Support\ServiceNotificationDetails::build($notif);
        if ($details === null) {
            return redirect($notif->url ?: route('notifications.index'));
        }

        $notif->markAsRead();

        return response()->view('notifications.service-details', ['notif' => $notif, 'd' => $details])
            ->header('Cache-Control', 'no-store, private');
    }

    public function markRead(Request $request, $id)
    {
        Notification::where('user_id', Auth::id())
            ->findOrFail($id)
            ->update(['is_read' => true]);

        return $request->expectsJson() ? $this->unreadJson() : back();
    }

    public function markAllRead(Request $request)
    {
        Notification::where('user_id', Auth::id())
            ->update(['is_read' => true]);

        return $request->expectsJson()
            ? $this->unreadJson()
            : back()->with('success', 'All notifications marked as read.');
    }

    private function unreadJson()
    {
        return response()->json(['ok' => true, 'unread' => Notification::where('user_id', Auth::id())->unread()->count()]);
    }
}
