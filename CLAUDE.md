# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

LP Furniture is a Laravel 12 (PHP 8.2+) e-commerce storefront for a furniture business, with an admin back office, Stripe + GCash payments, KYC-style account validation, and an AI-assisted custom furniture design feature. It's a traditional server-rendered Blade app (no SPA framework, no separate API) running under XAMPP but served via `php artisan serve` in development (`APP_URL=http://127.0.0.1:8000`).

## Commands

```bash
# Install PHP deps, copy .env, generate key, migrate, install JS deps, build assets
composer run setup

# Local dev: runs php artisan serve + queue:listen + pail (logs) + vite, concurrently
composer run dev

# Run the full test suite (clears config cache first)
composer test
# equivalent to:
php artisan config:clear && php artisan test

# Run a single test file / filter by name
php artisan test tests/Feature/ExampleTest.php
php artisan test --filter=test_method_name

# Frontend only
npm run dev      # vite dev server
npm run build    # production build (Tailwind v4 + Vite)

# Code style (Laravel Pint)
vendor/bin/pint

# DB
php artisan migrate
php artisan migrate:fresh --seed   # only DatabaseSeeder.php exists, no per-model seeders

# Release abandoned/unpaid Stripe or GCash orders and restock (not currently scheduled anywhere — run manually or wire into a scheduler/cron)
php artisan orders:release-abandoned --minutes=60
```

Note: tests run against the MySQL **scratch database `furniture_db_test`** (set in `phpunit.xml`), never `furniture_db`. `tests/TestCase.php` aborts any run whose database name doesn't end in `_test`, before anything touches the DB. Never run `migrate:fresh`/`db:wipe`/`migrate:rollback` against `furniture_db` — to run migration commands on the scratch DB use `DB_DATABASE=furniture_db_test php artisan migrate`. Create the scratch DB once with `CREATE DATABASE furniture_db_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`; the feature tests rebuild it from the migrations (`RefreshDatabase`) and roll back each test, so it needs no manual schema. The default `ExampleTest.php` stubs still exist (the Feature one fails: it needs seeded products).

## Architecture

### Routing & access control (`routes/web.php`)

There is no `routes/api.php` — everything is session/Blade-based. Route groups, from least to most restricted:

- **Public**: home, catalog, product show/quick-view, reviews (read), chatbot endpoint.
- **`guest`**: login/register/password reset, Google OAuth (`AuthController` + Socialite).
- **`auth`**: logout, email verification, profile, KYC validation page, cart (incl. `order-now/{id}` buy-now shortcut), notifications.
  - **`auth` + `validated`** (nested): checkout, place order, orders list/show, Stripe/GCash payment flows, review submission. `validated` is a custom gate — see Middleware below.
  - **`auth` + `validated`**: AI custom design flow (submit/select/regenerate/favorite/gallery).
- **Stripe webhook** (`/webhook/stripe`, POST, no auth — called by Stripe directly).
- **Admin** (`/admin/*`, `admin` middleware + `admin.` route name prefix): dashboard, KYC validations, orders, reviews, payments, products, employees, reports, customers, installments, inventory, chatbot-intent training.

### Auth: two guards, one users table

`config/auth.php` defines a `web` guard and a separate `admin` guard, but **both providers resolve to `App\Models\User`** — there is no distinct Employee/Admin model. `App\Http\Middleware\CheckAdmin` (registered as `admin`) checks `Auth::guard('admin')->check()` **and** `role === 'admin'` on that same `users` table. Keep this in mind when adding admin-only features: it's a role check, not a separate identity.

Auth itself is hand-rolled in `AuthController` (no Breeze/Jetstream/Fortify) — login, register, password reset, profile, plus Google OAuth via `laravel/socialite`.

### The `validated` gate (KYC)

`App\Http\Middleware\CheckValidated` (registered as `validated`) blocks checkout/payment/reviews for accounts where `is_validated === false`, redirecting to the validation page (or a JSON 403 for AJAX). Validation itself (`ValidationController`, `UserValidation` model, admin approval in `Admin\AdminController`) is a document-review flow, not third-party KYC.

### Orders, checkout, and stock (`OrderController`, `CartController`)

- Checkout supports two entry points that converge on the same logic: cart checkout and "buy now" (`CartController::orderNow` stashes a single item in session; `OrderController` has private helpers `getBuyNowItems()` / `getBuyNowItemsFromRequest()` that reshape that session data into cart-shaped data so `placeOrder()` doesn't need two code paths).
- `OrderController::placeOrder` runs inside a DB transaction with `lockForUpdate()` on products to re-check stock, then creates `Order` + `OrderItem` + `Payment` + `Delivery` rows and a `Notification`.
- `OrderItem` stores denormalized snapshot fields (`product_name`, `product_image`) at order time, with fallback logic if the live `Product` is later deleted/changed — this was a deliberate fix (see commit `fb7c2fd`), not an oversight; don't "simplify" it back to a pure `belongsTo(Product)` lookup.
- `App\Console\Commands\ReleaseAbandonedOrders` (`orders:release-abandoned {--minutes=60}`) finds `pending` orders with a still-`pending` **Stripe or GCash** payment older than the cutoff, restores stock, marks the order `cancelled`/payment `failed`, and notifies the user. Cash orders are deliberately excluded since those are confirmed manually by an admin in person, not tied to an online payment attempt. GCash was excluded until the PayMongo migration — back when GCash meant manual receipt uploads, a slow customer/admin turnaround was legitimate; now GCash confirms instantly via PayMongo the same way Stripe does, so it's included in the same abandonment sweep. This command is not currently registered in `routes/console.php`'s scheduler — if periodic cleanup is expected, it needs to be scheduled explicitly (or run via external cron).

### Payments

- **Stripe**: direct `Stripe\StripeClient` usage in `PaymentController` (Checkout Sessions) — no Laravel Cashier. The Stripe webhook (`handleWebhook`) verifies the signature with Stripe's SDK, records each event id in `StripeWebhookEvent` (skipping ones already `processed`), and confirms through the same `confirmStripeSession()` the browser return uses. Marking a payment paid is atomic (`recordPaymentArrived()`: conditional `UPDATE ... WHERE payment_status <> 'paid'` in a transaction, so only one of a racing redirect/webhook confirms and notifies), and a payment arriving for a **cancelled** order is recorded as paid but never flips the order to confirmed (it is logged for manual refund). Config comes from `config('services.stripe.*')` / `STRIPE_KEY`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`.
- **GCash**: real payment gateway via PayMongo's Payment Intent API (`PaymentController::paymongoForm`/`paymongoCreateIntent`/`paymongoSuccess`/`paymongoWebhook`), test mode (`pk_test_`/`sk_test_`). Config comes from `config('services.paymongo.*')` / `PAYMONGO_PUBLIC_KEY`, `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`. The earlier manual receipt-upload flow (customer uploads a screenshot, admin approves/rejects) was removed — it had become unreachable dead code once every path (checkout, the order page's resume action, the payment-method switcher) routed GCash through PayMongo exclusively.

### AI custom design feature

`CustomDesignController` + `CustomDesignRequest`/`AiDesignSuggestion` models. `.env.example` shows multiple possible providers wired via `config/services.php`: `GEMINI_API_KEY`, `HUGGINGFACE_API_KEY`, `DEEPAI_API_KEY`, `OPENAI_API_KEY` (the latter via `openai-php/laravel`). Check `config/services.php` and the controller to see which provider is actually active before assuming.

**In progress:** turning an AI design request into a phone-confirmed, recorded agreement and a custom order (quotes, agreement records, payment ledger) is planned in [`docs/custom-orders-plan.md`](docs/custom-orders-plan.md). The "Approved decisions and changes" section at the end of that file overrides the plan above it. Read it before touching design requests, orders or payments for this feature.

Status: **Stage 0** (payment safety fixes in `PaymentController`) and **Stage 1** (schema + models only, no behaviour change) are done. The tables `custom_quotes`, `design_agreements`, `agreement_policies`, `custom_order_call_logs`, `custom_order_events`, `payment_records` exist with models, plus new columns on `custom_design_requests`/`orders`/`payments`; nothing in the app reads or writes them yet. `App\Support\InstallmentScheduler` now builds installment schedules (catalog = exactly 50% down; custom deposits must be >= `InstallmentScheduler::MIN_DEPOSIT_PERCENT`). `DesignAgreement` and `AgreementPolicy` are insert-only (model guards throw on update/delete; no triggers by design).

**Stage 2** (phone) is done: `App\Rules\PhilippineMobile` accepts `09…`/`+639…`/`639…` and `normalize()` stores `09XXXXXXXXX`. `updateProfile` only validates the phone when it is edited (legacy numbers save untouched; an account with no phone may stay empty). Registration keeps its own regex. Catalog checkout never needs a phone.

**Stage 3** (customer submission) is done: `selectDesign`/`sendWithoutImage` no longer approve anything; they open the "Send to our team" form (`design.sendForm` GET / `design.sendToTeam` POST). `sendToTeam` locks the request row, saves the contact fields, sets `status=approved` + `pipeline_status=submitted`, writes a `custom_order_events` row and `custom_order` notifications for the customer and every admin, all in one transaction. `CustomDesignRequest::isSentToTeam()` (pipeline set, or legacy `approved`/`converted_to_order`) blocks a second submit and `regenerate()`. Admin pages are unchanged (Mark contacted / Reject still work). 

**Stage 4** (admin Custom Orders) is done: `/admin/custom-orders` (list with status counts, search, 15/page; detail with call log and timeline) inside the `['admin','admin.timeout']` group. All state changes go through `App\Support\CustomOrderPipeline` (row lock, `lock_version` check, transition table, `custom_order_events` row; failures throw `PipelineException`). Actions: log call, mark unreachable (needs an unanswered call), decline (reason required, terminal; also sets legacy `status=declined`). The old Design Requests Mark contacted / Reject redirect to Custom Orders for any request with a `pipeline_status`, and still work for legacy rows.

**Stage 5** (quotes) is done: the admin saves a validated draft (`QuoteTerms::validate`, server-side money, deposit >= `InstallmentScheduler::MIN_DEPOSIT_PERCENT` of price+delivery and < total), reviews it on a confirm page and sends it; `CustomOrderPipeline` owns `createDraft/updateDraft/deleteDraft/sendQuote/expireStaleQuotes`. One draft and at most one sent quote per request (a revision draft may sit next to the sent one; sending it supersedes the old). Sent quotes expire after `CustomQuote::VALID_DAYS` (7) via `php artisan custom-orders:expire` (not scheduled, same as `design:fail-stale`) and a lazy check on admin/customer page loads. The customer's design page shows a read-only summary (never `call_notes`). Next is Stage 6 (customer review and accept, agreements).

### Models — key relationships

- `User` is the hub: `hasMany` CartItem, Order, Notification, Feedback, ChatbotLog, CustomDesignRequest, Payment; `hasOne` UserValidation.
- `Order` → `hasMany` OrderItem, MaterialUsage, TaskAssignment, InstallmentSchedule; `hasOne` Payment, Delivery, Feedback; `belongsTo` User and optionally a `CustomDesignRequest`.
- `Product` → `hasMany` OrderItem, CartItem, ProductImage (ordered by `sort_order`); `hasOne` primary `ProductImage` (`is_primary`); has an `available()` scope used by the buy-now helpers.
- `Inventory`/`MaterialUsage`/`Employee`/`TaskAssignment` support an internal production-tracking side of the app (material usage per order, staff task assignments) distinct from the customer-facing storefront.

### Frontend

Blade + Vite + Tailwind CSS v4, no JS framework. `resources/js/` is just `app.js` + `bootstrap.js` (vanilla/inline JS per view, Axios via bootstrap.js). `resources/css/app.css` is the single Tailwind entry point. Views are organized by feature under `resources/views/` (`admin/`, `auth/`, `cart/`, `design/`, `feedback/`, `orders/`, `payment/`, `product/`, `profile/`, `validation/`), with separate layout shells `layouts/app.blade.php` (storefront) and `layouts/admin.blade.php` (admin).


## Outstanding Priority Checklist

Working through a prioritized fix list (Tier 1 = critical). Status:

DONE:
- #1 Order Now / cart isolation (session + form-bound, see above)
- #2 DB transaction safety — already existed
- #3 Order item product snapshot — done, tested
- #4 Stock decrement + lockForUpdate — done, tested
- #4b Abandoned Stripe order release command — built, NOT yet
  scheduled (needs cron/Task Scheduler wiring)

NOT APPLICABLE:
- #5 Prevent duplicate GCash reference number reuse across orders —
  superseded by the PayMongo integration. The vulnerable path
  (`gcash_reference_number` from the manual receipt-upload flow) was
  removed entirely as dead code once PayMongo became the only GCash
  path — nothing writes that column anymore, so there's nothing left
  to duplicate.

ALSO DONE (found already built, with the commit/code that did it):
- #6 Account-based login lockout — customer and admin login are both
  keyed on the email being attempted, not the IP (commit `45cb925`)
- #7 AI Design prompts restricted to furniture — `FurnitureTopic` rule on
  the prompt in `CustomDesignController::submit`
- #9 Per-user daily AI Design cap — `DAILY_GENERATION_LIMIT` (3, failed
  attempts don't count) and `DAILY_ATTEMPT_LIMIT` (6) in
  `CustomDesignController`
- #10 Duplicate order submission — `OrderController::findRecentDuplicateOrder`
  (same user, total and items within 10 seconds)
- #11 Installment schedule rows — created in `placeOrder` by
  `createInstallmentSchedule()`. Implemented, but not covered by an
  automated test.

SUPERSEDED:
- #12 Shorter admin session timeout — commit `719515b` added
  `CheckAdminTimeout` (30 min) when customers had 120 min; commit
  `9e98886` then set `SESSION_LIFETIME=30` too, so customer and admin
  idle timeouts are now both 30 minutes by design.

PARTIAL / NOT STARTED:
- #8 Admin action audit trail — PARTIAL. Orders record `status_updated_by`,
  payments `verified_by`, validations `reviewed_by`. Not recorded: who
  marked an installment paid, who marked a design request contacted or
  declined, who approved/rejected a review.
- #13 Client-side inactivity warning before session expiry — NOT STARTED.
  There is a "Please log in to continue" notice after expiry (`719515b`),
  but no warning before it.
- #4b still open: schedule `orders:release-abandoned` (`routes/console.php`
  only contains the default `inspire` command).

When asked to "do the next item," work from the PARTIAL / NOT STARTED list
above.


## Note on frontend approach
Tailwind v4 + Vite exists in the base scaffold, but recent
feature work (checkout, orders, notifications, admin login) uses
hand-written plain CSS files under public/assets/css/, loaded
directly via asset() in @push('styles') blocks — NOT compiled
through Vite. Confirm which pattern to follow before adding new
styles to an existing feature; match what that specific page
already uses.
