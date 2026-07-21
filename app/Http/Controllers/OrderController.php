<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Delivery;
use App\Models\CartItem;
use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // ── SHOW CHECKOUT PAGE ──
    public function checkout()
    {
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

        $cartItems = CartItem::with('product')
            ->where('user_id', Auth::id())
            ->get();

        if ($cartItems->isEmpty()) {
            return redirect()->route('cart.index')
                ->with('error', 'Your cart is empty.');
        }

        $subtotal     = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);
        $deliveryFee  = $request->delivery_option === 'delivery' ? 500 : 0;
        $total        = $subtotal + $deliveryFee;

        $order = null;

        DB::transaction(function () use ($request, $cartItems, $subtotal, $deliveryFee, $total, &$order) {

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

            // Create order items from cart
            foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id'   => $order->id,
                    'product_id' => $item->product_id,
                    'quantity'   => $item->quantity,
                    'unit_price' => $item->product->price,
                    'subtotal'   => $item->product->price * $item->quantity,
                ]);
            }

            // Create payment record
            $payment = Payment::create([
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

            // Clear the cart
            CartItem::where('user_id', Auth::id())->delete();

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
}