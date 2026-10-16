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
Route::post('/chatbot', [ChatbotController::class, 'respond'])->name('chatbot.respond');

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