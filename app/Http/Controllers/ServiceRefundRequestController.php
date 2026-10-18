<?php

namespace App\Http\Controllers;

use App\Models\ServiceInquiry;
use App\Models\ServiceRefundRequest;
use App\Support\PipelineException;
use App\Support\ServiceRefundRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * The customer's side of an Other Services cancellation or refund request: the form (with a summary and confirm
 * step), the confirmation page, and the request page with its status and message thread. Everything is scoped to
 * the signed-in owner in the query itself, so another customer's ticket or request is a plain 404.
 * The rules live in ServiceRefundRequestService, which Luma also uses.
 */
class ServiceRefundRequestController extends Controller
{
    public function __construct(private ServiceRefundRequestService $service)
    {
    }

    private function myTicket($id): ServiceInquiry
    {
        return ServiceInquiry::where('user_id', Auth::id())->findOrFail($id);
    }

    private function myRequest(string $reference): ServiceRefundRequest
    {
        return ServiceRefundRequest::with(['inquiry', 'invoice', 'messages', 'attempts'])
            ->where('user_id', Auth::id())->where('reference', $reference)->firstOrFail();
    }

    public function create($id)
    {
        $ticket = $this->myTicket($id);
        $ctx    = ServiceRefundRequestService::context($ticket);

        if ($ctx['open']) {
            return redirect()->route('services.refund.show', $ctx['open']->reference);
        }
        if (!$ctx['can_request']) {
            return redirect()->route('services.request')->with('error', $ctx['why_not']);
        }

        return response()->view('services.refunds.form', $this->formData($ticket, $ctx))->header('Cache-Control', 'no-store, private');
    }

    /** Validates the form and shows a summary to confirm. Nothing is saved yet. */
    public function review(Request $request, $id)
    {
        $ticket = $this->myTicket($id);
        $ctx    = ServiceRefundRequestService::context($ticket);
        if (!$ctx['can_request']) {
            return redirect()->route('services.request')->with('error', $ctx['why_not']);
        }

        $clean = ServiceRefundRequestService::validate($request->all(), $ctx['paid_cents'] > 0, true);   // throws back to the form with messages

        return response()->view('services.refunds.review', $this->formData($ticket, $ctx) + [
            'clean' => $clean, 'details' => $clean['reason_text'],
        ])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, $id)
    {
        $ticket = $this->myTicket($id);

        try {
            $req = $this->service->create($ticket->id, $request->all(), Auth::user(), 'form');
        } catch (PipelineException $e) {
            return redirect()->route('services.request')->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return redirect()->route('services.refund.create', $ticket->id)->withErrors($e->errors())->withInput();
        }

        return redirect()->route('services.refund.sent', $req->reference);
    }

    public function sent(string $reference)
    {
        return response()->view('services.refunds.sent', ['req' => $this->myRequest($reference)])->header('Cache-Control', 'no-store, private');
    }

    public function show(string $reference)
    {
        return response()->view('services.refunds.show', ['req' => $this->myRequest($reference)])->header('Cache-Control', 'no-store, private');
    }

    public function reply(Request $request, string $reference)
    {
        $req = $this->myRequest($reference);

        try {
            $this->service->customerReply($req->id, Auth::user(), [
                'body'               => (string) $request->input('body', ''),
                'gcash_number'       => $request->input('gcash_number'),
                'gcash_account_name' => $request->input('gcash_account_name'),
            ]);
        } catch (PipelineException $e) {
            return redirect()->route('services.refund.show', $reference)->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return redirect()->route('services.refund.show', $reference)->withErrors($e->errors())->withInput();
        }

        return redirect()->route('services.refund.show', $reference)->with('success', 'Thank you. We received your reply.');
    }

    private function formData(ServiceInquiry $ticket, array $ctx): array
    {
        $user = Auth::user();

        return [
            'ticket'   => $ticket,
            'ctx'      => $ctx,
            'defaults' => [
                'phone' => $user->saved_contact_phone ?: ($user->phone_number ?: $ticket->phone),
                'email' => $user->saved_contact_email ?: ($user->email ?: $ticket->email),
            ],
        ];
    }
}
