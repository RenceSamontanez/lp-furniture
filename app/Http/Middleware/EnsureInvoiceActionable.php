<?php

namespace App\Http\Middleware;

use App\Models\ServiceInvoice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Put this on any customer route that ACTS on a service invoice (pay, print, download, start an online payment).
 * The invoice must belong to the signed-in customer and must not be a draft (otherwise a plain 404, nothing leaks).
 * A cancelled invoice is sent to its explanation page with a toast, so no payment form or printable copy is ever served.
 * The admin's own print of a cancelled invoice is a separate admin route and is not affected.
 */
class EnsureInvoiceActionable
{
    public const CANCELLED_MESSAGE = 'This invoice was cancelled, so it can no longer be paid or printed.';

    public function handle(Request $request, Closure $next)
    {
        $invoice = ServiceInvoice::where('user_id', Auth::id())
            ->openableByCustomer()
            ->findOrFail($request->route('id'));

        if ($invoice->isCancelled()) {
            return redirect()->route('services.invoice', $invoice->id)->with('error', self::CANCELLED_MESSAGE);
        }

        return $next($request);
    }
}
