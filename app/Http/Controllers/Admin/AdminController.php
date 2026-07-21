<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\User;
use App\Models\UserValidation;
use App\Models\Notification;
use App\Models\Feedback;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class AdminController extends Controller
{
    /* ════════════════════════════════
       ADMIN LOGIN
    ════════════════════════════════ */
    public function showLogin()
    {
        if (Auth::guard('admin')->check() && Auth::guard('admin')->user()->role === 'admin') {
            return redirect()->route('admin.dashboard');
        }
        return view('admin.login');
    }

    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $key = 'admin_login_' . $request->ip();

        if (cache()->has($key . '_lockout')) {
            return back()->withErrors([
                'email' => 'Too many failed attempts. Please wait 60 seconds before trying again.',
            ])->withInput($request->only('email'));
        }

        if (Auth::guard('admin')->attempt($request->only('email', 'password'))) {
            if (Auth::guard('admin')->user()->role !== 'admin') {
                Auth::guard('admin')->logout();
                return back()->withErrors(['email' => 'This account does not have admin access.']);
            }

            cache()->forget($key);
            $request->session()->regenerate();

            return redirect()->route('admin.dashboard')
                ->with('success', 'Welcome back, ' . Auth::guard('admin')->user()->first_name . '.');
        }

        $attempts = cache()->get($key, 0) + 1;
        cache()->put($key, $attempts, now()->addMinutes(10));

        if ($attempts >= 5) {
            cache()->put($key . '_lockout', true, now()->addMinutes(1));
            return back()->withErrors(['email' => 'Too many failed attempts. Please wait 60 seconds.']);
        }

        return back()
            ->withInput($request->only('email'))
            ->withErrors(['email' => 'Incorrect email or password.']);
    }

    public function logout(Request $request)
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login')->with('success', 'Signed out.');
    }

    /* ════════════════════════════════
       DASHBOARD
    ════════════════════════════════ */
    public function dashboard()
    {
        $stats = [
            'pending_validations' => UserValidation::where('status', 'pending')->count(),
            'pending_orders'      => Order::where('order_status', 'pending')->count(),
            'total_orders'        => Order::count(),
            'total_customers'     => User::where('role', 'customer')->count(),
        ];

        $recentValidations = UserValidation::with('user')
            ->where('status', 'pending')
            ->orderBy('submitted_at', 'desc')
            ->take(5)
            ->get();

        $recentOrders = Order::with('user')
            ->latest()
            ->take(5)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentValidations', 'recentOrders'));
    }

    /* ════════════════════════════════
       VALIDATION QUEUE
    ════════════════════════════════ */
    public function validationsIndex(Request $request)
    {
        $status = $request->get('status', 'pending');

        $validations = UserValidation::with('user')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderBy('submitted_at', 'desc')
            ->paginate(15);

        return view('admin.validations.index', compact('validations', 'status'));
    }

    public function validationShow($id)
    {
        $validation = UserValidation::with('user')->findOrFail($id);
        return view('admin.validations.show', compact('validation'));
    }

    public function validationApprove($id)
    {
        $validation = UserValidation::findOrFail($id);
        $validation->update([
            'status'      => 'approved',
            'reviewed_by' => Auth::guard('admin')->id(),
            'reviewed_at' => now(),
        ]);
        $validation->user->update(['is_validated' => true]);

        Notification::create([
            'user_id' => $validation->user_id,
            'title'   => 'Identity Verified ✓',
            'message' => 'Your identity has been verified. You can now place orders.',
            'type'    => 'validation_approved',
            'url'     => route('home'),
            'is_read' => false,
        ]);

        return back()->with('success', 'User verified successfully.');
    }

    public function validationReject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $validation = UserValidation::findOrFail($id);
        $validation->update([
            'status'            => 'rejected',
            'rejection_reason'  => $request->rejection_reason,
            'reviewed_by'       => Auth::guard('admin')->id(),
            'reviewed_at'       => now(),
        ]);

        Notification::create([
            'user_id' => $validation->user_id,
            'title'   => 'Identity Verification Rejected',
            'message' => $request->rejection_reason,
            'type'    => 'validation_rejected',
            'url'     => route('validation.show'),
            'is_read' => false,
        ]);

        return back()->with('success', 'Validation rejected. Customer has been notified.');
    }

    public function showDocument($id, $type)
    {
        $validation = UserValidation::findOrFail($id);
        $path = $type === 'id'
            ? $validation->id_photo_path
            : $validation->selfie_photo_path;

        if (!$path || !Storage::disk('private')->exists($path)) {
            abort(404, 'Document not found.');
        }

        $fullPath = Storage::disk('private')->path($path);
        $mimeType = Storage::disk('private')->mimeType($path);

        return response()->file($fullPath, [
            'Content-Type' => $mimeType,
        ]);
    }

    /* ════════════════════════════════
       ORDERS
    ════════════════════════════════ */
    public function ordersIndex(Request $request)
    {
        $status = $request->get('status', 'all');

        $orders = Order::with('user', 'items.product')
            ->when($status !== 'all', fn($q) => $q->where('order_status', $status))
            ->latest()
            ->paginate(15);

        return view('admin.orders.index', compact('orders', 'status'));
    }

    public function orderShow($id)
    {
        $order = Order::with('user', 'items.product')->findOrFail($id);
        return view('admin.orders.show', compact('order'));
    }

    public function orderUpdateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status' => 'required|in:pending,confirmed,in_production,ready,out_for_delivery,completed,cancelled',
        ]);

        $order = Order::findOrFail($id);
        $order->update(['order_status' => $request->order_status]);

        $statusLabels = [
            'confirmed'        => 'Your order has been confirmed and will begin production soon.',
            'in_production'    => 'Your order is now in production.',
            'ready'            => 'Your order is ready for pickup!',
            'out_for_delivery' => 'Your order is out for delivery!',
            'completed'        => 'Your order has been completed. Thank you for choosing LP Furniture!',
            'cancelled'        => 'Your order has been cancelled.',
        ];

        if (isset($statusLabels[$request->order_status])) {
            Notification::create([
                'user_id' => $order->user_id,
                'title'   => 'Order Update — ' . $order->order_number,
                'message' => $statusLabels[$request->order_status],
                'type'    => 'order_update',
                'url'     => route('orders.show', $order->id),
                'is_read' => false,
            ]);
        }

        return back()->with('success', 'Order status updated.');
    }

    /* ════════════════════════════════
       REVIEWS APPROVAL
    ════════════════════════════════ */
    public function reviewsIndex(Request $request)
    {
        $status = $request->get('status', 'pending');

        $reviews = Feedback::with('user', 'order')
            ->when($status === 'pending', fn($q) => $q->where('is_approved', false))
            ->when($status === 'approved', fn($q) => $q->where('is_approved', true))
            ->latest()
            ->paginate(15);

        return view('admin.reviews.index', compact('reviews', 'status'));
    }

    public function reviewApprove($id)
    {
        $review = Feedback::findOrFail($id);
        $review->update(['is_approved' => true]);

        return back()->with('success', 'Review approved and now visible publicly.');
    }

    public function reviewReject($id)
    {
        $review = Feedback::findOrFail($id);
        $review->delete();

        return back()->with('success', 'Review rejected and removed.');
    }

    /* ════════════════════════════════
       GCASH PAYMENT VERIFICATION
    ════════════════════════════════ */
    public function paymentsIndex(Request $request)
    {
        $status = $request->get('status', 'pending');

        $payments = Payment::with('order.user')
            ->where('payment_method', 'gcash')
            ->when($status !== 'all', fn($q) => $q->where('payment_status', $status))
            ->latest()
            ->paginate(15);

        return view('admin.payments.index', compact('payments', 'status'));
    }

    public function paymentApprove($id)
    {
        $payment = Payment::findOrFail($id);
        $payment->update([
            'payment_status' => 'paid',
            'paid_at'        => now(),
        ]);

        $payment->order->update(['order_status' => 'confirmed']);

        Notification::create([
            'user_id' => $payment->user_id,
            'title'   => 'Payment Verified ✓',
            'message' => "Your GCash payment for order {$payment->order->order_number} has been verified. Your order is now confirmed!",
            'type'    => 'payment_confirmed',
            'url'     => route('orders.show', $payment->order_id),
            'is_read' => false,
        ]);

        return back()->with('success', 'Payment verified and order confirmed.');
    }

    public function paymentReject(Request $request, $id)
    {
        $request->validate([
            'rejection_reason' => 'required|string|max:500',
        ]);

        $payment = Payment::findOrFail($id);
        $payment->update(['payment_status' => 'failed']);

        Notification::create([
            'user_id' => $payment->user_id,
            'title'   => 'Payment Verification Failed',
            'message' => $request->rejection_reason,
            'type'    => 'payment_rejected',
            'url'     => route('orders.show', $payment->order_id),
            'is_read' => false,
        ]);

        return back()->with('success', 'Payment rejected. Customer has been notified.');
    }

    public function showReceipt($id)
    {
        $payment = Payment::findOrFail($id);

        if (!$payment->receipt_image_path || !Storage::disk('private')->exists($payment->receipt_image_path)) {
            abort(404, 'Receipt not found.');
        }

        $fullPath = Storage::disk('private')->path($payment->receipt_image_path);
        $mimeType = Storage::disk('private')->mimeType($payment->receipt_image_path);

        return response()->file($fullPath, ['Content-Type' => $mimeType]);
    }

    /* ════════════════════════════════
       REPORTS & ANALYTICS
    ════════════════════════════════ */
    public function reports()
    {
        // ── Revenue: last 6 months ──
        $revenueByMonth = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);

            $total = Order::whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->where('order_status', '!=', 'cancelled')
                ->sum('total_amount');

            $revenueByMonth[] = [
                'label' => $month->format('M'),
                'total' => (float) $total,
            ];
        }

        $totalRevenue = Order::where('order_status', '!=', 'cancelled')->sum('total_amount');
        $thisMonthRevenue = Order::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->where('order_status', '!=', 'cancelled')
            ->sum('total_amount');
        $lastMonthRevenue = Order::whereMonth('created_at', now()->subMonth()->month)
            ->whereYear('created_at', now()->subMonth()->year)
            ->where('order_status', '!=', 'cancelled')
            ->sum('total_amount');

        $revenueChangePercent = $lastMonthRevenue > 0
            ? round((($thisMonthRevenue - $lastMonthRevenue) / $lastMonthRevenue) * 100, 1)
            : ($thisMonthRevenue > 0 ? 100 : 0);

        // ── Orders by status ──
        $ordersByStatus = Order::selectRaw('order_status, count(*) as total')
            ->groupBy('order_status')
            ->pluck('total', 'order_status');

        // ── Orders by category (via order items -> product) ──
        $ordersByCategory = \App\Models\OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->selectRaw('products.category, count(*) as total')
            ->whereNotNull('products.category')
            ->groupBy('products.category')
            ->orderByDesc('total')
            ->get();

        // ── Best-selling products ──
        $bestSellers = \App\Models\OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->selectRaw('products.id, products.product_name, products.product_image, sum(order_items.quantity) as total_sold')
            ->groupBy('products.id', 'products.product_name', 'products.product_image')
            ->orderByDesc('total_sold')
            ->take(5)
            ->get();

        // ── Customer insights ──
        $totalCustomers = User::where('role', 'customer')->count();

        $newCustomersThisMonth = User::where('role', 'customer')
            ->whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->count();

        $verifiedCustomers = User::where('role', 'customer')->where('is_validated', true)->count();

        // ── Low stock alert ──
        $lowStockProducts = Product::where('stock_quantity', '<=', 5)
            ->orderBy('stock_quantity')
            ->take(5)
            ->get();

        return view('admin.reports', compact(
            'revenueByMonth',
            'totalRevenue',
            'thisMonthRevenue',
            'revenueChangePercent',
            'ordersByStatus',
            'ordersByCategory',
            'bestSellers',
            'totalCustomers',
            'newCustomersThisMonth',
            'verifiedCustomers',
            'lowStockProducts'
        ));
    }

    /* ════════════════════════════════
       CUSTOMERS LIST
    ════════════════════════════════ */
    public function customersIndex(Request $request)
    {
        $search = $request->get('search');
        $filter = $request->get('filter', 'all');

        $customers = User::where('role', 'customer')
            ->withCount('orders')
            ->when($search, fn($q) => $q->where(function($sub) use ($search) {
                $sub->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->when($filter === 'verified', fn($q) => $q->where('is_validated', true))
            ->when($filter === 'unverified', fn($q) => $q->where('is_validated', false))
            ->latest()
            ->paginate(15);

        // Total spent per customer (completed orders only)
        foreach ($customers as $customer) {
            $customer->total_spent = Order::where('user_id', $customer->id)
                ->where('order_status', 'completed')
                ->sum('total_amount');
        }

        $stats = [
            'total'      => User::where('role', 'customer')->count(),
            'verified'   => User::where('role', 'customer')->where('is_validated', true)->count(),
            'unverified' => User::where('role', 'customer')->where('is_validated', false)->count(),
        ];

        return view('admin.customers.index', compact('customers', 'search', 'filter', 'stats'));
    }

    public function customerShow($id)
    {
        $customer = User::where('role', 'customer')->findOrFail($id);

        $orders = Order::where('user_id', $id)->latest()->get();
        $totalSpent = $orders->where('order_status', 'completed')->sum('total_amount');

        return view('admin.customers.show', compact('customer', 'orders', 'totalSpent'));
    }


    /* ════════════════════════════════
       INSTALLMENT SCHEDULES
    ════════════════════════════════ */
    public function installmentsIndex(Request $request)
    {
        $status = $request->get('status', 'pending');

        $installments = \App\Models\InstallmentSchedule::with('order.user')
            ->when($status !== 'all', fn($q) => $q->where('status', $status))
            ->orderBy('due_date')
            ->paginate(15);

        // Auto-flag overdue ones that are still marked pending
        \App\Models\InstallmentSchedule::where('status', 'pending')
            ->where('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        $stats = [
            'pending'  => \App\Models\InstallmentSchedule::where('status', 'pending')->count(),
            'overdue'  => \App\Models\InstallmentSchedule::where('status', 'overdue')->count(),
            'paid'     => \App\Models\InstallmentSchedule::where('status', 'paid')->count(),
        ];

        return view('admin.installments.index', compact('installments', 'status', 'stats'));
    }

    public function installmentMarkPaid($id)
    {
        $installment = \App\Models\InstallmentSchedule::findOrFail($id);
        $installment->update([
            'status'  => 'paid',
            'paid_at' => now(),
        ]);

        Notification::create([
            'user_id' => $installment->user_id,
            'title'   => 'Installment Payment Received',
            'message' => "Installment #{$installment->installment_no} for order {$installment->order->order_number} has been marked as paid. Thank you!",
            'type'    => 'payment_confirmed',
            'url'     => route('orders.show', $installment->order_id),
            'is_read' => false,
        ]);

        return back()->with('success', 'Installment marked as paid.');
    }
}
