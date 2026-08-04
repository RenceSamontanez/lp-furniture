<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Delivery;
use App\Models\CartItem;
use App\Models\Product;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // ── SHOW CHECKOUT PAGE ──
    public function checkout()
    {
        // If "Order Now" was used, a buy_now session exists —
        // show ONLY that single product, completely ignoring
        // whatever is sitting in the customer's real cart.
        if (session()->has('buy_now')) {
            $cartItems = $this->getBuyNowItems();

            if ($cartItems->isEmpty()) {
                // The product became unavailable between clicking
                // Order Now and reaching checkout — clear the stale
                // session and fall back gracefully.
                session()->forget('buy_now');
                return redirect()->route('catalog')
                    ->with('error', 'That product is no longer available.');
            }

            $subtotal = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);

            return view('checkout', compact('cartItems', 'subtotal'));
        }

        // Normal cart-based checkout (unchanged from before)
        $cartItems = CartItem::with('product')
            ->where('user_id', Auth::id())
            ->paginate(10);

        if ($cartItems->isEmpty()) {
            return redirect()->route('cart.index')
                ->with('error', 'Your cart is empty.');
        }

        $subtotal = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);

        return view('checkout', compact('cartItems', 'subtotal'));
    }

    // ── PLACE ORDER ──
    public function placeOrder(Request $request)
    {
        $request->validate([
            'delivery_option'     => 'required|in:pickup,delivery',
            'delivery_address'    => 'required_if:delivery_option,delivery|nullable|string',
            'payment_type'        => 'required|in:full,installment',
            'installment_period'  => 'required_if:payment_type,installment|nullable|string',
            'payment_method'      => 'required|in:stripe,gcash,onsite',
            'customization_note'  => 'nullable|string',
        ]);

        $isBuyNow = session()->has('buy_now');

        // Pull items from the buy_now session if that's the active
        // flow, otherwise fall back to the normal persistent cart —
        // these two sources never mix.
        $cartItems = $isBuyNow
            ? $this->getBuyNowItems()
            : CartItem::with('product')->where('user_id', Auth::id())->get();

        if ($cartItems->isEmpty()) {
            session()->forget('buy_now');
            return redirect()->route('cart.index')
                ->with('error', $isBuyNow ? 'That product is no longer available.' : 'Your cart is empty.');
        }

        $subtotal     = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);
        $deliveryFee  = $request->delivery_option === 'delivery' ? 500 : 0;
        $total        = $subtotal + $deliveryFee;

        $order = null;

        DB::transaction(function () use ($request, $cartItems, $subtotal, $deliveryFee, $total, $isBuyNow, &$order) {

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
                throw new \Exception(
                    'Sorry, "' . ($product->product_name ?? 'this item') . '" no longer has enough stock (only ' .
                    ($product->stock_quantity ?? 0) . ' left). Please update your cart and try again.'
                );
            }
        }

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
            'delivery_address'    => $request->delivery_address,
            'delivery_fee'        => $deliveryFee,
            'customization_note'  => $request->customization_note,
            'production_status'   => 'pending',
            'order_status'        => 'pending',
        ]);

        // Create order items from cart, and decrement stock
        // for each one now that we've confirmed it's available
        foreach ($cartItems as $item) {
            OrderItem::create([
                'order_id'      => $order->id,
                'product_id'    => $item->product_id,
                'product_name'  => $item->product->product_name,
                'product_image' => $item->product->product_image,
                'quantity'      => $item->quantity,
                'unit_price'    => $item->product->price,
                'subtotal'      => $item->product->price * $item->quantity,
            ]);

            Product::where('id', $item->product_id)
                ->decrement('stock_quantity', $item->quantity);
        }

        // Create payment record
        Payment::create([
            'order_id'       => $order->id,
            'user_id'        => Auth::id(),
            'amount'         => $total,
            'payment_method' => $request->payment_method,
            'payment_type'   => $request->payment_type,
            'payment_status' => 'pending',
        ]);

        // Create delivery record if delivery option chosen
        if ($request->delivery_option === 'delivery') {
            Delivery::create([
                'order_id'         => $order->id,
                'delivery_address' => $request->delivery_address,
                'contact_number'   => Auth::user()->phone_number,
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

        // Redirect based on payment method chosen
        if ($request->payment_method === 'stripe') {
            return redirect()->route('payment.stripe', $order->id);
        }

        if ($request->payment_method === 'gcash') {
            return redirect()->route('payment.gcash', $order->id);
        }

        // Onsite/cash — order is placed, no online payment needed yet
        return redirect()->route('orders.show', $order->id)
            ->with('success', 'Order placed successfully! Reference: ' . $order->order_number);
    }

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

        return view('orders.show', compact('order'));
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

        // A lightweight object mimicking the shape of a real
        // CartItem (->product, ->quantity, ->product_id) so the
        // rest of the checkout/order code needs zero special-casing.
        $fakeItem = (object) [
            'product_id' => $product->id,
            'product'    => $product,
            'quantity'   => $buyNow['quantity'] ?? 1,
            'note'       => $buyNow['note'] ?? null,
        ];

        return collect([$fakeItem]);
    }
}