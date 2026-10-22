<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\ValidationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\CustomDesignController;
use App\Http\Controllers\CustomAgreementController;
use App\Http\Controllers\Admin\AgreementController as AdminAgreementController;
use App\Http\Controllers\Admin\NotificationController as AdminNotificationController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\ChatbotIntentController;
use App\Http\Controllers\Admin\DesignRequestController;
use App\Http\Controllers\Admin\CustomOrderController;
use App\Http\Controllers\Admin\BusinessSettingController;



// ============================================================
// PUBLIC ROUTES
// ============================================================

Route::get('/', [ProductController::class, 'home'])->name('home');
Route::get('/catalog', [ProductController::class, 'catalog'])->name('catalog');
Route::get('/product/{id}', [ProductController::class, 'show'])->name('product.show');
Route::get('/product/{id}/quick-view', [ProductController::class, 'quickView'])->name('product.quickview');
Route::get('/reviews', [FeedbackController::class, 'index'])->name('feedback.index');
Route::get('/terms', [\App\Http\Controllers\TermsController::class, 'show'])->name('terms');
// Other Services terms (Stage 21f): separate from the main terms; furniture order acceptance is not touched.
Route::get('/services/terms', [\App\Http\Controllers\TermsController::class, 'services'])->name('services.terms');
Route::get('/guide', [\App\Http\Controllers\GuideController::class, 'show'])->name('guide');
Route::post('/chatbot', [ChatbotController::class, 'respond'])->name('chatbot.respond');
Route::get('/csrf-token', [ChatbotController::class, 'csrfToken'])->name('csrf.refresh')->middleware('throttle:30,1');
// Luma's cancellation / refund form card (Stage 21i). Signed-in customers only; the controller answers a guest with a JSON 401.
Route::post('/chatbot/refund', [ChatbotController::class, 'refund'])->name('chatbot.refund')->middleware('throttle:20,1');
// ============================================================
// GUEST ONLY ROUTES
// ============================================================

Route::middleware('guest')->group(function () {

    Route::get('/login', [AuthController::class, 'showLogin'])
         ->name('login');
    Route::post('/login', [AuthController::class, 'login'])
         ->name('login.post');

    Route::get('/register', [AuthController::class, 'showRegister'])
         ->name('register');
    Route::post('/register', [AuthController::class, 'register'])
         ->name('register.post');

    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])
         ->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])
         ->name('password.email');

    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])
         ->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
         ->name('password.update');

    Route::get('/auth/google', [AuthController::class, 'redirectToGoogle'])
         ->name('auth.google');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])
         ->name('auth.google.callback');
});

// ============================================================
// AUTHENTICATED ROUTES
// ============================================================

Route::middleware('auth')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout'])
         ->name('logout');

    // Email verification routes
    Route::get('/email/verify', function () {
        return view('auth.verify-email');
    })->name('verification.notice');

    Route::get('/email/verify/{id}/{hash}', function (\Illuminate\Foundation\Auth\EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect()->route('validation.show')
            ->with('success', 'Email verified! Now please verify your identity.');
    })->middleware('signed')->name('verification.verify');

    Route::post('/email/resend', function (\Illuminate\Http\Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return back()->with('success', 'Verification email resent! Check your inbox.');
    })->middleware('throttle:6,1')->name('verification.send');

    Route::get('/profile', [AuthController::class, 'profile'])
         ->name('profile');
    Route::put('/profile', [AuthController::class, 'updateProfile'])
         ->name('profile.update');

    Route::get('/validate-account', [ValidationController::class, 'show'])
         ->name('validation.show');
    Route::post('/validate-account', [ValidationController::class, 'submit'])
         ->name('validation.submit');

    Route::get('/cart', [CartController::class, 'index'])
         ->name('cart.index');
    Route::post('/cart/add/{id}', [CartController::class, 'add'])
         ->name('cart.add');
         Route::post('/order-now/{id}', [CartController::class, 'orderNow'])->name('cart.orderNow');
    Route::patch('/cart/update/{id}', [CartController::class, 'update'])
         ->name('cart.update');
    Route::delete('/cart/remove/{id}', [CartController::class, 'remove'])
         ->name('cart.remove');
    Route::delete('/cart/clear', [CartController::class, 'clear'])
         ->name('cart.clear');

    Route::get('/notifications', [NotificationController::class, 'index'])
         ->name('notifications.index');
    Route::get('/notifications/{id}', [NotificationController::class, 'show'])
         ->name('notifications.show')->whereNumber('id');
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead'])
         ->name('notifications.read');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])
         ->name('notifications.readAll');

    // ── VALIDATED USERS ONLY ──
    Route::middleware('validated')->group(function () {

        Route::get('/checkout', [OrderController::class, 'checkout'])
             ->name('checkout');
        Route::post('/order', [OrderController::class, 'placeOrder'])
             ->name('order.place');
        Route::get('/orders', [OrderController::class, 'index'])
             ->name('orders.index');
        Route::get('/orders/{id}', [OrderController::class, 'show'])
             ->name('orders.show');
        Route::post('/orders/{id}/payment-method', [OrderController::class, 'updatePaymentMethod'])
             ->name('orders.paymentMethod.update');

        // ── STRIPE (Checkout Session flow) ──
        Route::get('/payment/stripe/{orderId}', [PaymentController::class, 'stripeForm'])
             ->name('payment.stripe');
        Route::post('/payment/stripe/{orderId}/checkout', [PaymentController::class, 'stripeCreateSession'])
             ->name('payment.stripe.checkout');
        Route::get('/payment/stripe/{orderId}/success', [PaymentController::class, 'stripeSuccess'])
             ->name('payment.stripe.success');

        // ── GCASH VIA PAYMONGO (real payment gateway — new default) ──
        Route::get('/payment/gcash/paymongo/{orderId}', [PaymentController::class, 'paymongoForm'])
             ->name('payment.gcash.paymongo');
        Route::post('/payment/gcash/paymongo/{orderId}/checkout', [PaymentController::class, 'paymongoCreateIntent'])
             ->name('payment.gcash.paymongo.checkout');
        Route::get('/payment/gcash/paymongo/{orderId}/success', [PaymentController::class, 'paymongoSuccess'])
             ->name('payment.gcash.paymongo.success');

        // ── PAYMENT CONFIRMED ──
        Route::get('/payment/confirmed/{orderId}', [PaymentController::class, 'confirmed'])
             ->name('payment.confirmed');

        Route::post('/reviews', [FeedbackController::class, 'store'])
             ->name('feedback.store');
    });
});

// ============================================================
// AI Custom Design Routes (requires login + validation)
// ============================================================

Route::middleware(['auth', 'validated'])->group(function () {
    Route::get('/design',              [CustomDesignController::class, 'index'])->name('design.index');
    Route::post('/design',             [CustomDesignController::class, 'submit'])->name('design.submit');
    Route::get('/design/{id}',         [CustomDesignController::class, 'show'])->name('design.show');
    Route::post('/design/{id}/select', [CustomDesignController::class, 'selectDesign'])->name('design.select');
    Route::post('/design/{id}/regenerate', [CustomDesignController::class, 'regenerate'])->name('design.regenerate');
    Route::post('/design/{id}/send-without-image', [CustomDesignController::class, 'sendWithoutImage'])->name('design.sendWithoutImage');
    Route::get('/design/{id}/send',    [CustomDesignController::class, 'sendForm'])->name('design.sendForm');
    Route::post('/design/{id}/send',   [CustomDesignController::class, 'sendToTeam'])->name('design.sendToTeam');
    Route::get('/design/{id}/edit',    [CustomDesignController::class, 'editForm'])->name('design.edit')->whereNumber('id');
    Route::post('/design/{id}/edit',   [CustomDesignController::class, 'updateRequest'])->name('design.update')->whereNumber('id');
    Route::post('/design/{id}/favorite', [CustomDesignController::class, 'toggleFavorite'])->name('design.favorite');
Route::get('/design-gallery', [CustomDesignController::class, 'gallery'])->name('design.gallery');

    // Other Services inquiry tickets (labor only with own wood, repairs, anything priced by phone).
    // An account is required, same group as the Customize page, so we can always reach the customer.
    Route::get('/services/request', [\App\Http\Controllers\ServiceInquiryController::class, 'create'])->name('services.request');
    Route::post('/services/request', [\App\Http\Controllers\ServiceInquiryController::class, 'store'])->name('services.request.submit');
    Route::get('/services/request/sent', [\App\Http\Controllers\ServiceInquiryController::class, 'sent'])->name('services.request.sent');
    Route::get('/services/invoices/{id}', [\App\Http\Controllers\ServiceInvoiceController::class, 'show'])->name('services.invoice')->whereNumber('id');
    // Everything below acts on an invoice: the `invoice.actionable` middleware sends a cancelled one to its explanation page.
    $invCtl = \App\Http\Controllers\ServiceInvoiceController::class;
    Route::get('/services/invoices/{id}/print', [$invCtl, 'print'])->name('services.invoice.print')->whereNumber('id')->middleware('invoice.actionable');
    Route::post('/services/invoices/{id}/pay/{method}', [$invCtl, 'startPayment'])->name('services.invoice.pay')->whereNumber('id')
        ->where('method', 'stripe|gcash')->middleware('invoice.actionable');
    Route::get('/services/invoices/{id}/payment/{outcome}', [$invCtl, 'paymentReturn'])->name('services.invoice.payment.return')->whereNumber('id')
        ->where('outcome', 'success|cancelled|failed')->middleware('invoice.actionable');

    // Cancellation and refund requests for Other Services (Stage 21f). Owner only: anyone else gets a 404.
    $rfCtl = \App\Http\Controllers\ServiceRefundRequestController::class;
    Route::get('/services/requests/{id}/cancellation',          [$rfCtl, 'create'])->name('services.refund.create')->whereNumber('id');
    Route::post('/services/requests/{id}/cancellation/review',  [$rfCtl, 'review'])->name('services.refund.review')->whereNumber('id')->middleware('throttle:20,1');
    Route::post('/services/requests/{id}/cancellation',         [$rfCtl, 'store'])->name('services.refund.store')->whereNumber('id')->middleware('throttle:6,1');
    Route::get('/services/refunds/{reference}',                 [$rfCtl, 'show'])->name('services.refund.show')->where('reference', 'REF-\d{4}-\d{4,}');
    Route::get('/services/refunds/{reference}/sent',            [$rfCtl, 'sent'])->name('services.refund.sent')->where('reference', 'REF-\d{4}-\d{4,}');
    Route::post('/services/refunds/{reference}/reply',          [$rfCtl, 'reply'])->name('services.refund.reply')->where('reference', 'REF-\d{4}-\d{4,}')->middleware('throttle:12,1');

    // Review and Accept, and the immutable agreement record (Stage 6).
    // There is deliberately no update or delete route for an agreement.
    Route::get('/design/{id}/quotes/{quote}',          [CustomAgreementController::class, 'review'])->name('design.quote.review')->whereNumber(['id', 'quote']);
    Route::post('/design/{id}/quotes/{quote}/accept',  [CustomAgreementController::class, 'accept'])->name('design.quote.accept')->whereNumber(['id', 'quote']);
    Route::post('/design/{id}/quotes/{quote}/decline', [CustomAgreementController::class, 'decline'])->name('design.quote.decline')->whereNumber(['id', 'quote']);
    // Agreement checkout (Stage 9): the form posts only payment_method.
    Route::get('/custom-orders/{agreement}/checkout',  [\App\Http\Controllers\CustomCheckoutController::class, 'show'])->name('custom.checkout')->whereNumber('agreement');
    Route::post('/custom-orders/{agreement}/checkout', [\App\Http\Controllers\CustomCheckoutController::class, 'place'])->name('custom.checkout.place')->whereNumber('agreement');
    Route::get('/agreements/{agreement}',        [CustomAgreementController::class, 'show'])->name('design.agreement')->whereNumber('agreement');
    Route::get('/agreements/{agreement}/print',  [CustomAgreementController::class, 'printView'])->name('design.agreement.print')->whereNumber('agreement');
    Route::get('/agreements/{agreement}/image',  [CustomAgreementController::class, 'image'])->name('design.agreement.image')->whereNumber('agreement');
});

// ============================================================
// PAYMENT WEBHOOKS (no auth — the providers' servers call these
// directly, not a logged-in browser)
// ============================================================

Route::post('/webhook/stripe', [PaymentController::class, 'handleWebhook'])
     ->name('webhook.stripe');

Route::post('/webhook/paymongo', [PaymentController::class, 'paymongoWebhook'])
     ->name('webhook.paymongo');

// ============================================================
// ADMIN ROUTES
// ============================================================

// Admin Auth (no admin middleware — this is how they log in)
Route::get('/admin/login',  [AdminController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->name('admin.login.post');

// Admin Protected Routes — 'admin' confirms identity, 'admin.timeout'
// enforces the shorter idle-logout (see CheckAdminTimeout)
Route::middleware(['admin', 'admin.timeout'])->prefix('admin')->name('admin.')->group(function () {
    Route::post('/logout', [AdminController::class, 'logout'])->name('logout');
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');


    // ────────────────
    // Identity Validations (KYC)
    // ────────────────
    Route::get('/validations',                [AdminController::class, 'validationsIndex'])->name('validations.index'); // List all validation requests
    Route::get('/validations/{id}',            [AdminController::class, 'validationShow'])->name('validations.show'); // Show a specific validation
    Route::post('/validations/{id}/approve',    [AdminController::class, 'validationApprove'])->name('validations.approve'); // Approve a validation
    Route::post('/validations/{id}/reject',     [AdminController::class, 'validationReject'])->name('validations.reject'); // Reject a validation
    Route::get('/validations/{id}/document/{type}', [AdminController::class, 'showDocument'])
         ->name('validations.document') // Show uploaded ID or selfie document
         ->where('type', 'id|selfie');

    // ────────────────
    // Order Management
    // ────────────────
    Route::get('/orders',              [AdminController::class, 'ordersIndex'])->name('orders.index'); // List all orders
    Route::get('/orders/{id}',          [AdminController::class, 'orderShow'])->name('orders.show'); // Show a specific order
    Route::post('/orders/{id}/status',  [AdminController::class, 'orderUpdateStatus'])->name('orders.updateStatus'); // Update order status
    Route::post('/orders/{id}/payments', [\App\Http\Controllers\Admin\PaymentRecordController::class, 'store'])->name('orders.payments.store')->whereNumber('id'); // Record Payment (ledger)
    Route::post('/orders/{id}/review',   [\App\Http\Controllers\Admin\PaymentRecordController::class, 'markReviewed'])->name('orders.review')->whereNumber('id'); // Clear needs_review

    // ────────────────
    // Custom Orders (requests sent to the team through the customer form)
    // ────────────────
    Route::get('/custom-orders',                       [CustomOrderController::class, 'index'])->name('custom-orders.index');
    Route::get('/custom-orders/{id}',                  [CustomOrderController::class, 'show'])->name('custom-orders.show')->whereNumber('id');
    Route::post('/custom-orders/{id}/calls',           [CustomOrderController::class, 'logCall'])->name('custom-orders.calls')->whereNumber('id');
    Route::post('/custom-orders/{id}/call-update',     [CustomOrderController::class, 'callUpdate'])->name('custom-orders.call-update')->whereNumber('id');
    Route::post('/custom-orders/{id}/furniture-type',  [CustomOrderController::class, 'setFurnitureType'])->name('custom-orders.furniture-type')->whereNumber('id');
    Route::post('/custom-orders/{id}/unreachable',     [CustomOrderController::class, 'markUnreachable'])->name('custom-orders.unreachable')->whereNumber('id');
    Route::post('/custom-orders/{id}/decline',         [CustomOrderController::class, 'decline'])->name('custom-orders.decline')->whereNumber('id');

    // Quotes (Stage 5): drafts, versions, send
    Route::get('/custom-orders/{id}/quote-preview',              [CustomOrderController::class, 'quotePreview'])->name('custom-orders.quotes.preview')->whereNumber('id');
    Route::get('/custom-orders/{id}/quotes/create',              [CustomOrderController::class, 'quoteForm'])->name('custom-orders.quotes.create')->whereNumber('id');
    Route::post('/custom-orders/{id}/quotes',                    [CustomOrderController::class, 'quoteStore'])->name('custom-orders.quotes.store')->whereNumber('id');
    Route::get('/custom-orders/{id}/quotes/{quote}/edit',        [CustomOrderController::class, 'quoteEdit'])->name('custom-orders.quotes.edit')->whereNumber(['id', 'quote']);
    Route::post('/custom-orders/{id}/quotes/{quote}',            [CustomOrderController::class, 'quoteUpdate'])->name('custom-orders.quotes.update')->whereNumber(['id', 'quote']);
    Route::post('/custom-orders/{id}/quotes/{quote}/delete',     [CustomOrderController::class, 'quoteDelete'])->name('custom-orders.quotes.delete')->whereNumber(['id', 'quote']);
    Route::get('/custom-orders/{id}/quotes/{quote}/confirm',     [CustomOrderController::class, 'quoteConfirm'])->name('custom-orders.quotes.confirm')->whereNumber(['id', 'quote']);
    Route::post('/custom-orders/{id}/quotes/{quote}/send',       [CustomOrderController::class, 'quoteSend'])->name('custom-orders.quotes.send')->whereNumber(['id', 'quote']);

    // ────────────────
    // Other Services (inquiry tickets; they never become orders)
    // ────────────────
    Route::get('/service-inquiries',                     [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'index'])->name('service-inquiries.index');
    Route::get('/service-inquiries/create',              [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'create'])->name('service-inquiries.create');
    Route::post('/service-inquiries',                    [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'store'])->name('service-inquiries.store');
    Route::get('/service-inquiries/customer-search',     [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'customerSearch'])->name('service-inquiries.customer-search');
    Route::get('/service-inquiries/{id}',                [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'show'])->name('service-inquiries.show')->whereNumber('id');
    Route::get('/service-inquiries/{id}/photos/{n}',     [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'photo'])->name('service-inquiries.photo')->whereNumber(['id', 'n']);
    Route::post('/service-inquiries/{id}/call-update',   [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'callUpdate'])->name('service-inquiries.call-update')->whereNumber('id');
    Route::post('/service-inquiries/{id}/notes',         [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'addNote'])->name('service-inquiries.notes')->whereNumber('id');
    Route::post('/service-inquiries/{id}/close',         [\App\Http\Controllers\Admin\ServiceInquiryController::class, 'close'])->name('service-inquiries.close')->whereNumber('id');

    // Other Services invoices (draft -> issued -> paid / void; issued ones are locked)
    $inv = \App\Http\Controllers\Admin\ServiceInvoiceController::class;
    Route::get('/service-inquiries/{ticketId}/invoices/create', [$inv, 'create'])->name('service-invoices.create')->whereNumber('ticketId');
    Route::post('/service-inquiries/{ticketId}/invoices',       [$inv, 'store'])->name('service-invoices.store')->whereNumber('ticketId');
    Route::get('/service-invoices/{id}',                        [$inv, 'show'])->name('service-invoices.show')->whereNumber('id');
    Route::get('/service-invoices/{id}/edit',                   [$inv, 'edit'])->name('service-invoices.edit')->whereNumber('id');
    Route::post('/service-invoices/{id}',                       [$inv, 'update'])->name('service-invoices.update')->whereNumber('id');
    Route::post('/service-invoices/{id}/delete',                [$inv, 'destroy'])->name('service-invoices.delete')->whereNumber('id');
    Route::post('/service-invoices/{id}/issue',                 [$inv, 'issue'])->name('service-invoices.issue')->whereNumber('id');
    Route::post('/service-invoices/{id}/paid',                  [$inv, 'pay'])->name('service-invoices.paid')->whereNumber('id');
    Route::post('/service-invoices/{id}/void',                  [$inv, 'void'])->name('service-invoices.void')->whereNumber('id');
    Route::post('/service-invoices/{id}/service-done',          [$inv, 'serviceDone'])->name('service-invoices.service-done')->whereNumber('id');
    Route::post('/service-invoices/{id}/cash',                  [$inv, 'recordCash'])->name('service-invoices.cash')->whereNumber('id');
    // Other Services cancellations and refunds (Stage 21f). The old per-invoice refund note form was replaced by this.
    $rf = \App\Http\Controllers\Admin\ServiceRefundRequestController::class;
    Route::get('/refund-requests',                              [$rf, 'index'])->name('refunds.index');
    Route::get('/refund-requests/{id}',                         [$rf, 'show'])->name('refunds.show')->whereNumber('id');
    Route::post('/refund-requests/{id}/approve',                [$rf, 'approve'])->name('refunds.approve')->whereNumber('id');
    Route::post('/refund-requests/{id}/approve-exception',      [$rf, 'approveException'])->name('refunds.approve-exception')->whereNumber('id');
    Route::post('/refund-requests/{id}/decline',                [$rf, 'decline'])->name('refunds.decline')->whereNumber('id');
    Route::post('/refund-requests/{id}/ask-info',               [$rf, 'askInfo'])->name('refunds.ask-info')->whereNumber('id');
    Route::post('/refund-requests/{id}/mark-refunded',          [$rf, 'markRefunded'])->name('refunds.mark-refunded')->whereNumber('id');
    Route::post('/refund-requests/{id}/refund-failed',          [$rf, 'refundFailed'])->name('refunds.refund-failed')->whereNumber('id');
    Route::post('/service-inquiries/{id}/refund-request',       [$rf, 'openForCustomer'])->name('service-inquiries.refund-request')->whereNumber('id');
    Route::post('/service-inquiries/{id}/work-started',         [$rf, 'workStarted'])->name('service-inquiries.work-started')->whereNumber('id');
    Route::post('/service-inquiries/{id}/work-started/undo',    [$rf, 'undoWorkStarted'])->name('service-inquiries.work-started-undo')->whereNumber('id');
    Route::get('/service-invoices/{id}/print',                  [$inv, 'print'])->name('service-invoices.print')->whereNumber('id');

    // Agreement records (read only) and the admin's own notifications (Stage 6)
    Route::get('/agreements/{id}',       [AdminAgreementController::class, 'show'])->name('agreements.show')->whereNumber('id');
    Route::get('/agreements/{id}/print', [AdminAgreementController::class, 'printView'])->name('agreements.print')->whereNumber('id');
    Route::get('/agreements/{id}/image', [AdminAgreementController::class, 'image'])->name('agreements.image')->whereNumber('id');

    Route::get('/notifications',            [AdminNotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all',  [AdminNotificationController::class, 'markAllRead'])->name('notifications.readAll');
    Route::post('/notifications/{id}/read', [AdminNotificationController::class, 'markRead'])->name('notifications.read')->whereNumber('id');

    // ────────────────
    // AI Custom Design Requests
    // ────────────────
    Route::get('/design-requests',                  [DesignRequestController::class, 'index'])->name('design-requests.index'); // List design requests awaiting follow-up
    Route::get('/design-requests/{id}',              [DesignRequestController::class, 'show'])->name('design-requests.show'); // Admin-facing detail view
    Route::post('/design-requests/{id}/contacted',   [DesignRequestController::class, 'markContacted'])->name('design-requests.contacted'); // Mark as contacted
    Route::post('/design-requests/{id}/reject',      [DesignRequestController::class, 'reject'])->name('design-requests.reject'); // Decline a request

    // ────────────────
    // Customer Reviews (Feedback Moderation)
    // ────────────────
    Route::get('/reviews',              [AdminController::class, 'reviewsIndex'])->name('reviews.index'); // List all reviews
    Route::post('/reviews/{id}/approve', [AdminController::class, 'reviewApprove'])->name('reviews.approve'); // Approve a review
    Route::post('/reviews/{id}/reject',  [AdminController::class, 'reviewReject'])->name('reviews.reject'); // Reject a review

    // ────────────────
    // Product Management
    // ────────────────
    Route::get('/products',                [AdminProductController::class, 'index'])->name('products.index'); // List products
    Route::get('/products/create',         [AdminProductController::class, 'create'])->name('products.create'); // Show product creation form
    Route::post('/products',               [AdminProductController::class, 'store'])->name('products.store'); // Store a new product
    Route::get('/products/{id}/edit',      [AdminProductController::class, 'edit'])->name('products.edit'); // Show product edit form
    Route::put('/products/{id}',           [AdminProductController::class, 'update'])->name('products.update'); // Update a product
    Route::delete('/products/{id}',        [AdminProductController::class, 'destroy'])->name('products.destroy'); // Delete a product
    Route::delete('/products/{id}/images/{image}', [AdminProductController::class, 'destroyImage'])->name('products.images.destroy'); // Remove one extra photo
    Route::post('/products/{id}/stock',    [AdminProductController::class, 'updateStock'])->name('products.stock'); // Update product stock

    // ────────────────
    // Employee (Staff) Management
    // ────────────────
    Route::get('/employees',              [EmployeeController::class, 'index'])->name('employees.index'); // List all employees
    Route::get('/employees/create',       [EmployeeController::class, 'create'])->name('employees.create'); // Show employee creation form
    Route::post('/employees',             [EmployeeController::class, 'store'])->name('employees.store'); // Store a new employee
    Route::get('/employees/{id}/edit',    [EmployeeController::class, 'edit'])->name('employees.edit'); // Show employee edit form
    Route::put('/employees/{id}',         [EmployeeController::class, 'update'])->name('employees.update'); // Update an employee
    Route::post('/employees/{id}/status', [EmployeeController::class, 'updateStatus'])->name('employees.status'); // Change employee status (active/inactive)
    Route::delete('/employees/{id}',      [EmployeeController::class, 'destroy'])->name('employees.destroy'); // Delete an employee

    // ────────────────
    // Reporting
    // ────────────────
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports'); // Admin Reports (Analytics, etc.)

    // ────────────────
    // Customer Management (View only)
    // ────────────────
    Route::get('/customers',      [AdminController::class, 'customersIndex'])->name('customers.index'); // List all customers
    Route::get('/customers/{id}', [AdminController::class, 'customerShow'])->name('customers.show'); // Show a customer profile


    // Installments
Route::get('/installments', [AdminController::class, 'installmentsIndex'])->name('installments.index');
Route::post('/installments/{id}/paid', [AdminController::class, 'installmentMarkPaid'])->name('installments.markPaid');

// Customers
Route::get('/customers', [AdminController::class, 'customersIndex'])->name('customers.index');
Route::get('/customers/{id}', [AdminController::class, 'customerShow'])->name('customers.show');

// Inventory
Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
Route::get('/inventory/create', [InventoryController::class, 'create'])->name('inventory.create');
Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
Route::get('/inventory/{id}/edit', [InventoryController::class, 'edit'])->name('inventory.edit');
Route::put('/inventory/{id}', [InventoryController::class, 'update'])->name('inventory.update');
Route::post('/inventory/{id}/adjust', [InventoryController::class, 'adjustQuantity'])->name('inventory.adjust');
Route::delete('/inventory/{id}', [InventoryController::class, 'destroy'])->name('inventory.destroy');

// Chatbot Training
Route::get('/chatbot', [ChatbotIntentController::class, 'index'])->name('chatbot.index');
Route::get('/chatbot/create', [ChatbotIntentController::class, 'create'])->name('chatbot.create');
Route::post('/chatbot', [ChatbotIntentController::class, 'store'])->name('chatbot.store');
Route::get('/chatbot/{id}/edit', [ChatbotIntentController::class, 'edit'])->name('chatbot.edit');
Route::put('/chatbot/{id}', [ChatbotIntentController::class, 'update'])->name('chatbot.update');
Route::post('/chatbot/{id}/toggle', [ChatbotIntentController::class, 'toggleActive'])->name('chatbot.toggle');
Route::delete('/chatbot/{id}', [ChatbotIntentController::class, 'destroy'])->name('chatbot.destroy');

// Contact Settings (phone / email / Facebook shown to customers)
Route::get('/settings/contact', [BusinessSettingController::class, 'edit'])->name('settings.contact.edit');
Route::put('/settings/contact', [BusinessSettingController::class, 'update'])->name('settings.contact.update');
});