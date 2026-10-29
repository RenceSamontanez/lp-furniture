<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\SafeIntendedUrl;
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
    /** "Keep me signed in on this device": the remember cookie lasts 30 days. */
    private const REMEMBER_MINUTES = 60 * 24 * 30;

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

        // Keyed on the account being attempted, not the caller's IP —
        // same reasoning as the customer login: an IP-based key lets one
        // shared network's failed attempts lock out everyone behind it,
        // and does nothing to stop an account being attacked from many
        // different IPs.
        $key = 'admin_login_' . strtolower(trim($request->email));

        if (cache()->has($key . '_lockout')) {
            $seconds = cache()->get($key . '_lockout_time', 60);
            return back()->withErrors([
                'email' => "Too many failed login attempts for this account. Please wait {$seconds} seconds before trying again.",
            ])->withInput($request->only('email'));
        }

        // role is matched as part of the same credential check (not a
        // separate branch after login) so a correct email/password on a
        // non-admin account fails exactly like a wrong password — no
        // separate error message that would tell an attacker the
        // credentials were valid, and the admin guard's session is never
        // established for a non-admin account in the first place.
        $remember = $request->boolean('remember');
        if ($remember) {
            Auth::guard('admin')->setRememberDuration(self::REMEMBER_MINUTES);
        }

        if (Auth::guard('admin')->attempt([
            'email'    => $request->email,
            'password' => $request->password,
            'role'     => 'admin',
        ], $remember)) {
            cache()->forget($key);
            cache()->forget($key . '_lockout');

            // One identity per browser: an admin session replaces any
            // customer session already active in this browser.
            Auth::guard('web')->logout();

            $request->session()->regenerate();

            return redirect(SafeIntendedUrl::resolve($request, route('admin.dashboard'), adminArea: true))
                ->with('success', 'Welcome back, ' . Auth::guard('admin')->user()->first_name . '.');
        }

        $attempts = cache()->get($key, 0) + 1;
        cache()->put($key, $attempts, now()->addMinutes(10));

        if ($attempts >= 5) {
            cache()->put($key . '_lockout', true, now()->addMinutes(1));
            cache()->put($key . '_lockout_time', 60, now()->addMinutes(1));
            return back()->withErrors([
                'email' => 'Too many failed attempts for this account. Please wait 60 seconds before trying again.',
            ])->withInput($request->only('email'));
        }

        $remaining = 5 - $attempts;
        return back()
            ->withInput($request->only('email'))
            ->withErrors([
                'email' => "Incorrect email or password. {$remaining} attempt(s) remaining before lockout.",
            ]);
    }

    public function logout(Request $request)
    {
        \App\Support\Luma\ChatState::forget();
        // logout() also replaces the remember token and expires the cookie.
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
       ORDERS
    ════════════════════════════════ */
    public function ordersIndex(Request $request)
    {
        $status = $request->get('status', 'all');
        $type   = in_array($request->get('type'), ['customized', 'catalog'], true) ? $request->get('type') : 'all';

        $orders = Order::with('user', 'items.product', 'designRequest', 'payment')
            ->when($status !== 'all', fn($q) => $q->where('order_status', $status))
            ->when($type === 'customized', fn($q) => $q->whereNotNull('design_agreement_id'))
            ->when($type === 'catalog', fn($q) => $q->whereNull('design_agreement_id'))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('admin.orders.index', compact('orders', 'status', 'type'));
    }

    public function orderShow($id)
    {
        $order = Order::with('user', 'items.product', 'statusUpdatedBy', 'payment', 'agreement', 'designRequest')->findOrFail($id);

        $ledger = \App\Models\PaymentRecord::where('order_id', $order->id)
            ->with(['recorder', 'installment'])->orderBy('paid_on')->orderBy('id')->get();
        $paid = round((float) $ledger->sum('amount'), 2);
        $remaining = max(0.0, round((float) $order->total_amount - $paid, 2));

        // One-time token for the Record Payment form (see PaymentRecordController).
        $token = bin2hex(random_bytes(16));
        $tokens = array_slice((array) session(\App\Http\Controllers\Admin\PaymentRecordController::TOKEN_SESSION_KEY, []), -9);
        $tokens[] = $token;
        session([\App\Http\Controllers\Admin\PaymentRecordController::TOKEN_SESSION_KEY => $tokens]);

        if (\App\Support\OrderTracking::handles($order)) {
            $order->load('events.admin');

            return view('admin.orders.track', [
                'order'        => $order,
                'ledger'       => $ledger,
                'pay'          => \App\Support\OrderTracking::payment($order),
                'formToken'    => $token,
                'unpaidRows'   => $order->installments()->where('status', '!=', 'paid')->orderBy('installment_no')->get(),
                'reviewedBy'   => $order->reviewed_by ? \App\Models\User::find($order->reviewed_by) : null,
                'methodLabels' => \App\Support\RecordPayment::METHOD_LABELS,
            ]);
        }

        return view('admin.orders.show', [
            'hideGlobalErrorToast' => true, // payment and status errors are shown inline
            'order'        => $order,
            'ledger'       => $ledger,
            'paid'         => $paid,
            'remaining'    => $remaining,
            'formToken'    => $token,
            'unpaidRows'   => $order->installments()->where('status', '!=', 'paid')->orderBy('installment_no')->get(),
            'reviewedBy'   => $order->reviewed_by ? \App\Models\User::find($order->reviewed_by) : null,
            'unrecorded'   => $order->payment && $order->payment->payment_method === 'onsite' && $ledger->isEmpty(),
            'methodLabels' => \App\Support\RecordPayment::METHOD_LABELS,
        ]);
    }

    public function orderUpdateStatus(Request $request, $id)
    {
        $request->validate([
            'order_status' => 'required|in:pending,confirmed,in_production,ready,out_for_delivery,completed,cancelled',
        ]);

        $order = Order::findOrFail($id);

        // Catalog orders no longer have a free status picker: they move one step at a time through the
        // Update progress card (Admin\OrderTrackingController). Only customized orders use this route.
        if ($order->design_agreement_id === null) {
            return back()->withErrors(['status' => 'Use the Update progress card on the order page to change this order.']);
        }

        // Customized orders: forward-only map, cancel rules, ledger-aware.
        $request->validate([
            'cancel_reason'   => 'nullable|string|max:1000',
            'override_reason' => 'nullable|string|max:1000',
        ]);

        try {
            app(\App\Support\CustomOrderStatus::class)->change(
                $order->id,
                Auth::guard('admin')->user(),
                $request->order_status,
                $request->order_status === 'completed'
                    ? $request->input('override_reason')
                    : $request->input('cancel_reason')
            );
        } catch (\App\Support\OrderStatusException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
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

    // GCash manual payment verification (paymentsIndex/paymentApprove/
    // paymentReject/showReceipt) was removed along with the manual
    // receipt-upload flow it reviewed — PayMongo is the only GCash
    // path now, so this queue could never have anything routed to it.

    /* ════════════════════════════════
       REPORTS & ANALYTICS
    ════════════════════════════════ */
    public function reports(Request $request)
    {
        return view('admin.reports', [
            'r' => \App\Support\AdminReports::build((string) $request->query('period', 'm')),
            'tab' => in_array($request->query('tab'), ['sales', 'prod', 'svc', 'luma', 'cust'], true) ? $request->query('tab') : 'sales',
        ]);
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

        $nameChanges = $customer->nameChanges()->get();

        return view('admin.customers.show', compact('customer', 'orders', 'totalSpent', 'nameChanges'));
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

        // Same ledger path as Record Payment: amount, method (cash by
        // default) and the admin are recorded, and the parent payment
        // status follows. The customer is told by that service.
        try {
            app(\App\Support\RecordPayment::class)->recordInstallment($installment, Auth::guard('admin')->user());
        } catch (\App\Support\PaymentRecordException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        return back()->with('success', 'Installment marked as paid and recorded in the ledger.');
    }
}
