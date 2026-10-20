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

**Stage 5** (quotes) is done: the admin saves a validated draft (`QuoteTerms::validate`, server-side money, deposit >= `InstallmentScheduler::MIN_DEPOSIT_PERCENT` of price+delivery and < total), reviews it on a confirm page and sends it; `CustomOrderPipeline` owns `createDraft/updateDraft/deleteDraft/sendQuote/expireStaleQuotes`. One draft and at most one sent quote per request (a revision draft may sit next to the sent one; sending it supersedes the old). Sent quotes expire after `CustomQuote::VALID_DAYS` (7) via `php artisan custom-orders:expire` (not scheduled, same as `design:fail-stale`) and a lazy check on admin/customer page loads. The customer's design page shows a read-only summary (never `call_notes`).

**Stage 6** (review and accept) is done: `CustomAgreementController` (customer, inside auth + validated, owner-scoped with `Auth::id()` so another customer gets 404) serves the review page (`design.quote.review`), accept, decline, the agreement page, a print view and an ownership-checked image route (`/agreements/{id}[/print|/image]`, all `Cache-Control: no-store`). `CustomOrderPipeline::acceptQuote/declineQuote` lock the request row, re-check status/currentness/expiry (server time, Asia/Manila), flip `sent -> accepted` conditionally, insert the `design_agreements` row (number `LP-A-YYYY-NNNN`, retried on collision) with a `terms_snapshot` built by `App\Support\AgreementTerms` and its `snapshot_sha256`, set `custom_design_requests.payment_due_at` (later of quote expiry and acceptance + 24h), and write the event and notifications. The reference image is copied to the private `local` disk (`agreements/…`) before the transaction and deleted if the transaction does not create the agreement. `App\Support\AgreementIntegrity` re-verifies the hash every time an agreement is shown; a mismatch blocks the page (409) and alerts admins. Admin side: `admin.agreements.{show,print,image}`, an agreement panel on the Custom Order detail page, and `admin.notifications.*` (own notifications only, unread badge in the sidebar). No route updates or deletes an agreement. Checkout and payment for an accepted agreement are not built (Stage 9); amendments (Stage 7) are not started.


**Stage 8** (payment ledger) is done: `PaymentController::recordPaymentArrived` locks the order row, flips the payment and writes a `payment_records` row (`stripe` / `gcash_paymongo`, reference = payment intent id) and `orders.amount_paid` in one transaction; a repeat capture is a no-op through UNIQUE(method, gateway_reference). A second *different* Stripe capture for an already-paid order is ledgered and sets `needs_review` + an admin notification without touching the status; a payment for a cancelled order also sets `needs_review`. Admins record cash / GCash direct / bank transfer through `App\Support\RecordPayment` (order page "Record payment", one-time form token, 10 s duplicate window): it locks the order, derives the balance from the ledger, updates `payments.payment_status` (partial/paid), closes a linked installment only when covered, and moves only `pending -> confirmed` once `payments.amount` is reached. `AdminController::installmentMarkPaid` goes through the same service (cash, with a note). `orders.reviewed_by/reviewed_at` record "Mark reviewed". Refunds are handled outside the system. Pending migration: `2026_10_17_090000_add_review_audit_to_orders`.

**Stage 9** (agreement checkout) is done: `/custom-orders/{agreement}/checkout` (`CustomCheckoutController` + `App\Support\AgreementCheckout`) builds the order, a product-less item, the payment and installment rows only from the hash-verified `terms_snapshot` (form posts only `payment_method` stripe|gcash), under the request row lock, one order per agreement. Stripe/PayMongo session creation is refused past `payment_due_at` or for a non-current agreement, completion never is; a payment for an outdated agreement is ledgered and flagged, not confirmed; confirmation sets `orders.expected_ready_date` (Mon-Sat). Stripe reuses an open session. `ReleaseAbandonedOrders` skips customized orders; `custom-orders:expire` also expires accepted agreements with no order past the window. Customized order status changes go through `App\Support\CustomOrderStatus` (forward-only; cancel needs a reason once money was received). Amendments (Stage 7) are not started.


**Other Services (Stage 21)**: `/services/request` needs an account (same `['auth','validated']` group as the Customize page); admin side is `/admin/service-inquiries`. Tickets never become orders. Simple invoices (`ServiceInvoice` / `ServiceInvoiceLine`, logic in `App\Support\ServiceInvoiceDesk`) are draft -> issued (locked by model guards) -> paid / void, numbered `INV-YYYY-NNNN` at issue time, and deliberately independent of the payment ledger, Stripe, PayMongo and the custom order pipeline. The printed footer lives in `config/invoice.php`; see `docs/service-invoices-to-confirm.md` for what the owner must confirm after BIR registration.

**Online payments for Other Services invoices (Stage 21e)**: a separate flow from the custom-order ledger. One plan per invoice chosen when the admin sends it (`full`, or `half_half` = 50/50, only where `App\Support\InvoicePaymentRules` allows it: service type and total threshold). Payments are rows in `service_invoice_payments` (stripe / paymongo / cash; `paid_slot` UNIQUE = one paid record per installment); `service_invoices.amount_paid` is always the sum of the paid rows, set in the same transaction. Logic lives in `App\Support\ServiceInvoicePayments` (start, confirm, cash, service done, refund notes) and talks to the gateways only through `App\Support\InvoiceGateway` (faked in tests). The existing `/webhook/stripe` and `/webhook/paymongo` hand invoice sessions/intents (metadata `kind=service_invoice`, or a known intent id) to that service and are otherwise untouched; the Stripe webhook now also refuses everything when `STRIPE_WEBHOOK_SECRET` is empty. The second half is payable only after the admin taps "Service done". An invoice with any payment can't be cancelled (refund notes are records only; no code path ever refunds). Customer routes that act on an invoice use `invoice.actionable`. Shared button classes `.lp-btn` (primary / secondary / danger / chip) live in `public/assets/style.css`: use them for every new button; `tests/ButtonContrast/measure.js` audits contrast per state.

**Cancellations and refunds for Other Services (Stage 21f)**: `ServiceInvoice::paymentBlockReason()` is the one place that decides whether a payment may start (`draft`, `invoice_cancelled`, `ticket_cancelled`, `on_hold_cancellation_request`, `already_paid`, `installment_not_open`); `isPayable()`, every pay route, `ServiceInvoicePayments::start/recordCash/due` and the pay buttons use it. Blocking an invoice calls `ServiceInvoicePayments::closeOpenCheckouts()` (pending rows -> expired, open Stripe sessions expired; PayMongo has no expire call, so a late GCash payment is still recorded and raises the admin alert). The admin's ticket "close" goes through `ServiceRefundRequestService::adminClose()`: a ticket with an unpaid sent invoice becomes `cancelled` together with its invoice and the customer is notified; a ticket with money on it is refused (`PAYMENT_ON_TICKET`) and must go through a cancellation request (a finished and fully paid one closes normally). `service_inquiries` gained `service_started_at` (admin "Work started", also set by "Service done") and `terms_accepted_at`; `App\Support\ServiceWorkState::for()` returns not_started/started/done everywhere. `ServiceRefundRequest` (REF-YYYY-NNNN; tables `service_refund_requests/attempts/messages`) is created and changed only through `ServiceRefundRequestService` (customer form, admin screens, Luma later): one open request per ticket (`open_slot` UNIQUE) holds the invoice; decline lifts the hold; `markRefunded` (transaction number required) is the ONLY path that cancels a paid invoice and the ticket. Approve only while the live work state is not_started; "Approve as an exception" needs a written reason and is flagged. The GCash number is encrypted (model cast), masked in lists, shown in full only on the admin detail page, and never written to logs, notes, messages or notifications. Nothing ever calls a Stripe or PayMongo refund API. Shared UI: `.lp-notice` / `<x-notice>` (see `docs/ui-components.md`). The 8d admin refund-note form and route were removed; the table `service_invoice_refund_notes` stays and shows read-only as "Earlier refund notes". Owner/lawyer questions: `docs/service-terms-to-confirm.md`.

**Luma, the chatbot (Stage 21i, `App\Support\Luma\*`)**: `Luma::reply()` handles one message in this order: an open guided flow, a reference number (`Reference`, REF-/INV-/SI-YYYY-NNNN in any form), the answer to a question she just asked, thanks/goodbye/follow-ups (`Context`: "yes", "how", "when", "sige"), the trained intents (`IntentMatcher`; a keyword `a&b` means both words anywhere), then size/budget/type, then the fallback. Memory is `ChatState` in the session (last topic, domain `os`|`furniture`, flow, misses; 30 minutes idle expiry; stamped with the customer id, cleared on both logouts). The Other Services knowledge is a tree in `OtherServicesKnowledge` (the single source: `chatbot:sync-defaults` inserts it as `luma_os_*` intents, insert-only; `php artisan luma:knowledge-map` regenerates `docs/luma-knowledge-map.md`; every example phrasing in it must route to its node: `tests/Feature/LumaKnowledgeMapTest`, quick no-DB loop `php tests/Luma/route_check.php`, why one message routes where it does: `php tests/Luma/why.php "msg"`). A bare refund/cancel/payment/gcash/status asks once "Other Services or furniture order?" (`Disambiguation`) and remembers the answer; the furniture answers (`luma_cancel_refund`, `luma_payment_methods`, `luma_track_order`) are untouched. `StatusAnswers` reads ONLY the signed-in customer's tickets/invoices/requests (queries scoped by `user_id`; someone else's number, a draft and a missing number all give the same neutral reply) and only shows Pay now when `ServiceInvoice::paymentBlockReason()` is null. `RefundFlow` is the guided cancellation/refund: it explains, shows a form card (reply type `form`), validates with `ServiceRefundRequestService::validate()`, shows a confirm summary and creates with `create(..., 'luma')`; the GCash number and name travel only in an encrypted single-use token (never in the session, `chatbot_logs`, notifications or replies; numbers typed into the chat are masked in the log). Endpoint `POST /chatbot/refund` (auth, throttled). Tone rules (never "approved"/"guaranteed", one apology, no promised date or refund) are in `docs/luma-tone-rules.md` and `tests/Feature/LumaToneTest`. Corpus: `tests/Luma/corpus.php` + `LumaCorpusTest` (95% overall, 100% on refund/cancel/payment/unpaid). The 23 original default intents were NOT rewritten: see `docs/luma-intent-review.md`. Tickets now need a 40-character description or a photo, record `work_location`, and the admin must tick "I have enough details to do this work" to send an invoice for a vague ticket. Browser walk: `tests/Luma/browser-checks.cjs` (scratch database only).

### Models — key relationships

- `User` is the hub: `hasMany` CartItem, Order, Notification, Feedback, ChatbotLog, CustomDesignRequest, Payment; `hasOne` UserValidation.
- `Order` → `hasMany` OrderItem, MaterialUsage, TaskAssignment, InstallmentSchedule; `hasOne` Payment, Delivery, Feedback; `belongsTo` User and optionally a `CustomDesignRequest`.
- `Product` → `hasMany` OrderItem, CartItem, ProductImage (ordered by `sort_order`); `hasOne` primary `ProductImage` (`is_primary`); has an `available()` scope used by the buy-now helpers.
- `Inventory`/`MaterialUsage`/`Employee`/`TaskAssignment` support an internal production-tracking side of the app (material usage per order, staff task assignments) distinct from the customer-facing storefront.

### Frontend

Blade + Vite + Tailwind CSS v4, no JS framework. `resources/js/` is just `app.js` + `bootstrap.js` (vanilla/inline JS per view, Axios via bootstrap.js). `resources/css/app.css` is the single Tailwind entry point. Views are organized by feature under `resources/views/` (`admin/`, `auth/`, `cart/`, `design/`, `feedback/`, `orders/`, `payment/`, `product/`, `profile/`, `validation/`), with separate layout shells `layouts/app.blade.php` (storefront) and `layouts/admin.blade.php` (admin).


**Landing hero and glass nav**: the storefront nav is a floating glass bar (`nav.site-nav`, `public/assets/css/site-nav.css`, hamburger script `public/assets/js/site-nav.js`; the old `nav-links`/`nav-btn`/`nav-burger` classes are dead). The home hero (`resources/views/partials/home-hero.blade.php`, `public/assets/css/home-hero.css`) has an AI Showcase card (`config/hero.php`, images in `public/images/hero-ai/`) and two product rails fed by `App\Support\HeroRails`, desktop only (>= 1100px). Glass buttons are `.lp-btn--glass-primary|outline|quiet`. Details: `docs/hero-notes.md`, `docs/ui-components.md`; browser walk `tests/UiLinks/browser-hero.cjs` (scratch DB; seed products with `php tests/UiLinks/seed_hero.php`).

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
