<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::where('user_id', Auth::id())
            ->latest()
            ->paginate(20);

        return view('notifications.index', compact('notifications'));
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

    public function markRead($id)
    {
        Notification::where('user_id', Auth::id())
            ->findOrFail($id)
            ->update(['is_read' => true]);

        return back();
    }

    public function markAllRead()
    {
        Notification::where('user_id', Auth::id())
            ->update(['is_read' => true]);

        return back()->with('success', 'All notifications marked as read.');
    }
}