<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\PaymentRecordException;
use App\Support\RecordPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin: Record Payment and Mark reviewed on the order page.
 * Routes live inside ['admin','admin.timeout']. The amount, balance
 * and every status change are decided by App\Support\RecordPayment.
 */
class PaymentRecordController extends Controller
{
    public const TOKEN_SESSION_KEY = 'record_payment_tokens';

    public function __construct(private RecordPayment $service)
    {
    }

    public function store(Request $request, $orderId)
    {
        Order::findOrFail($orderId);

        $data = $request->validate([
            'form_token'     => 'required|string|max:64',
            'amount'         => 'required|string|max:20',
            'method'         => 'required|string|max:30',
            'paid_on'        => 'required|string|max:10',
            'note'           => 'nullable|string|max:1000',
            'installment_id' => 'nullable|integer',
        ]);

        // One-time token: a second submit of the same rendered form
        // (double click, back button, second tab) finds it gone.
        $tokens = (array) session(self::TOKEN_SESSION_KEY, []);
        if (!in_array($data['form_token'], $tokens, true)) {
            return redirect()->route('admin.orders.show', $orderId)
                ->withErrors(['payment' => 'This form was already submitted or has expired. Check the ledger below before trying again.']);
        }
        session([self::TOKEN_SESSION_KEY => array_values(array_diff($tokens, [$data['form_token']]))]);

        try {
            $record = $this->service->record(
                (int) $orderId,
                Auth::guard('admin')->user(),
                $data['amount'],
                $data['method'],
                $data['paid_on'],
                $data['note'] ?? null,
                isset($data['installment_id']) && $data['installment_id'] !== '' ? (int) $data['installment_id'] : null,
            );
        } catch (PaymentRecordException $e) {
            return redirect()->route('admin.orders.show', $orderId)
                ->withInput($request->except('form_token'))
                ->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('admin.orders.show', $orderId)
            ->with('success', 'Payment of ₱' . number_format((float) $record->amount, 2) . ' recorded.');
    }

    public function markReviewed($orderId)
    {
        Order::findOrFail($orderId);

        try {
            $this->service->markReviewed((int) $orderId, Auth::guard('admin')->user());
        } catch (PaymentRecordException $e) {
            return redirect()->route('admin.orders.show', $orderId)->withErrors(['payment' => $e->getMessage()]);
        }

        return redirect()->route('admin.orders.show', $orderId)->with('success', 'Marked as reviewed.');
    }
}
