<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceInquiry;
use App\Models\ServiceInvoice;
use App\Support\PipelineException;
use App\Support\InvoicePaymentRules;
use App\Support\ServiceInvoiceDesk;
use App\Support\ServiceInvoicePayments;
use App\Support\ServiceInquiryDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin side of Other Services invoices: create a draft from a ticket, edit it, issue it (locks it),
 * record payment, void. Nothing here touches orders, the payment ledger or the payment gateways.
 */
class ServiceInvoiceController extends Controller
{
    public function __construct(private ServiceInvoiceDesk $desk, private ServiceInvoicePayments $payments)
    {
    }

    public function create($ticketId)
    {
        $ticket = ServiceInquiry::with('user')->findOrFail($ticketId);

        if ($live = ServiceInvoiceDesk::activeFor($ticket)) {
            return redirect()->route('admin.service-invoices.show', $live->id)
                ->withErrors(['invoice' => 'This ticket already has an invoice. Cancel it first if you need to send a new one.']);
        }

        return view('admin.service-invoices.form', [
            'ticket'  => $ticket,
            'invoice' => null,
            'values'  => [
                'service_description' => ServiceInvoiceDesk::suggestedService($ticket),
                'customer_name'       => $ticket->name,
                'customer_phone'      => $ticket->phone,
                'customer_email'      => $ticket->email,
                'customer_address'    => $ticket->user?->address,
                'delivery_option'     => null,
                'lines'               => [],
                'payment_plan'        => InvoicePaymentRules::FULL,
            ],
            'halfMin' => InvoicePaymentRules::HALF_HALF_MIN_TOTAL,
        ]);
    }

    public function store(Request $request, $ticketId)
    {
        ServiceInquiry::findOrFail($ticketId);
        $clean = ServiceInvoiceDesk::validate($request->all());

        try {
            $invoice = $this->desk->createDraft((int) $ticketId, Auth::guard('admin')->user(), $clean);
        } catch (PipelineException $e) {
            return back()->withInput()->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect()->route('admin.service-invoices.show', $invoice->id)
            ->with('success', 'Draft saved. You can edit it again until you send it to the customer.');
    }

    public function show($id)
    {
        $invoice = ServiceInvoice::with(['lines', 'inquiry.user', 'createdBy', 'payments.recorder', 'refundNotes.recorder'])->findOrFail($id);

        return view('admin.service-invoices.show', [
            'hideGlobalErrorToast' => true,
            'invoice' => $invoice,
            'ticket'  => $invoice->inquiry,
            'due'     => ServiceInvoicePayments::due($invoice),
            'receivedCents' => ServiceInvoicePayments::receivedCents($invoice),
            'work'    => \App\Support\ServiceWorkState::for($invoice->inquiry),
            'openRequest' => $invoice->inquiry->openRefundRequest(),
            'today'   => ServiceInquiryDesk::today()->toDateString(),
        ]);
    }

    public function edit($id)
    {
        $invoice = ServiceInvoice::with(['lines', 'inquiry'])->findOrFail($id);

        if (!$invoice->isDraft()) {
            return redirect()->route('admin.service-invoices.show', $id)
                ->withErrors(['invoice' => 'This invoice is ' . strtolower($invoice->statusLabel()) . ' and is locked. Cancel it and send a new one to change it.']);
        }

        $delivery = $invoice->lines->first(fn ($l) => in_array($l->kind, ['delivery_fee', 'home_service_fee'], true));

        return view('admin.service-invoices.form', [
            'ticket'  => $invoice->inquiry,
            'halfMin' => InvoicePaymentRules::HALF_HALF_MIN_TOTAL,
            'invoice' => $invoice,
            'values'  => [
                'service_description' => $invoice->service_description,
                'service_cost'        => $invoice->lines->firstWhere('kind', 'service_cost')?->amount,
                'customer_name'       => $invoice->customer_name,
                'customer_phone'      => $invoice->customer_phone,
                'customer_email'      => $invoice->customer_email,
                'customer_address'    => $invoice->customer_address,
                'delivery_option'     => $invoice->delivery_option,
                'delivery_amount'     => $delivery?->amount,
                'notes'               => $invoice->notes,
                'payment_plan'        => $invoice->payment_plan,
                'lines'               => $invoice->lines->whereIn('kind', ['material_fee', 'other_fee'])
                    ->map(fn ($l) => ['kind' => $l->kind, 'description' => $l->description, 'amount' => $l->amount])->values()->all(),
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        ServiceInvoice::findOrFail($id);
        $clean = ServiceInvoiceDesk::validate($request->all());
        $admin = Auth::guard('admin')->user();

        // "Save draft" only saves. "Send to customer" saves what is on the page, then sends exactly that.
        if ($request->input('then') !== 'send') {
            return $this->run($id, fn () => $this->desk->updateDraft((int) $id, $admin, $clean), 'Draft saved. You can keep editing it until you send it.');
        }

        try {
            $this->desk->updateDraft((int) $id, $admin, $clean);
        } catch (PipelineException $e) {
            return redirect()->route('admin.service-invoices.show', $id)->withErrors(['invoice' => $e->getMessage()]);
        }

        return $this->run($id, fn () => $this->desk->issue((int) $id, $admin), 'Invoice sent to the customer. It can no longer be edited.');
    }

    public function destroy($id)
    {
        $invoice = ServiceInvoice::findOrFail($id);
        $back    = route('admin.service-inquiries.show', $invoice->service_inquiry_id);

        try {
            $this->desk->deleteDraft((int) $id, Auth::guard('admin')->user());
        } catch (PipelineException $e) {
            return redirect()->route('admin.service-invoices.show', $id)->withErrors(['invoice' => $e->getMessage()]);
        }

        return redirect($back)->with('success', 'Draft deleted. It had no invoice number, so nothing is missing from the numbering.');
    }

    public function issue($id)
    {
        ServiceInvoice::findOrFail($id);

        return $this->run($id, fn () => $this->desk->issue((int) $id, Auth::guard('admin')->user()), 'Invoice sent to the customer. It can no longer be edited.');
    }

    public function pay(Request $request, $id)
    {
        ServiceInvoice::findOrFail($id);
        $data = $request->validate([
            'paid_method'     => 'required|in:' . implode(',', array_keys(ServiceInvoice::PAID_METHODS)),
            'paid_reference'  => 'nullable|string|max:100',
            'amount_received' => ['required', 'numeric', 'gt:0', 'max:' . ServiceInvoiceDesk::MAX_AMOUNT, 'regex:/^\d+(\.\d{1,2})?$/'],
            'paid_date'       => 'required|date_format:Y-m-d',
        ], [
            'paid_method.required'     => 'Choose how the customer paid.',
            'amount_received.required' => 'Enter the amount received.',
            'amount_received.regex'    => 'Amounts can have at most 2 decimal places.',
            'paid_date.required'       => 'Enter the date the payment was received.',
        ]);

        return $this->run($id, fn () => $this->desk->markPaid((int) $id, Auth::guard('admin')->user(), [
            'method' => $data['paid_method'], 'reference' => $data['paid_reference'] ?? null,
            'amount' => $data['amount_received'], 'date' => $data['paid_date'],
        ]), 'Payment recorded.');
    }

    public function void(Request $request, $id)
    {
        ServiceInvoice::findOrFail($id);
        $data = $request->validate(
            ['reason' => 'required|string|min:' . ServiceInvoiceDesk::MIN_CANCEL_REASON . '|max:1000'],
            [
                'reason.required' => 'A reason is required to cancel an invoice.',
                'reason.min'      => 'Please give a reason of at least ' . ServiceInvoiceDesk::MIN_CANCEL_REASON . ' characters. The customer will see it.',
            ]
        );

        return $this->run($id, fn () => $this->desk->void((int) $id, Auth::guard('admin')->user(), $data['reason']), 'Invoice cancelled. You can send a new one from the ticket.');
    }

    public function serviceDone($id)
    {
        ServiceInvoice::findOrFail($id);

        return $this->run($id, fn () => $this->payments->serviceDone((int) $id, Auth::guard('admin')->user()), 'Service marked as done. If the invoice is half and half, the customer has been told the balance is due.');
    }

    public function recordCash(Request $request, $id)
    {
        ServiceInvoice::findOrFail($id);
        $data = $request->validate([
            'amount'  => ['required', 'numeric', 'gt:0', 'max:' . ServiceInvoiceDesk::MAX_AMOUNT, 'regex:/^\d+(\.\d{1,2})?$/'],
            'confirm' => ['accepted'],
        ], [
            'amount.required'    => 'Enter the cash amount received.',
            'amount.regex'       => 'Amounts can have at most 2 decimal places.',
            'confirm.accepted'   => 'Please confirm that you received this cash payment.',
        ]);

        return $this->run($id, fn () => $this->payments->recordCash((int) $id, Auth::guard('admin')->user(), $data['amount'], true), 'Cash payment recorded.');
    }

    public function print($id)
    {
        $invoice = ServiceInvoice::with(['lines', 'payments'])->findOrFail($id);

        return view('service-invoices.print', [
            'invoice' => $invoice,
            'backUrl' => route('admin.service-invoices.show', $invoice->id),
        ]);
    }

    private function run($id, callable $action, string $message)
    {
        try {
            $action();
        } catch (PipelineException $e) {
            return redirect()->route('admin.service-invoices.show', $id)->withErrors(['invoice' => $e->getMessage()])
                ->with('offer_cancellation_request', $e->getMessage() === ServiceInvoicePayments::CANCEL_BLOCKED);
        }

        return redirect()->route('admin.service-invoices.show', $id)->with('success', $message);
    }
}
