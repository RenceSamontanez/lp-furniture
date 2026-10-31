<?php

namespace App\Http\Controllers;

use App\Models\InstallmentAttempt;
use App\Models\InstallmentSchedule;
use App\Models\Order;
use App\Models\Payment;
use App\Support\Installments\InstallmentGateway;
use App\Support\Installments\InstallmentPayments;
use App\Support\Installments\PlanMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The customer's side of an installment plan: Pay Now on one installment, Pay Remaining Balance In Full,
 * Update Card, and the public Installment Terms page. Every action is scoped to the signed-in customer's own order.
 */
class InstallmentController extends Controller
{
    public function __construct(private InstallmentGateway $gateway, private InstallmentPayments $payments)
    {
    }

    public function terms()
    {
        return view('installments.terms', ['t' => config('installments.terms'), 'version' => config('installments.terms_version')]);
    }

    /* ───────── helpers ───────── */

    private function planOrder(int $orderId): array
    {
        $order = Order::with('payment')->where('user_id', Auth::id())->where('id', $orderId)->firstOrFail();
        $plan = $order->payment;
        abort_unless($plan && $plan->payment_type === 'installment' && $plan->installments_started_at && $order->order_status !== 'cancelled', 404);

        return [$order, $plan];
    }

    private function back(int $orderId, string $kind, string $message)
    {
        return redirect()->route('orders.show', $orderId)->with($kind, $message);
    }

    private function method(Request $request): string
    {
        return $request->validate(['method' => 'required|in:card,gcash'])['method'];
    }

    /** The statuses a customer may pay from. */
    private const PAYABLE = ['pending', 'failed', 'link_sent', 'overdue'];

    /* ───────── Pay Now (one installment) ───────── */

    public function pay(Request $request, int $order, int $installment)
    {
        [$order, $plan] = $this->planOrder($order);
        $method = $this->method($request);
        $row = InstallmentSchedule::where('order_id', $order->id)->findOrFail($installment);

        if (!in_array($row->status, self::PAYABLE, true)) {
            return $this->back($order->id, 'error', $row->status === 'paid' ? 'That installment is already paid.' : 'That payment is being processed. Please check again in a moment.');
        }

        return $this->payRows($order, $plan, collect([$row]), $method, 'pay_now', "installment {$row->installment_no}");
    }

    /* ───────── Pay Remaining Balance In Full ───────── */

    public function payFull(Request $request, int $order)
    {
        [$order, $plan] = $this->planOrder($order);
        $method = $this->method($request);
        $rows = InstallmentSchedule::where('payment_id', $plan->id)->whereIn('status', self::PAYABLE)->orderBy('installment_no')->get();

        if ($rows->isEmpty()) {
            return $this->back($order->id, 'error', 'Nothing is left to pay.');
        }
        if (InstallmentSchedule::where('payment_id', $plan->id)->where('status', 'processing')->exists()) {
            return $this->back($order->id, 'error', 'A payment is being processed. Please check again in a moment.');
        }

        return $this->payRows($order, $plan, $rows, $method, 'pay_full', 'the remaining balance');
    }

    private function payRows(Order $order, Payment $plan, $rows, string $method, string $via, string $what)
    {
        $total = round((float) $rows->sum('amount_due'), 2);
        $centavos = (int) round($total * 100);
        $desc = "LP Furniture order {$order->order_number} ({$what})";
        $meta = ['kind' => 'installment', 'installment_schedule_id' => $rows->first()->id, 'order_id' => $order->id];
        $ids = $rows->pluck('id')->all();
        $first = $rows->first();

        if ($method === 'gcash') {
            $result = $this->gateway->startGcash(Auth::user(), $centavos, $desc, $meta, route('installments.return', $order->id));
            if ($result->failedNow() || !$result->redirectUrl) {
                return $this->back($order->id, 'error', "We couldn't start the GCash payment. Please try again.");
            }
            InstallmentPayments::attempt($first, $via, 'info', 'GCash payment started for ' . $what, $result->reference);

            return redirect($result->redirectUrl);
        }

        // Card: only the saved card can be charged here (Update Card puts a new one on file).
        if (!PlanMode::autoCharges($plan)) {
            return $this->back($order->id, 'error', 'There is no saved card on this plan. Use GCash, or update your card.');
        }

        // Hold the rows while the card is charged, so a double click cannot charge twice.
        $before = $rows->pluck('status', 'id')->all();
        $claimed = InstallmentSchedule::whereIn('id', $ids)->whereIn('status', self::PAYABLE)->update(['status' => 'processing', 'last_attempt_at' => now()]);
        if ($claimed !== count($ids)) {
            foreach ($before as $id => $status) {
                InstallmentSchedule::where('id', $id)->where('status', 'processing')->update(['status' => $status]);
            }

            return $this->back($order->id, 'error', 'That payment is already being processed.');
        }
        $restore = function () use ($before) {
            foreach ($before as $id => $status) {
                InstallmentSchedule::where('id', $id)->where('status', 'processing')->update(['status' => $status]);
            }
        };

        $result = $this->gateway->chargeSavedCard((string) $plan->paymongo_customer_id, (string) $plan->installment_card_method_id, $centavos, $desc, $meta);

        if ($result->ok()) {
            $restore();
            $this->payments->recordGateway($ids, PlanMode::cardLedgerMethod(), (string) $result->reference, $via, $this->gateway->isSimulated());

            return $this->back($order->id, 'success', $via === 'pay_full' ? 'Balance paid in full. Thank you.' : 'Payment received. Thank you.');
        }
        if ($result->status === 'pending') {
            $restore();
            InstallmentPayments::attempt($first, $via, 'info', 'Card payment started for ' . $what, $result->reference);

            return $result->redirectUrl ? redirect($result->redirectUrl) : $this->back($order->id, 'success', 'Your payment is being processed.');
        }

        $restore();
        InstallmentPayments::attempt($first, $via, 'failed', 'Saved card could not be charged' . ($result->message ? ' (' . $result->message . ')' : ''), $result->reference, $this->gateway->isSimulated());

        return $this->back($order->id, 'error', 'We could not charge your saved card. Try GCash, or update your card.');
    }

    /* ───────── Back from GCash / 3-D Secure ───────── */

    public function paymentReturn(Request $request, int $order)
    {
        [$order, $plan] = $this->planOrder($order);

        $intent = (string) ($request->query('payment_intent_id') ?: $request->query('pi'));
        // The intent must be one WE started for this order's installments.
        $attempt = $intent === '' ? null : InstallmentAttempt::where('gateway_reference', $intent)
            ->whereIn('kind', ['pay_now', 'pay_full'])
            ->whereIn('installment_schedule_id', InstallmentSchedule::where('order_id', $order->id)->select('id'))
            ->latest('id')->first();
        if (!$attempt) {
            return $this->back($order->id, 'error', 'We could not find that payment. If you were charged, it will appear here shortly.');
        }

        $info = $this->gateway->lookupIntent($intent);
        if ($info && $info['status'] === 'succeeded') {
            $isFull = $attempt->kind === 'pay_full';
            $ids = $isFull
                ? InstallmentSchedule::where('payment_id', $plan->id)->whereIn('status', InstallmentSchedule::OPEN)->pluck('id')->all()
                : [$attempt->installment_schedule_id];
            $expected = (int) round(InstallmentSchedule::whereIn('id', $ids)->sum('amount_due') * 100);

            if ($ids !== [] && $info['amount'] === $expected) {
                $method = ($request->query('m') === 'card') ? 'card_paymongo' : 'gcash_paymongo';
                $this->payments->recordGateway($ids, $method, $intent, $isFull ? 'pay_full' : 'pay_now');
            }
            // A card used to pay (Update Card): remember it for the remaining installments.
            if ($pm = session()->pull('installment_new_card.' . $intent)) {
                $this->saveNewCard($plan, $pm, $intent);
            }

            return $this->back($order->id, 'success', 'Payment received. Thank you.');
        }

        return $this->back($order->id, 'error', 'We have not received that payment yet. If you approved it, it will appear here shortly.');
    }

    /* ───────── Update Card ───────── */

    public function cardForm(int $order)
    {
        [$order, $plan] = $this->planOrder($order);
        $next = InstallmentSchedule::where('payment_id', $plan->id)->whereIn('status', self::PAYABLE)->orderBy('installment_no')->first();
        abort_unless($plan->payment_method === 'paymongo_card' && $next, 404);

        return view('installments.card', [
            'order' => $order, 'title' => 'Update Your Card', 'amount' => (float) $next->amount_due,
            'postUrl' => route('installments.card.store', $order->id), 'backUrl' => route('orders.show', $order->id),
            'purpose' => "installment {$next->installment_no}, paid with your new card", 'publicKey' => config('services.paymongo.public_key'),
        ]);
    }

    /** Pays the next installment with the new card; the card PayMongo vaults then replaces the old one. */
    public function cardStore(Request $request, int $order)
    {
        [$order, $plan] = $this->planOrder($order);
        $data = $request->validate(['payment_method_id' => ['required', 'string', 'regex:/^pm_[A-Za-z0-9]+$/']]);
        $next = InstallmentSchedule::where('payment_id', $plan->id)->whereIn('status', self::PAYABLE)->orderBy('installment_no')->first();
        if ($plan->payment_method !== 'paymongo_card' || !$next) {
            return response()->json(['error' => 'There is nothing to pay with a new card.'], 409);
        }

        $customer = $plan->paymongo_customer_id;
        if (!$customer) {
            try {
                $customer = $this->gateway->ensureCustomer(Auth::user());
            } catch (\Throwable $e) {
                $customer = null; // only needed for vaulting: never stops the payment
            }
        }
        try {
            $result = $this->gateway->startCardPayment($customer, $data['payment_method_id'], (int) round((float) $next->amount_due * 100),
                "LP Furniture order {$order->order_number} (installment {$next->installment_no}, new card)",
                ['kind' => 'installment', 'installment_schedule_id' => $next->id, 'order_id' => $order->id],
                route('installments.return', ['order' => $order->id, 'm' => 'card']));
        } catch (\Throwable $e) {
            return response()->json(['error' => "We couldn't start the card payment. Please try again."], 502);
        }
        if ($result->failedNow()) {
            return response()->json(['error' => 'Your card was declined. Please check the details or use another card.'], 422);
        }

        $plan->forceFill(['paymongo_customer_id' => $customer])->save();
        InstallmentPayments::attempt($next, 'pay_now', 'info', 'Card payment started with a new card', $result->reference);

        if ($result->ok()) {
            $this->payments->recordGateway([$next->id], 'card_paymongo', (string) $result->reference, 'pay_now');
            $this->saveNewCard($plan, $data['payment_method_id'], (string) $result->reference);

            return response()->json(['redirect' => route('orders.show', $order->id)]);
        }

        session()->put('installment_new_card.' . $result->reference, $data['payment_method_id']);

        return response()->json(['redirect' => $result->redirectUrl ?: route('installments.return', ['order' => $order->id, 'payment_intent_id' => $result->reference, 'm' => 'card'])]);
    }

    private function saveNewCard(Payment $plan, string $methodId, string $intent = ''): void
    {
        // Sandbox: the new card stays a simulated saved card; only its label comes from what PayMongo reported.
        if ($this->gateway->isSimulated()) {
            $paid = $intent !== '' ? ($this->gateway->lookupIntent($intent)['card'] ?? null) : null;
            $plan->forceFill([
                'card_simulated' => true,
                'installment_card_method_id' => 'sim_card_' . $plan->id,
                'card_brand' => !empty($paid['brand']) ? ucfirst((string) $paid['brand']) : 'Test Card',
                'card_last4' => $paid['last4'] ?? null,
            ])->save();

            return;
        }

        $card =collect($this->gateway->savedCards((string) $plan->paymongo_customer_id))->firstWhere('id', $methodId);
        $plan->forceFill([
            'installment_card_method_id' => $methodId,
            'card_brand' => isset($card['brand']) ? ucfirst((string) $card['brand']) : $plan->card_brand,
            'card_last4' => $card['last4'] ?? $plan->card_last4,
        ])->save();
    }
}
