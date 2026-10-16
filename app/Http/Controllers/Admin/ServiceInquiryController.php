<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\CallUpdateMessage;
use App\Support\PipelineException;
use App\Support\ServiceInquiryDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin side of "Other Services": a list of inquiry tickets, a detail page with the call
 * update tool, notes and close, and a form to enter a ticket for a walk-in or phone customer.
 * Tickets never become orders.
 */
class ServiceInquiryController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private ServiceInquiryDesk $desk)
    {
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        if (!array_key_exists((string) $status, ServiceInquiry::STATUSES)) {
            $status = null;
        }
        $search = trim((string) $request->query('q', ''));

        $query = ServiceInquiry::query();

        if ($status) {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                  ->orWhere('reference_number', 'like', $like)
                  ->orWhere('phone', 'like', $like);
            });
        }

        $tickets = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();

        $counts = ServiceInquiry::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.service-inquiries.index', [
            'tickets' => $tickets,
            'status'  => $status,
            'search'  => $search,
            'counts'  => $counts,
            'total'   => $counts->sum(),
        ]);
    }

    public function show($id)
    {
        $ticket = ServiceInquiry::with(['user', 'createdBy'])->findOrFail($id);

        return view('admin.service-inquiries.show', [
            'hideGlobalErrorToast' => true, // errors are shown inline on this page
            'ticket'      => $ticket,
            'isOpen'      => $ticket->isOpen(),
            'callPreview' => ServiceInquiryDesk::previewData($ticket),
        ]);
    }

    public function create()
    {
        return view('admin.service-inquiries.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate(
            ServiceInquiryDesk::rules(false) + ['customer_id' => ['nullable', 'integer']],
            ServiceInquiryDesk::messages()
        );

        // Optionally link an existing customer account (found with the search box); only customers can be linked.
        $customer = null;
        if (!empty($data['customer_id'])) {
            $customer = User::where('role', 'customer')->find($data['customer_id']);
            if (!$customer) {
                return back()->withInput()->withErrors(['customer_id' => 'That customer account was not found. Search again or unlink it.']);
            }
        }

        $ticket = $this->desk->create($data, $customer, Auth::guard('admin')->user());

        return redirect()->route('admin.service-inquiries.show', $ticket->id)
            ->with('success', "Ticket {$ticket->reference_number} created.");
    }

    /** Search box on the create form: customers by name, phone or email (JSON, at most 8). */
    public function customerSearch(Request $request)
    {
        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['results' => []]);
        }

        $like  = '%' . addcslashes($term, '\\%_') . '%';
        $users = User::where('role', 'customer')
            ->where(function ($q) use ($like) {
                $q->whereRaw("CONCAT(first_name, ' ', last_name) like ?", [$like])
                  ->orWhere('email', 'like', $like)
                  ->orWhere('phone_number', 'like', $like)
                  ->orWhere('saved_contact_phone', 'like', $like)
                  ->orWhere('saved_contact_email', 'like', $like);
            })
            ->orderBy('first_name')->limit(8)->get();

        return response()->json(['results' => $users->map(fn (User $u) => [
            'id'    => $u->id,
            'name'  => $u->display_name,
            'phone' => $u->saved_contact_phone ?: $u->phone_number,
            'email' => $u->saved_contact_email ?: $u->email,
        ])->values()]);
    }

    public function callUpdate(Request $request, $id)
    {
        $data = $request->validate([
            'outcome'  => 'required|in:' . implode(',', array_keys(CallUpdateMessage::LABELS)),
            'attempts' => 'nullable|integer|min:1|max:20',
            'note'     => 'nullable|string|max:500',
        ], [
            'outcome.required' => 'Choose the call outcome.',
            'attempts.min'     => 'Call attempts must be from 1 to 20.',
            'attempts.max'     => 'Call attempts must be from 1 to 20.',
        ]);

        return $this->run($id, function () use ($id, $data) {
            $ticket = $this->desk->callUpdate(
                (int) $id,
                Auth::guard('admin')->user(),
                $data['outcome'],
                isset($data['attempts']) ? (int) $data['attempts'] : null,
                $data['note'] ?? null
            );

            return $ticket->user_id
                ? 'Call update saved. The customer has been notified.'
                : 'Call update saved. This ticket has no account, so no notification was sent.';
        });
    }

    public function addNote(Request $request, $id)
    {
        $data = $request->validate(['note' => 'required|string|max:1000'], ['note.required' => 'Write a note first.']);

        return $this->run($id, function () use ($id, $data) {
            $this->desk->addNote((int) $id, Auth::guard('admin')->user(), $data['note']);

            return 'Note added.';
        });
    }

    public function close(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000'], ['reason.required' => 'A reason is required to close a ticket.']);

        return $this->run($id, function () use ($id, $data) {
            $this->desk->close((int) $id, Auth::guard('admin')->user(), $data['reason']);

            return 'Ticket closed.';
        });
    }

    /** Runs a ticket action; a refusal becomes a flash error, never a 500. */
    private function run($id, callable $action)
    {
        ServiceInquiry::findOrFail($id); // 404 first for an unknown id

        try {
            $message = $action();
        } catch (PipelineException $e) {
            return redirect()->route('admin.service-inquiries.show', $id)->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect()->route('admin.service-inquiries.show', $id)->with('success', $message);
    }
}
