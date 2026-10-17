<?php

namespace App\Http\Controllers;

use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\Auth;

/**
 * A customer's own Other Services invoice, read-only. Scoped to the signed-in customer in the query itself,
 * so another customer's invoice, a draft, or an id that does not exist is a plain 404 and reveals nothing.
 * A cancelled invoice is shown as an explanation page (no amount due, no way to pay or print).
 */
class ServiceInvoiceController extends Controller
{
    public function show($id)
    {
        $invoice = ServiceInvoice::with(['lines', 'inquiry'])
            ->where('user_id', Auth::id())
            ->openableByCustomer()
            ->findOrFail($id);

        if ($invoice->isCancelled()) {
            return response()->view('service-invoices.cancelled', [
                'invoice' => $invoice,
                'ticket'  => $invoice->inquiry,
            ])->header('Cache-Control', 'no-store, private');
        }

        return response()->view('service-invoices.print', [
            'invoice' => $invoice,
            'backUrl' => route('services.request'),
        ])->header('Cache-Control', 'no-store, private');
    }
}
