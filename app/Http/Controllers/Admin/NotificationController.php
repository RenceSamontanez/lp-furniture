<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * An admin's own notifications. Always scoped to the admin guard's
 * user id, so one admin can never read or mark another's.
 */
class NotificationController extends Controller
{
    public function index()
    {
        $notifications = Notification::where('user_id', Auth::guard('admin')->id())
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(20);

        return view('admin.notifications.index', compact('notifications'));
    }

    public function markRead($id)
    {
        $notification = Notification::where('user_id', Auth::guard('admin')->id())->findOrFail($id);
        $notification->update(['is_read' => true]);

        return back();
    }

    public function markAllRead()
    {
        Notification::where('user_id', Auth::guard('admin')->id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return back()->with('success', 'All notifications marked as read.');
    }
}
