<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Delivery;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Notification;
use App\Models\User;
use App\Support\InstallmentScheduler;
use App\Support\OrderContact;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // ── SHOW CHECKOUT PAGE ──
    public function checkout()
    {
        if (session()->has('buy_now')) {
            $cartItems = $this->getBuyNowItems();

            if ($cartItems->isEmpty()) {
                session()->forget('buy_now');
                return redirect()->route('catalog')
                    ->with('error', 'That product is no longer available.');
            }

            $subtotal = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);
            $buyNow   = session('buy_now');

            return view('checkout', compact('cartItems', 'subtotal', 'buyNow'));
        }

        $cartItems = CartItem::with('product')
            ->where('user_id', Auth::id())
            ->paginate(10);

        if ($cartItems->isEmpty()) {
            return redirect()->route('cart.index')
                ->with('error', 'Your cart is empty.');
        }

        $subtotal = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);
        $buyNow = null;

        return view('checkout', compact('cartItems', 'subtotal', 'buyNow'));
    }

    // ── PLACE ORDER ──
    public function placeOrder(Request $request)
    {
        $request->validate([
            'delivery_option'        => 'required|in:pickup,delivery',
            'payment_type'           => 'required|in:full,installment',
            // Duration (months) and frequency (days between payments)
            // are picked independently and combined to determine the
            // number of installment payments — see InstallmentScheduler::createSchedule().
            'installment_duration'   => 'required_if:payment_type,installment|nullable|integer|in:1,2,3',
            'installment_frequency'  => 'required_if:payment_type,installment|nullable|integer|in:15,30',
            'payment_method'         => 'required|in:stripe,gcash,onsite',
            'customization_note'     => 'nullable|string',
        ]);

        // Every order needs a valid phone; a delivery order also needs the complete structured address (pickup at our shop
        // needs none). Checked here, on the server, whatever the page did; redirects back with the field errors.
        $contact = OrderContact::fromRequest($request, Auth::user(), $request->delivery_option === 'delivery');

        $isBuyNow = $request->filled('buy_now_product_id');

        $cartItems = $isBuyNow
            ? $this->getBuyNowItemsFromRequest($request)
            : CartItem::with('product')->where('user_id', Auth::id())->get();

        if ($cartItems->isEmpty()) {
            session()->forget('buy_now');
            return redirect()->route('cart.index')
                ->with('error', $isBuyNow ? 'That product is no longer available.' : 'Your cart is empty.');
        }

        $subtotal     = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);
        $deliveryFee  = $request->delivery_option === 'delivery' ? 500 : 0;
        $total        = $subtotal + $deliveryFee;

        // Double-submit guard: if this exact user just created an
        // identical order (same total + same items) in the last few
        // seconds, treat this as a duplicate click/resubmit rather
        // than a genuine second order — redirect to the first one
        // instead of creating a new order and double-decrementing
        // stock. Runs before the transaction so a caught duplicate
        // never touches the stock row-lock or does any writes.
        $duplicate = $this->findRecentDuplicateOrder(Auth::id(), $total, $cartItems);
        if ($duplicate) {
            return $this->redirectForOrder($duplicate);
        }

        $order      = null;
        $stockError = null;

        try {
            DB::transaction(function () use ($request, $cartItems, $subtotal, $deliveryFee, $total, $isBuyNow, $contact, &$order, &$stockError) {
                $isInstallment = $request->payment_type === 'installment';
                // 50% down now, the rest split into the schedule below.
                // Derived by subtraction (not computed independently)
                // so downpayment + remaining always sum exactly to $total.
                $downpayment = $isInstallment ? InstallmentScheduler::catalogDownpayment($total) : $total;

                // Re-check stock INSIDE the transaction, with a row
                // lock, right before committing to the order. This
                // protects against two customers checking out the
                // same low-stock item at nearly the same moment —
                // whichever transaction gets here first wins, the
                // second one fails cleanly instead of overselling.
                foreach ($cartItems as $item) {
                    $product = Product::where('id', $item->product_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$product || $product->stock_quantity < $item->quantity) {
                        $stockError = [
                            'product_name' => $product->product_name ?? $item->product->product_name ?? 'This item',
                            'available'    => $product->stock_quantity ?? 0,
                            'requested'    => $item->quantity,
                        ];
                        throw new \RuntimeException('insufficient_stock');
                    }
                }

                // Keep the address the customer asked to keep (and a missing account phone) with the order itself.
                OrderContact::persist(User::whereKey(Auth::id())->lockForUpdate()->first(), $contact);

                // Generate unique order number
                $orderNumber = 'LP-' . date('Y') . '-' . str_pad(
                    Order::count() + 1, 5, '0', STR_PAD_LEFT
                );

                // Create the order
                $order = Order::create([
                    'user_id'             => Auth::id(),
                    'order_number'        => $orderNumber,
                    'total_amount'        => $total,
                    'delivery_option'     => $request->delivery_option,
                    'delivery_address'    => $contact['snapshot']['full'] ?? null,
                    'delivery_snapshot'   => $contact['snapshot'],
                    'delivery_fee'        => $deliveryFee,
                    'customization_note'  => $request->customization_note,
                    'production_status'   => 'pending',
                    'order_status'        => 'pending',
                ]);
                \App\Support\OrderTracking::recordPlaced($order);

                // Create order items from cart, and decrement stock
                // for each one now that we've confirmed it's available
                foreach ($cartItems as $item) {
                    OrderItem::create([
                        'order_id'      => $order->id,
                        'product_id'    => $item->product_id,
                        'product_name'  => $item->product->product_name,
                        'product_image' => $item->product->mainPhoto()?->image_path,
                        'quantity'      => $item->quantity,
                        'unit_price'    => $item->product->price,
                        'subtotal'      => $item->product->price * $item->quantity,
                    ]);

                    Product::where('id', $item->product_id)
                        ->decrement('stock_quantity', $item->quantity);
                }

                // Create payment record — amount is the downpayment
                // (50%) for installment orders, the full total otherwise.
                // This is the single source of truth PaymentController
                // reads from for what to actually charge right now.
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'user_id' => Auth::id(),
                    'amount' => $downpayment,
                    'payment_method' => $request->payment_method,
                    'payment_type' => $request->payment_type,
                    'installment_duration_months' => $isInstallment ? $request->installment_duration : null,
                    'installment_frequency_days' => $isInstallment ? $request->installment_frequency : null,
                    'payment_status' => 'pending',
                ]);

                if ($isInstallment) {
                    InstallmentScheduler::createSchedule(
                        $payment,
                        $order,
                        $total - $downpayment,
                        (int) $request->installment_duration,
                        (int) $request->installment_frequency
                    );
                }

                // Create delivery record if delivery option chosen
                if ($request->delivery_option === 'delivery') {
                    Delivery::create([
                        'order_id'         => $order->id,
                        'delivery_address' => $contact['snapshot']['full'] ?? null,
                        'contact_number'   => PhoneNumber::display($contact['phone']),
                        'status'           => 'waiting_for_courier',
                    ]);
                }

                // Only clear the REAL cart if this was a normal cart
                // checkout. A buy-now purchase must never touch the
                // customer's actual persistent cart items.
                if ($isBuyNow) {
                    session()->forget('buy_now');
                } else {
                    CartItem::where('user_id', Auth::id())->delete();
                }

                // Create notification
                Notification::create([
                    'user_id' => Auth::id(),
                    'title'   => 'Order Placed',
                    'message' => "Your order {$orderNumber} has been placed and is pending confirmation.",
                    'type'    => 'order_update',
                    'url'     => route('orders.show', $order->id),
                ]);
            });
        } catch (\RuntimeException $e) {
            if ($stockError) {
                return back()->withInput()->with('stock_error', $stockError);
            }
            return back()->withInput()->with('error', 'Something went wrong placing your order. Please try again.');
        } catch (\Exception $e) {
            return back()->withInput()->with('error', 'Something went wrong placing your order. Please try again.');
        }

        return $this->redirectForOrder($order);
    }

    // ── Redirect target for a placed order, based on its payment
    //    method — shared by both the normal success path and the
    //    double-submit guard, so a duplicate redirect behaves
    //    identically to a fresh order redirect. ──
    public function redirectForOrder(Order $order)
    {
        $paymentMethod = $order->payment->payment_method ?? null;

        if ($paymentMethod === 'stripe') {
            return redirect()->route('payment.stripe', $order->id);
        }

        if ($paymentMethod === 'gcash') {
            // Real PayMongo gateway is the default now — the manual
            // receipt-upload flow (route('payment.gcash', ...)) is
            // kept intact and still directly reachable as a fallback,
            // just no longer where checkout sends GCash orders.
            return redirect()->route('payment.gcash.paymongo', $order->id);
        }

        // Onsite/cash — order is placed, no online payment needed yet
        return redirect()->route('orders.show', $order->id)
            ->with('success', 'Order placed successfully! Reference: ' . $order->order_number);
    }

    // ── Double-submit guard: find a same-user order created in the
    //    last few seconds with the same total AND the same items
    //    (product_id + quantity), to distinguish a genuine duplicate
    //    click from a coincidentally-identical total. ──
    private function findRecentDuplicateOrder(int $userId, float $total, $cartItems): ?Order
    {
        $signature = $cartItems
            ->map(fn($item) => $item->product_id . ':' . $item->quantity)
            ->sort()
            ->values()
            ->all();

        return Order::where('user_id', $userId)
            ->where('total_amount', $total)
            ->where('created_at', '>=', now()->subSeconds(10))
            ->with('items')
            ->latest()
            ->get()
            ->first(function ($candidate) use ($signature) {
                $candidateSignature = $candidate->items
                    ->map(fn($item) => $item->product_id . ':' . $item->quantity)
                    ->sort()
                    ->values()
                    ->all();

                return $candidateSignature === $signature;
            });
    }

    // The installment schedule builder moved to App\Support\InstallmentScheduler
    // (shared with custom orders); catalog behaviour is unchanged.

    // ── VIEW ALL ORDERS ──
    public function index()
    {
        $orders = Order::with(['items.product', 'payment', 'delivery'])
            ->where('user_id', Auth::id())
            ->latest()
            ->paginate(10);

        return view('orders.index', compact('orders'));
    }

    // ── VIEW SINGLE ORDER ──
    public function show($id)
    {
        $order = Order::with(['items.product', 'payment', 'delivery'])
            ->where('user_id', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        // Catalog orders get the Track My Order page; customized orders keep their own (agreement + timeline).
        if (\App\Support\OrderTracking::handles($order)) {
            return view('orders.track', [
                'order'  => $order,
                'pay'    => \App\Support\OrderTracking::payment($order),
                // Only what the customer was told; corrections are never notified and never listed.
                'events' => $order->events()->forCustomer()->get(),
            ]);
        }

        $timeline = [];
        if ($order->design_request_id) {
            $designRequest = \App\Models\CustomDesignRequest::where('user_id', Auth::id())->find($order->design_request_id);
            $timeline = $designRequest ? app(\App\Support\CustomOrderTimeline::class)->forCustomer($designRequest) : [];
        }

        return view('orders.show', compact('order', 'timeline'));
    }

    // ── CHANGE PAYMENT METHOD ──
    // Lets a customer switch Stripe/GCash/Cash on an order that
    // hasn't been paid yet, without going back through checkout
    // (which can't work anyway — the cart/buy-now session is
    // already cleared by the time this page exists). Only ever
    // UPDATEs the existing Payment row — never creates a new
    // Order or Payment, so there's no duplicate-order risk here.
    //
    // amount/payment_type/installment_* are deliberately left
    // untouched: payment_method has no bearing on what's owed
    // (see placeOrder() — amount is derived from payment_type
    // alone), so switching method never needs a recalculation.
    public function updatePaymentMethod(Request $request, $id)
    {
        $request->validate([
            'payment_method' => 'required|in:stripe,gcash,onsite',
        ]);

        $order = Order::where('user_id', Auth::id())
            ->where('id', $id)
            ->firstOrFail();

        // order_status (not just payment_status) is checked
        // deliberately: onsite/cash payment_status never becomes
        // 'paid' through any existing flow, so payment_status
        // alone can't tell us whether this order is still "open"
        // for a method switch once an admin has moved it along.
        if (!$order->payment || $order->order_status !== 'pending' || $order->payment->payment_status === 'paid') {
            abort(403);
        }

        // A customized order can only be paid online (stripe/gcash), and
        // switches go through one service (lock, checks, old Stripe session
        // expired, event). Catalog orders keep the rules below.
        if ($order->design_agreement_id !== null) {
            return $this->switchCustomizedOrder($request, $order);
        }

        // Null out every OTHER method's gateway-tracking fields.
        // This isn't just tidiness — it closes a real race: e.g. a
        // customer starts a GCash attempt, authorizes it in the
        // GCash app, then switches to Stripe before the PayMongo
        // webhook arrives. The webhook looks the payment up by
        // paymongo_payment_intent_id; if that column still held
        // the stale ID, a late webhook would wrongly mark this
        // order paid via GCash even though it's now set to Stripe.
        // With the ID cleared, the same webhook safely finds no
        // matching payment and no-ops (see paymongoWebhook()).
        Payment::where('order_id', $order->id)->update([
            'payment_method'             => $request->payment_method,
            'payment_status'             => 'pending',
            'stripe_payment_intent_id'   => null,
            'stripe_client_secret'       => null,
            'stripe_charge_id'           => null,
            'stripe_customer_id'         => null,
            'paymongo_payment_intent_id' => null,
            'paymongo_payment_method_id' => null,
            'gcash_reference_number'     => null,
            'receipt_image_path'         => null,
            'verified_by'                => null,
            'verified_at'                => null,
            'qr_hash'                    => null,
            'qr_is_active'               => false,
        ]);

        return $this->redirectForOrder($order->fresh(['payment']))
            ->with('success', 'Payment method updated.');
    }

    private function switchCustomizedOrder(Request $request, Order $order)
    {
        try {
            $result = app(\App\Support\PaymentMethodSwitch::class)->switch($order->id, Auth::id(), $request->payment_method);
        } catch (\App\Support\PaymentSwitchRefused $e) {
            return redirect()->route('orders.show', $order->id)->withErrors(['payment_method' => $e->getMessage()]);
        }

        return $this->redirectForOrder($result['order']->fresh(['payment']))
            ->with('success', $result['changed'] ? 'Payment method updated.' : 'That is already your payment method.');
    }

    /* ════════════════════════════════
       Builds a cart-shaped collection from the buy_now session
       data, so the exact same checkout/place-order code that
       already works for real cart items also works unchanged
       for a buy-now purchase — no duplicated logic, no risk of
       the two flows drifting out of sync with each other.
    ════════════════════════════════ */
    private function getBuyNowItems()
    {
        $buyNow = session('buy_now');

        $product = Product::available()->find($buyNow['product_id'] ?? null);

        if (!$product) {
            return collect();
        }

        $fakeItem = (object) [
            'product_id' => $product->id,
            'product'    => $product,
            'quantity'   => $buyNow['quantity'] ?? 1,
            'note'       => $buyNow['note'] ?? null,
        ];

        return collect([$fakeItem]);
    }

    private function getBuyNowItemsFromRequest(Request $request)
    {
        $product = Product::available()->find($request->input('buy_now_product_id'));

        if (!$product) {
            return collect();
        }

        $fakeItem = (object) [
            'product_id' => $product->id,
            'product'    => $product,
            'quantity'   => $request->input('buy_now_quantity', 1),
            'note'       => $request->input('buy_now_note'),
        ];

        return collect([$fakeItem]);
    }
}