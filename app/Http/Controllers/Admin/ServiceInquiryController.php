<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\CallUpdateMessage;
use App\Support\PipelineException;
use App\Support\ServiceInquiryDesk;
use App\Support\ServiceRefundRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Admin side of "Other Services": a list of inquiry tickets, a detail page with the call
 * update tool, notes and close, and a form to enter a ticket for a walk-in or phone customer.
 * Tickets never become orders.
 */
class ServiceInquiryController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private ServiceInquiryDesk $desk, private ServiceRefundRequestService $refunds)
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

        // "Ready to start": paid enough, work not started yet (so paid jobs are not forgotten).
        $readyIds = ServiceInquiry::whereIn('status', ServiceInquiry::OPEN)->whereNull('service_started_at')
            ->whereHas('invoices', fn ($q) => $q->whereIn('status', ['issued', 'paid']))
            ->get()->filter(fn ($t) => \App\Support\ServiceWorkState::canStart($t) === \App\Support\ServiceWorkState::ALLOWED)->pluck('id');
        $ready = (bool) $request->query('ready');
        if ($ready) {
            $query->whereIn('id', $readyIds);
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

        // A rejected "New Ticket" submit (or the old create URL, ?new=1) reopens the modal; the linked account is shown again.
        $openModal = old('_modal') ?: ($request->boolean('new') ? 'ticket-new' : null);
        $linkedId = (int) old('customer_id');
        $linkedCustomer = $linkedId > 0 ? User::where('role', 'customer')->find($linkedId) : null;

        return view('admin.service-inquiries.index', [
            'openModal' => $openModal,
            'linkedCustomer' => $linkedCustomer,
            'tickets' => $tickets,
            'status'  => $status,
            'search'  => $search,
            'counts'  => $counts,
            'total'   => $counts->sum(),
            'ready'   => $ready,
            'readyCount' => $readyIds->count(),
        ]);
    }

    public function show($id)
    {
        $ticket = ServiceInquiry::with(['user', 'createdBy', 'invoices'])->findOrFail($id);

        return view('admin.service-inquiries.show', [
            'hideGlobalErrorToast' => true, // errors are shown inline on this page
            'ticket'      => $ticket,
            'isOpen'      => $ticket->isOpen(),
            'ctx'         => ServiceRefundRequestService::context($ticket),
            'callPreview' => ServiceInquiryDesk::previewData($ticket),
        ]);
    }

    /** One reference photo of a ticket, from the private disk. Admin only; never cached. */
    public function photo($id, $n)
    {
        $ticket = ServiceInquiry::findOrFail($id);
        $path   = ($ticket->reference_photos ?? [])[(int) $n] ?? null;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        $response = response()->file(Storage::disk('local')->path($path), ['X-Content-Type-Options' => 'nosniff']);
        $response->setPrivate();
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    /** The New Ticket page is now a modal on the list; the old URL still works and opens it. */
    public function create()
    {
        return redirect()->route('admin.service-inquiries.index', ['new' => 1]);
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
            $ticket = $this->refunds->adminClose((int) $id, Auth::guard('admin')->user(), $data['reason']);

            return $ticket->isCancelled() ? 'Ticket cancelled. Its invoice was cancelled with it and the customer was told.' : 'Ticket closed.';
        });
    }

    /** Runs a ticket action; a refusal becomes a flash error, never a 500. */
    private function run($id, callable $action)
    {
        ServiceInquiry::findOrFail($id); // 404 first for an unknown id

        try {
            $message = $action();
        } catch (PipelineException $e) {
            // A ticket with money on it cannot just be cancelled: offer to open a cancellation request instead.
            return redirect()->route('admin.service-inquiries.show', $id)->withErrors(['ticket' => $e->getMessage()])
                ->with('offer_cancellation_request', $e->getMessage() === ServiceRefundRequestService::PAYMENT_ON_TICKET);
        }

        return redirect()->route('admin.service-inquiries.show', $id)->with('success', $message);
    }
}
