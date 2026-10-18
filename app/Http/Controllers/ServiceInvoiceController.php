<?php

namespace App\Http\Controllers;

use App\Models\ServiceInvoice;
use App\Models\ServiceInvoicePayment;
use App\Support\PipelineException;
use App\Support\ServiceInvoicePayments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A customer's own Other Services invoice: view it, print it, and pay it online (GCash through PayMongo,
 * or card through Stripe). Scoped to the signed-in customer in the query itself, so another customer's invoice,
 * a draft, or an id that does not exist is a plain 404 and reveals nothing. A cancelled invoice is shown as an
 * explanation page (no amount due, no way to pay or print). The amounts to pay always come from the server.
 */
class ServiceInvoiceController extends Controller
{
    private const METHODS = ['stripe' => ServiceInvoicePayment::STRIPE, 'gcash' => ServiceInvoicePayment::PAYMONGO];

    public function __construct(private ServiceInvoicePayments $payments)
    {
    }

    private function mine($id): ServiceInvoice
    {
        return ServiceInvoice::with(['lines', 'inquiry', 'payments'])
            ->where('user_id', Auth::id())
            ->openableByCustomer()
            ->findOrFail($id);
    }

    public function show($id)
    {
        $invoice = $this->mine($id);

        if ($invoice->isCancelled()) {
            return response()->view('service-invoices.cancelled', [
                'invoice' => $invoice,
                'ticket'  => $invoice->inquiry,
                'refund'  => $invoice->refundRequests()->where('status', 'refunded')->with('attempts')->first(),
            ])->header('Cache-Control', 'no-store, private');
        }

        return response()->view('service-invoices.show', [
            'invoice' => $invoice,
            'ticket'  => $invoice->inquiry,
            'due'     => ServiceInvoicePayments::due($invoice),
            'block'   => $invoice->hardBlockReason(),
            'openRequest' => $invoice->inquiry?->openRefundRequest(),
        ])->header('Cache-Control', 'no-store, private');
    }

    /** The printable sheet (route has `invoice.actionable`: never served for a cancelled invoice). */
    public function print($id)
    {
        $invoice = $this->mine($id);

        return response()->view('service-invoices.print', [
            'invoice' => $invoice,
            'backUrl' => route('services.invoice', $invoice->id),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function startPayment(Request $request, $id, string $method)
    {
        $invoice = $this->mine($id);

        // The route middleware already turns a cancelled invoice away; this and the service check are the second and third lock.
        if ($reason = $invoice->paymentBlockReason()) {
            return redirect()->route('services.invoice', $invoice->id)->with('error', $invoice->blockMessage($reason));
        }

        try {
            $url = $this->payments->start($invoice->id, self::METHODS[$method]);
        } catch (PipelineException $e) {
            return redirect()->route('services.invoice', $invoice->id)->with('error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    /**
     * Where Stripe / PayMongo send the customer back. This page never marks anything paid on the strength of the
     * URL: it asks the gateway itself (server to server) and shows what the invoice then says.
     */
    public function paymentReturn(Request $request, $id, string $outcome)
    {
        $invoice = $this->mine($id);

        if ($outcome === 'success') {
            if ($sessionId = (string) $request->query('session_id')) {
                $this->payments->verifyStripeReturn($invoice, $sessionId);
            } else {
                $this->payments->verifyPaymongoReturn($invoice);
            }
            $invoice = $this->mine($id);
        }

        $latest = $invoice->payments->whereIn('method', ['stripe', 'paymongo'])->last();
        $paid   = $latest && $latest->status === ServiceInvoicePayment::PAID;
        $state = match (true) {
            $outcome === 'cancelled' => 'cancelled',
            $outcome === 'failed'    => 'failed',
            $paid                    => 'paid',
            default                  => 'confirming',
        };

        return response()->view('service-invoices.payment-result', [
            'invoice' => $invoice,
            'state'   => $state,
        ])->header('Cache-Control', 'no-store, private');
    }
}
