<?php

namespace App\Http\Controllers;

use App\Models\ServiceInvoice;
use Illuminate\Support\Facades\Auth;

/**
 * A customer's own issued or paid Other Services invoice, read-only and printable. Scoped to the
 * signed-in customer in the query itself, so another customer's invoice (or a draft or void one) is a 404.
 */
class ServiceInvoiceController extends Controller
{
    public function show($id)
    {
        $invoice = ServiceInvoice::with('lines')
            ->where('user_id', Auth::id())
            ->visibleToCustomer()
            ->findOrFail($id);

        return response()->view('service-invoices.print', [
            'invoice' => $invoice,
            'backUrl' => route('services.request'),
        ])->header('Cache-Control', 'no-store, private');
    }
}
