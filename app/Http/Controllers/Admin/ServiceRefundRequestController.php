<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceInquiry;
use App\Models\ServiceRefundRequest;
use App\Support\PipelineException;
use App\Support\ServiceRefundRequestService;
use App\Support\ServiceWorkState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Admin side of Other Services cancellations and refunds. Lists ONLY these requests. Every action goes through
 * ServiceRefundRequestService, which owns the rules; this class just reads the form and shows the result.
 * The GCash number is shown in full only on the detail page.
 */
class ServiceRefundRequestController extends Controller
{
    private const PER_PAGE = 15;

    public function __construct(private ServiceRefundRequestService $service)
    {
    }

    public function index(Request $request)
    {
        $status = $request->query('status');
        $status = array_key_exists((string) $status, ServiceRefundRequest::STATUSES) ? $status : null;
        $work   = $request->query('work');
        $work   = array_key_exists((string) $work, ServiceWorkState::LABELS) ? $work : null;
        $method = $request->query('method');
        $method = array_key_exists((string) $method, ServiceRefundRequest::METHODS) ? $method : null;
        $search = trim((string) $request->query('q', ''));

        $query = ServiceRefundRequest::with(['inquiry', 'invoice']);
        $status && $query->where('status', $status);
        $work && $query->where('work_state_snapshot', $work);
        $method && $query->where('refund_method', $method);

        if ($search !== '') {
            $like = '%' . addcslashes($search, '\\%_') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('reference', 'like', $like)
                  ->orWhere('contact_phone', 'like', $like)
                  ->orWhereHas('invoice', fn ($i) => $i->where('invoice_number', 'like', $like))
                  ->orWhereHas('inquiry', fn ($t) => $t->where('name', 'like', $like)->orWhere('reference_number', 'like', $like));
            });
        }

        $requests = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();
        $counts   = ServiceRefundRequest::selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');

        return view('admin.refunds.index', [
            'requests' => $requests, 'status' => $status, 'work' => $work, 'method' => $method, 'search' => $search,
            'counts' => $counts, 'total' => $counts->sum(),
        ]);
    }

    public function show($id)
    {
        $req = ServiceRefundRequest::with(['inquiry.user', 'invoice', 'messages.admin', 'attempts.admin', 'decider'])->findOrFail($id);

        return view('admin.refunds.show', [
            'hideGlobalErrorToast' => true,
            'req'  => $req,
            'work' => ServiceWorkState::for($req->inquiry),   // live, not the snapshot
            'paidCents' => $req->invoice ? \App\Support\ServiceInvoicePayments::receivedCents($req->invoice) : 0,
        ]);
    }

    public function approve($id)
    {
        return $this->act($id, fn ($admin) => $this->service->approve((int) $id, $admin), 'Approved. The customer was told when to expect the refund.', ['refund_approved', 'Approved the refund request']);
    }

    public function approveException(Request $request, $id)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000'], ['reason.required' => 'Write the reason for this exception.']);

        return $this->act($id, fn ($admin) => $this->service->approveAsException((int) $id, $admin, $data['reason']), 'Approved as an exception and logged.', ['refund_approved', 'Approved the refund request as an exception']);
    }

    public function decline(Request $request, $id)
    {
        $data = $request->validate(['explanation' => 'required|string|max:1000'], ['explanation.required' => 'Write an explanation for the customer.']);

        return $this->act($id, fn ($admin) => $this->service->decline((int) $id, $admin, $data['explanation']), 'Declined. The customer was sent your explanation and payments are open again.', ['refund_declined', 'Declined the refund request']);
    }

    public function askInfo(Request $request, $id)
    {
        $data = $request->validate(['message' => 'required|string|max:1000'], ['message.required' => 'Write your question for the customer.']);

        return $this->act($id, fn ($admin) => $this->service->askForInfo((int) $id, $admin, $data['message']), 'Your question was sent to the customer.');
    }

    public function markRefunded(Request $request, $id)
    {
        $data = $request->validate([
            'amount'             => 'required|numeric|gt:0|max:999999.99',
            'method'             => 'nullable|in:' . implode(',', array_keys(ServiceRefundRequest::METHODS)),
            'transaction_number' => 'required|string|max:60',
            'reference_override' => 'nullable|boolean',
            'reference_override_reason' => 'nullable|string|max:300',
            'sent_on'            => 'required|date_format:Y-m-d',
            'note'               => 'nullable|string|max:1000',
        ], [
            'amount.required'             => 'Enter the amount that was sent.',
            'transaction_number.required' => 'Enter the transaction number (the GCash reference or the cash receipt number).',
            'sent_on.required'            => 'Enter the date the refund was sent.',
        ]);

        return $this->act($id, fn ($admin) => $this->service->markRefunded((int) $id, $admin, $data), 'Refund recorded. The service request and invoice are now cancelled and the customer was told.', ['refund_recorded', 'Recorded the refund for']);
    }

    public function refundFailed(Request $request, $id)
    {
        $data = $request->validate([
            'problem' => 'required|in:' . implode(',', array_keys(ServiceRefundRequestService::PROBLEMS)),
            'note'    => 'nullable|string|max:1000',
        ], ['problem.required' => 'Choose what went wrong.']);

        return $this->act($id, fn ($admin) => $this->service->markRefundFailed((int) $id, $admin, $data['problem'], $data['note'] ?? null), 'Recorded. The customer was asked to send the correct details.');
    }

    /** "Open a cancellation request for a customer" (phone or walk-in), from the ticket page. */
    public function openForCustomer(Request $request, $id)
    {
        $ticket = ServiceInquiry::findOrFail($id);

        try {
            $req = $this->service->create($ticket->id, $request->all(), Auth::guard('admin')->user(), 'admin');
        } catch (PipelineException $e) {
            return redirect()->route('admin.service-inquiries.show', $ticket->id)->withErrors(['ticket' => $e->getMessage()]);
        } catch (ValidationException $e) {
            return redirect()->route('admin.service-inquiries.show', $ticket->id)->withErrors($e->errors())->withInput();
        }

        return redirect()->route('admin.refunds.show', $req->id)->with('success', "Cancellation request {$req->reference} opened. Payments on this service are paused.");
    }

    public function workStarted($id)
    {
        return $this->ticketAct($id, fn ($admin) => $this->service->startWork((int) $id, $admin), 'Work started recorded.');
    }

    public function undoWorkStarted($id)
    {
        return $this->ticketAct($id, fn ($admin) => $this->service->undoStartWork((int) $id, $admin), 'Work started was undone.');
    }

    /** @param array{0: string, 1: string}|null $log Activity Log event and wording; recorded only after the action succeeded */
    private function act($id, callable $action, string $message, ?array $log = null)
    {
        $req = ServiceRefundRequest::findOrFail($id);

        try {
            $action(Auth::guard('admin')->user());
        } catch (PipelineException $e) {
            return redirect()->route('admin.refunds.show', $id)->withErrors(['request' => $e->getMessage()]);
        }

        if ($log) {
            \App\Support\ActivityLog::record($log[0], $log[1] . ' ' . $req->reference, 'orders', $req, 'Request: ' . $req->reference);
        }

        return redirect()->route('admin.refunds.show', $id)->with('success', $message);
    }

    private function ticketAct($id, callable $action, string $message)
    {
        ServiceInquiry::findOrFail($id);

        try {
            $action(Auth::guard('admin')->user());
        } catch (PipelineException $e) {
            return redirect()->route('admin.service-inquiries.show', $id)->withErrors(['ticket' => $e->getMessage()]);
        }

        return redirect()->route('admin.service-inquiries.show', $id)->with('success', $message);
    }
}
