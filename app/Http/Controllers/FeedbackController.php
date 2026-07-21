<?php

namespace App\Http\Controllers;

use App\Models\Feedback;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FeedbackController extends Controller
{
    public function index()
{
    $feedbacks = Feedback::approved()
        ->with(['user', 'order.items.product'])
        ->latest()
        ->paginate(9);

    $stats = [
        'total'   => Feedback::approved()->count(),
        'average' => round(Feedback::approved()->avg('rating') ?? 0, 1),
    ];

    $reviewableOrders = [];
    if (Auth::check()) {
        $reviewableOrders = Order::where('user_id', Auth::id())
            ->where('order_status', 'completed')
            ->whereDoesntHave('feedback')
            ->with('items.product')
            ->latest()
            ->get();
    }

    return view('feedback.index', compact('feedbacks', 'stats', 'reviewableOrders'));
}

    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id',
            'rating'   => 'required|integer|min:1|max:5',
            'comment'  => 'required|string|min:10|max:1000',
        ]);

        // Confirm this order belongs to the logged-in user and is completed
        $order = Order::where('id', $request->order_id)
            ->where('user_id', Auth::id())
            ->where('order_status', 'completed')
            ->first();

        if (!$order) {
            return back()->withErrors(['order_id' => 'This order is not eligible for a review.']);
        }

        // Prevent duplicate reviews for the same order
        if (Feedback::where('order_id', $order->id)->exists()) {
            return back()->withErrors(['order_id' => 'You already left a review for this order.']);
        }

        Feedback::create([
            'user_id'     => Auth::id(),
            'order_id'    => $order->id,
            'rating'      => $request->rating,
            'comment'     => $request->comment,
            'is_approved' => false,
        ]);

        return back()->with('success', 'Thank you for your review! It will appear publicly after admin approval.');
    }
}