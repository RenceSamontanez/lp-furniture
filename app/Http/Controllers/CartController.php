<?php

namespace App\Http\Controllers;

use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CartController extends Controller
{
    public function index()
    {
        $cartItems = CartItem::with('product')
            ->where('user_id', Auth::id())
            ->get();

        $total = $cartItems->sum(fn($item) => $item->product->price * $item->quantity);

        return view('cart.index', compact('cartItems', 'total'));
    }

    public function add(Request $request, $id)
    {
        $product = Product::available()->findOrFail($id);
    
        $request->validate([
            'quantity' => 'sometimes|integer|min:1|max:' . $product->stock_quantity,
        ]);
    
        $existing = CartItem::where('user_id', Auth::id())
            ->where('product_id', $id)->first();
    
        if ($existing) {
            $existing->quantity += $request->input('quantity', 1);
            $existing->note      = $request->input('note', $existing->note);
            $existing->save();
        } else {
            CartItem::create([
                'user_id'    => Auth::id(),
                'product_id' => $id,
                'quantity'   => $request->input('quantity', 1),
                'note'       => $request->input('note'),
            ]);
        }
    
        // If Order Now was clicked, redirect straight to checkout
        if ($request->input('redirect_to') === 'checkout') {
            return redirect()->route('checkout')
                ->with('success', $product->product_name . ' ready for checkout!');
        }
    
        return back()->with('success', $product->product_name . ' added to cart!');
    }
    public function update(Request $request, $id)
    {
        $item = CartItem::where('user_id', Auth::id())->findOrFail($id);

        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $item->update(['quantity' => $request->quantity]);

        return back()->with('success', 'Cart updated.');
    }

    public function remove($id)
    {
        CartItem::where('user_id', Auth::id())->findOrFail($id)->delete();
        return back()->with('success', 'Item removed from cart.');
    }

    public function clear()
    {
        CartItem::where('user_id', Auth::id())->delete();
        return back()->with('success', 'Cart cleared.');
    }
}