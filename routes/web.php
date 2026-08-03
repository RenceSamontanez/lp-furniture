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
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\InventoryController;
use App\Http\Controllers\Admin\ChatbotIntentController;



// ============================================================
// PUBLIC ROUTES
// ============================================================

Route::get('/', [ProductController::class, 'home'])->name('home');
Route::get('/catalog', [ProductController::class, 'catalog'])->name('catalog');
Route::get('/product/{id}', [ProductController::class, 'show'])->name('product.show');
Route::get('/product/{id}/quick-view', [ProductController::class, 'quickView'])->name('product.quickview');
Route::get('/reviews', [FeedbackController::class, 'index'])->name('feedback.index');
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

        // ── STRIPE (Checkout Session flow) ──
        Route::get('/payment/stripe/{orderId}', [PaymentController::class, 'stripeForm'])
             ->name('payment.stripe');
        Route::post('/payment/stripe/{orderId}/checkout', [PaymentController::class, 'stripeCreateSession'])
             ->name('payment.stripe.checkout');
        Route::get('/payment/stripe/{orderId}/success', [PaymentController::class, 'stripeSuccess'])
             ->name('payment.stripe.success');

        // ── GCASH ──
        Route::get('/payment/gcash/{orderId}', [PaymentController::class, 'gcashForm'])
             ->name('payment.gcash');
        Route::post('/payment/gcash/upload', [PaymentController::class, 'uploadReceipt'])
             ->name('payment.gcash.upload');

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
    Route::post('/design/{id}/favorite', [CustomDesignController::class, 'toggleFavorite'])->name('design.favorite');
Route::get('/design-gallery', [CustomDesignController::class, 'gallery'])->name('design.gallery');
});

// ============================================================
// STRIPE WEBHOOK (no auth — Stripe calls this directly)
// ============================================================

Route::post('/webhook/stripe', [PaymentController::class, 'handleWebhook'])
     ->name('webhook.stripe');

// ============================================================
// ADMIN ROUTES
// ============================================================

// Admin Auth (no admin middleware — this is how they log in)
Route::get('/admin/login',  [AdminController::class, 'showLogin'])->name('admin.login');
Route::post('/admin/login', [AdminController::class, 'login'])->name('admin.login.post');

// Admin Protected Routes — uses 'admin' middleware only (which checks the admin guard internally)
Route::middleware(['admin'])->prefix('admin')->name('admin.')->group(function () {
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

    // ────────────────
    // Customer Reviews (Feedback Moderation)
    // ────────────────
    Route::get('/reviews',              [AdminController::class, 'reviewsIndex'])->name('reviews.index'); // List all reviews
    Route::post('/reviews/{id}/approve', [AdminController::class, 'reviewApprove'])->name('reviews.approve'); // Approve a review
    Route::post('/reviews/{id}/reject',  [AdminController::class, 'reviewReject'])->name('reviews.reject'); // Reject a review

    // ────────────────
    // Payments Management
    // ────────────────
    Route::get('/payments',                  [AdminController::class, 'paymentsIndex'])->name('payments.index'); // List all payments
    Route::post('/payments/{id}/approve',     [AdminController::class, 'paymentApprove'])->name('payments.approve'); // Approve a payment
    Route::post('/payments/{id}/reject',      [AdminController::class, 'paymentReject'])->name('payments.reject'); // Reject a payment
    Route::get('/payments/{id}/receipt',      [AdminController::class, 'showReceipt'])->name('payments.receipt'); // Show a payment receipt

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
});