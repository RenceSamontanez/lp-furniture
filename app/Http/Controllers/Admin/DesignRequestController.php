<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomDesignRequest;
use App\Models\Notification;
use Illuminate\Http\Request;

class DesignRequestController extends Controller
{
    /* ════════════════════════════════
       LIST — customers who selected a design ("Order This
       Design") and are awaiting admin follow-up, plus ones
       already declined for reference. Phase 1: visibility +
       lightweight tracking only, no order creation from here.
    ════════════════════════════════ */
    public function index(Request $request)
    {
        $status = $request->get('status', 'approved');

        $requests = CustomDesignRequest::query()
            ->whereIn('status', ['approved', 'declined'])
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->with(['user', 'suggestions'])
            ->latest()
            ->paginate(15);

        return view('admin.design-requests.index', compact('requests', 'status'));
    }

    /* ════════════════════════════════
       MARK AS CONTACTED — lightweight tracking marker, doesn't
       change status (the request stays "approved" — this just
       records that admin has followed up).
    ════════════════════════════════ */
    public function markContacted($id)
    {
        $designRequest = CustomDesignRequest::where('status', 'approved')->findOrFail($id);
        $designRequest->update(['admin_contacted_at' => now()]);

        return back()->with('success', 'Marked as contacted.');
    }

    /* ════════════════════════════════
       REJECT — distinct from the AI-generation-failure 'rejected'
       status (see the migration note): this is 'declined', a
       deliberate admin decision on a design that generated fine.
       Notifies the customer with the given reason.
    ════════════════════════════════ */
    public function reject(Request $request, $id)
    {
        $request->validate([
            'admin_notes' => 'nullable|string|max:1000',
        ]);

        $designRequest = CustomDesignRequest::where('status', 'approved')->findOrFail($id);
        $designRequest->update([
            'status'       => 'declined',
            'admin_notes'  => $request->admin_notes,
        ]);

        Notification::create([
            'user_id' => $designRequest->user_id,
            'title'   => 'Update on Your Custom Design Request',
            'message' => $request->admin_notes
                ? "Regarding your \"{$designRequest->furniture_type}\" request: {$request->admin_notes}"
                : "We're unable to take on your \"{$designRequest->furniture_type}\" request. Feel free to try a different idea.",
            'type'    => 'general',
            'url'     => route('design.show', $designRequest->id),
        ]);

        return back()->with('success', 'Request declined and customer notified.');
    }
}
