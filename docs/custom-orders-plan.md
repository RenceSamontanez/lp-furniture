# AI Design custom order flow: plan

This is a plan only; nothing is built yet. I followed CLAUDE.md throughout: Blade views, plain CSS in `public/assets/css`, no new frameworks. Every admin route goes inside `['admin','admin.timeout']`, and no guard, login or middleware files change. The image generators (`GenerateAiDesign` and its providers) are not touched.

## 1. Inventory

| Item | What exists today |
|---|---|
| Contact fields on `custom_design_requests` | **None.** There is no phone, email or call window. Contact comes only from `users.phone_number` (nullable; Google sign-ups leave it null) and `users.email`. |
| `selectDesign()` | Owner-scoped. Marks one suggestion `is_selected` and sets status straight to `approved` with one click. No form, no notes, no contact confirmation. |
| `sendWithoutImage()` | Owner-scoped, only allowed from `rejected` (generation failed). Sets `approved` directly. |
| `regenerate()` | Owner-scoped. **Deletes all suggestion rows** and resets to `pending`, from any status, including after the customer has already sent the request to the team. |
| Admin `markContacted` | Sets `admin_contacted_at` (timestamp only, no record of who) and sends a `general` notification. |
| Admin `reject` | `approved` → `declined`, optional `admin_notes` as the reason, sends a `general` notification. |
| Notifications `type` ENUM | `order_update, payment_confirmed, payment_rejected, delivery_update, validation_approved, validation_rejected, design_ready, installment_due, general`. Live DB matches the migration. |
| `orders.design_request_id` | Exists, nullable, `Order::designRequest()` relation exists. **No foreign key, no index, no unique constraint.** Nothing in the codebase ever writes it. |
| `order_items.product_id` | Nullable, `ON DELETE SET NULL` (migration `2026_08_11_025531`). Snapshot `product_name` / `product_image` exist. |
| Convert-to-Order code | **None.** The `converted_to_order` status value exists in the ENUM and is read in `design/show.blade.php`, but nothing sets it. |
| `placeOrder` | Cart or buy-now items only. Re-checks stock under `lockForUpdate` and decrements it. Delivery fee is hardcoded at 500. Installment means exactly 50% down, then 1/2/3 months × every 15/30 days via `createInstallmentSchedule()` (a private method on `OrderController`). Accepts `stripe`, `gcash` or `onsite`. Order number is `Order::count()+1`, which can collide under concurrent checkouts; the unique index turns a collision into a generic error. |
| `stripeSuccess` / Stripe webhook | Both go through `confirmStripeSession()`. It checks the metadata order id (and the URL order id on the redirect path) and that the amount equals `payment.amount`. It then sets payment `paid` and order `confirmed`. The "already paid" check is read-then-write, so a simultaneous redirect and webhook can send two notifications. **It does not check whether the order was cancelled**, so a late payment can flip a cancelled order to `confirmed`. The webhook never writes to `StripeWebhookEvent`, even though CLAUDE.md says it does. |
| `paymongoSuccess` / PayMongo webhook | Same pattern through `markGcashPaymentPaid()`: amount check on the redirect path, the same read-then-write race, and no cancelled check. |
| `stripeCreateSession` | Creates a **new** Checkout Session on every click and overwrites the stored ids. Two tabs can produce two payable sessions, and the second capture is silently treated as "already paid". |
| `updatePaymentMethod` | Lets the customer switch to `onsite` (cash) whenever the order is pending and unpaid. |
| Installment schedule | Rows are created at checkout. `installmentMarkPaid` sets `paid` without recording the amount, method or which admin did it, and never updates the parent payment status. |
| `ReleaseAbandonedOrders` | Cancels Stripe/GCash orders still pending after 60 minutes and adds the stock back (`product_id` null makes that a no-op). **It would cancel a customized order**, and the unique constraint we're adding would then block a new order for that agreement. |
| Cash orders | `payment_status` never becomes `paid` through any code path (the code even says so in `orders/show.blade.php`). |
| Profile phone | `updateProfile` validates `required\|string\|max:20` only. Registration uses the Philippine mobile pattern, but profile edits don't. |
| Admin notifications | Admins have no notification UI at all. The customer notification routes are web-guard only. |
| Timezone | `APP_TIMEZONE=Asia/Manila`; PHP and MySQL agree. |
| Live DB vs migrations | `custom_design_requests.furniture_type`, `custom_design_requests.style_preference` and `ai_design_suggestions.is_favorited` exist live but in no migration. `users.remember_token` also showed up, but it's a false positive from `rememberToken()`. Enum values match. |

## 2. Schema and ENUM changes

**How migrations will be written:**
- Every migration is additive and guarded with `Schema::hasColumn` / `hasTable`, so it runs safely against the live DB.
- ENUMs are widened with `->change()`, the pattern the repo already uses. Each `down()` first maps new values back to existing ones, then shrinks the ENUM.
- The DB-level rules (triggers, generated-column unique keys, `FOREIGN_KEY_CHECKS`-safe foreign keys) are MySQL/MariaDB-only. SQLite can't run them, and the SQLite test DB already can't migrate anyway.

**Migrations:**

**M0. Reconcile drift.** Adds `furniture_type`, `style_preference` and `is_favorited` only if missing, so a fresh install matches live. Adds an index plus a `SET NULL` foreign key on `orders.design_request_id`.

**M1. `custom_design_requests` (new columns):**
- `pipeline_status` ENUM, null for requests not yet submitted. Values: `submitted, contacting, unreachable, quoted, accepted, ordered, expired, declined_by_customer, declined_by_admin, cancelled`.
- `request_mode` ENUM (`exact`, `modified`) and `modification_notes` TEXT.
- `selected_suggestion_id` (foreign key, `SET NULL`).
- `contact_phone`, `contact_email` and `preferred_call_window` (ENUM `morning_8_12`, `afternoon_12_5`, `anytime`).
- `submitted_at`, `decline_reason`, `lock_version` (INT, for optimistic locking).

The existing `status` keeps its current meaning: `approved` means "sent to team".

**M2. `custom_order_call_logs`:** `request_id`, `admin_id`, `attempt_no`, `called_at`, `answered` (bool), `note`, `created_at`. Append-only.

**M3. `custom_quotes`:**
- `request_id`, `version` (unique together with `request_id`), `status` ENUM `draft, sent, superseded, accepted, declined, expired`.
- Terms: `final_description`, `width_cm`, `depth_cm`, `height_cm`, `material`, `finish`, `modifications`, `price` DECIMAL(10,2) up to 999,999.00, `delivery_option`, `delivery_fee`, `delivery_address`, `production_days`.
- Payment terms: `payment_terms` ENUM (`full`, `deposit`), `deposit_amount`, `installment_duration_months` (1/2/3), `installment_frequency_days` (15/30).
- `call_notes` (internal only, never shown to the customer).
- Version history: `previous_price`, `created_by`, `sent_by`, `sent_at`, `expires_at`, `is_amendment`, `amends_agreement_id`, `customer_decline_reason`.
- **One open quote per request:** a stored generated column `open_slot = IF(status IN ('draft','sent'), request_id, NULL)` with a UNIQUE index.

**M4. `agreement_policies`:** `version` (unique), `title`, `body`, `created_at`. Seeded with v1 as a placeholder: `[Refund and cancellation policy — text to be supplied]`. Rows are immutable, enforced by the same triggers as M5.

**M5. `design_agreements`:**
- Identity: `agreement_number` (unique), `request_id`, `quote_id` (**UNIQUE**), `quote_version`, `amends_agreement_id`.
- People and time: `user_id`, `finalized_by`, `sent_at`, `accepted_at`, `accepted_at_local` (string, Asia/Manila).
- Request details: `ip_address` (45), `user_agent` (512).
- Contents: `policy_version`, `terms_snapshot` JSON, `snapshot_sha256`, `total_amount`, `upfront_amount`, `created_at`.
- Foreign keys use `RESTRICT`.
- Triggers `BEFORE UPDATE` and `BEFORE DELETE` both `SIGNAL SQLSTATE '45000'`.

**M6. `orders`:** `design_agreement_id` (nullable, **UNIQUE**, `RESTRICT`), `current_agreement_id` (for amendments after payment, see decision D1), `amount_paid` DECIMAL, `expected_ready_date`, `needs_review` (bool) and `review_reason`.

**M7. `payment_records` (the ledger):**
- `order_id`, `payment_id`, `installment_schedule_id` (nullable), `amount`.
- `method` ENUM `cash, gcash_direct, bank_transfer, stripe, gcash_paymongo`, `gateway_reference` (nullable).
- `paid_on`, `recorded_by` (null means the system recorded it), `note`, `created_at`.
- UNIQUE(`method`, `gateway_reference`) stops one gateway capture being recorded twice.
- Also: `payments.payment_status` ENUM gains `partial`, and `payments` gets `design_agreement_id`.

**M8. `custom_order_events`:** `request_id`, `order_id` (nullable), `type`, `actor_type` (`customer`/`admin`/`system`), `actor_id`, `data` JSON, `occurred_at`. Append-only; this feeds both timelines.

**M9. Notifications:** `type` ENUM gains `custom_order`, a single new value to keep ENUM changes small.

**M10. Backfill the ledger:** one `system` entry for every existing paid online payment and every paid installment, so `amount_paid` starts out correct. Cash orders get **no** entries: there's no record those were paid, so admins see them as "Unrecorded".

## 3. Statuses and allowed transitions

**Request `pipeline_status`:**

| From | To | Trigger |
|---|---|---|
| (null) | `submitted` | Customer submits the request form |
| `submitted` | `contacting` | First call is logged |
| `submitted`, `contacting` | `unreachable` | Admin marks unreachable; needs ≥1 unanswered call logged |
| `unreachable` | `contacting` | An answered call is logged |
| `submitted`, `contacting`, `unreachable` | `declined_by_admin` | Admin declines; reason required |
| `contacting` | `quoted` | Quote sent; needs ≥1 answered call logged |
| `quoted` | `quoted` | Admin edits a sent quote: version N+1 is created, N becomes superseded |
| `quoted` | `accepted` / `declined_by_customer` / `expired` | Customer accepts / customer declines / 7 days pass |
| `expired`, `declined_by_customer` | `quoted` | Admin sends a new version |
| `accepted` | `ordered` | Agreement checkout creates the order |
| `accepted` | `expired` | Payment window passes with no payment started |
| `accepted`, `ordered` (unpaid) | `quoted` | Amendment sent; the unpaid order is cancelled |
| `ordered` (paid) | `quoted` | Amendment sent (see D1); the order stays |
| any non-terminal | `cancelled` | Admin cancels with a reason. If money was paid, the refund happens outside the system and is noted. |

Terminal states are `declined_by_admin` and `cancelled`. "Completed" comes from the order.

**Quote:** `draft` → `sent` (after the confirmation step) · `draft` → deleted · `sent` → `superseded` / `accepted` / `declined` / `expired` · `accepted` → `superseded` (only when an amendment is sent).

**Agreement:** no status column, because the row can never change. "Current", "superseded" and "void" are worked out from the quote and request rows.

**Customized orders:** these follow a forward-only map: `pending` → `confirmed` (upfront amount paid) → `in_production` → `ready` / `out_for_delivery` → `completed`. `cancelled` is allowed only while unpaid, unless the admin enters a reason. Catalog orders keep today's free-form dropdown.

**Payment:** `pending` → `partial` / `paid` / `failed` · `partial` → `paid`. A `refunded` status already exists, but nothing would set it.

**Installment rows:** `pending` / `overdue` → `paid`, now only through Record Payment.

## 4. Notification wording

All use type `custom_order`. Admin notifications go to every `role=admin` user and link to admin routes.

| Event | Customer | Admin |
|---|---|---|
| Submitted | "We received your request for **{design}**. We'll call you at {phone} during your preferred time ({window})." | "New custom order request: **{design}** from {name}. Preferred call: {window}." |
| Unreachable | "We tried to reach you about **{design}** but couldn't get through. Please check that {phone} is correct, or message us at {contact}." | (none) |
| Declined by admin | "We're unable to take on **{design}**. Reason: {reason}" | (none) |
| Quote sent | "Your quote for **{design}** is ready: ₱{total}. Please review and accept by {expiry}." | (none) |
| New version | "Your quote for **{design}** was updated (version {v}, ₱{old} → ₱{new}). The previous quote can no longer be accepted." | (none) |
| Accepted | "You accepted agreement **{no}** for **{design}**. Agreed on {date time}. Next: pay ₱{upfront} by {due}." | "{name} accepted agreement **{no}** for **{design}** (₱{total}). Agreed on {date time}." |
| Declined by customer | "You declined the quote for **{design}**." | "{name} declined the quote for **{design}**. Reason: {reason or 'none given'}." |
| Expired | "Your quote for **{design}** expired on {date}. Contact us if you'd still like to proceed." | "Quote for **{design}** ({name}) expired without acceptance." |
| Amendment | "Agreement **{no}** for **{design}** has an amendment that needs your acceptance. Your original agreement stays on record." | (none) |
| Payment received | "Payment of ₱{amount} received for order **{order no}** (agreement **{no}**). Expected ready date: {date}." | "Payment of ₱{amount} received for customized order **{order no}**." |
| Payment needs review | (none) | "A payment for **{order no}** arrived for an outdated agreement version, or after cancellation. Review needed." |

## 5. How checkout and payment accept an agreement

- **Entry:** `GET /custom-orders/{agreement}/checkout` (auth + validated). Checks that the agreement is owned by `Auth::id()`, is the request's current agreement, has its quote still `accepted`, has no order yet, and is before `payment_due_at`. It renders the existing `checkout.blade.php` in a locked mode: terms are read-only, and the only choice is Stripe or GCash.
- **Place order:** a dedicated `placeAgreementOrder` action, **not** a branch inside `placeOrder`. `placeOrder` trusts client-sent delivery, payment type and installment fields and runs the stock lock-and-decrement. Mixing agreement orders into it risks honoring a tampered field or touching stock code. The new action shares only the extracted helpers: `redirectForOrder`, the installment scheduler (moved into `App\Support\InstallmentScheduler`), and order-number generation.
- **Price source:** the request carries only `payment_method` (`in:stripe,gcash`) and the agreement id from the URL. Total, upfront amount, delivery and schedule are read from `terms_snapshot` on the server, and the stored `snapshot_sha256` is re-checked first.
- **Version check:** inside the transaction, `lockForUpdate` the request row and confirm the agreement is still current and the quote is still `accepted`. Insert the order; the UNIQUE `design_agreement_id` blocks a second order.
- **Item and stock:** one `order_items` row with `product_id = NULL`, `product_name` = the design title, `product_image` = a copy of the reference image saved at acceptance, quantity 1, `unit_price = price`. No stock queries at all.
- **Deposit:** `payments.amount` = the upfront amount (the full total, or a deposit of at least 50% of the total including delivery). For a deposit, `payment_type = installment` and the remainder is split by the existing rules (1/2/3 months × 15/30 days).
- **Payment pages:**
  - `stripeCreateSession` and `paymongoCreateIntent` refuse a customized order whose agreement is no longer current or is past `payment_due_at`.
  - Stripe reuses an open session instead of creating a second one, and sets the session's `expires_at` no later than the due date.
  - `updatePaymentMethod` blocks `onsite` for customized orders.
- **Success handlers and webhooks:**
  - Mark paid with a conditional update (`WHERE payment_status <> 'paid'`) and only notify if a row actually changed.
  - Write the ledger entry, protected by the unique gateway reference.
  - If the order is cancelled, or its agreement isn't current, record the money but **don't confirm**: set `needs_review` and alert admins.
  - Never check expiry, so a payment that began before expiry is honored.
  - The cancelled-order guard is also needed for catalog orders, since it's the same bug.

**Risks to flag:**
- Changing the shared payment handlers affects every order, so those changes need their own stage and testing with mocked gateways.
- Copying the image file isn't undone if the DB transaction rolls back. I'd copy before the transaction and delete the copy on failure.
- `ReleaseAbandonedOrders` must skip customized orders (`whereNull('design_agreement_id')`). A new `custom-orders:expire` command, plus a lazy check like `failStale()`, handles their expiry instead.

## 6. Concurrency

All admin edits, acceptance and agreement checkout first take `lockForUpdate` on the `custom_design_requests` row, so the request row is the single point where they're serialized.

| Case | Prevention |
|---|---|
| Admin edits during payment | Amendments are refused while an online attempt is open; the admin sees "payment in progress" with an option to expire the Stripe session. If a capture lands anyway, the version check in the handler records it as `needs_review` instead of confirming. |
| Double payment | Open Stripe sessions are reused. Ledger UNIQUE(`method`, `gateway_reference`) stops the same capture being recorded twice. A second, different capture on an already-paid order is recorded and flagged for refund. Conditional paid-update ends duplicate notifications. |
| Expiry | Checked on the server at accept, at checkout entry, and at gateway session/intent creation, each inside the lock. Never checked when completing a payment. |
| Double acceptance | Conditional `sent → accepted` update plus `design_agreements.quote_id` UNIQUE; the loser is sent to the existing agreement. |
| Double order or reused link | `orders.design_agreement_id` UNIQUE, plus the "no order yet" check inside the lock. |
| Two admins editing | `lock_version` hidden field (stale save is rejected), the `open_slot` unique index, and UNIQUE(`request_id`, `version`). |
| Order-number race | The existing `count()+1` approach is kept but retried on a unique-key collision (bounded number of attempts). |

## 7. Agreement record

- **What it stores:** everything in §2 M5. The `terms_snapshot` holds every agreed term, the design title and reference image path, the full policy text plus its version number, the payment schedule preview, and the customer and admin names as they were at the time.
- **How immutability is enforced, not just by convention:**
  1. MySQL triggers block every UPDATE and DELETE.
  2. `RESTRICT` foreign keys, so deleting a user or request can't cascade into agreements.
  3. Model `updating`/`deleting` events throw.
  4. `snapshot_sha256` is re-verified whenever an agreement is shown, printed or checked out; a mismatch blocks it and alerts admins.
- **Changes after acceptance:** a new agreement row that points back at the original through `amends_agreement_id`. The original is never changed.
- **Personal data held:** customer name, email, phone, delivery address (if delivery), IP address and user agent, plus the admin's name.
- **Who can see it:** only the owner and admins. Pages send `Cache-Control: no-store`, and the image is served through an ownership-checked route.
- **Note:** an IP address counts as personal data under the Philippine Data Privacy Act. Your policy text should say this is collected; I'm not giving legal advice there.

## 8. Files, build order, risks

**New:**
- Migrations M0–M10.
- Models: `CustomQuote`, `DesignAgreement`, `AgreementPolicy`, `CustomOrderCallLog`, `PaymentRecord`, `CustomOrderEvent`.
- `App\Support\InstallmentScheduler` and `App\Rules\PhilippineMobile`.
- Controllers: `Admin\CustomOrderController`, `Admin\PaymentRecordController`, `Admin\NotificationController`, `CustomAgreementController` (customer side).
- Command: `ExpireCustomOrders`.
- Views: customer submit form, review-and-accept, agreement page, print view, admin custom-orders index/show/quote form, timeline partial.
- CSS: `custom-orders.css` and `agreement-print.css`.

**Changed:** `CustomDesignController` (selectDesign, sendWithoutImage, regenerate guard, phone gate), `DesignRequestController` (folded into Custom Orders, old URLs redirect), `AuthController::updateProfile` (validation rule only, no login logic), `OrderController`, `PaymentController`, `AdminController` (installmentMarkPaid, orderUpdateStatus, reports), `ReleaseAbandonedOrders`, `routes/web.php`, `layouts/admin.blade.php`, and the order/checkout/design views.

**Build order (one commit after each stage):**
1. Schema M0–M10 and models; no behavior change.
2. Phone requirement: mobile-only rule, profile prompt, "Unverified" label.
3. Customer submission form; block regenerate after submitting.
4. Admin Custom Orders: list, pipeline counts and filters, detail page, call log, unreachable, decline.
5. Quote drafts, versions, send confirmation ("Send ₱X to [customer]?"), notifications, expiry.
6. Review and accept, agreement record, print view, admin notifications page.
7. Amendments.
8. Payment ledger, Record Payment, backfill, cash orders reaching `paid`, installments paid through the ledger.
9. Agreement checkout, `placeAgreementOrder`, payment-handler guards, abandonment exclusion, expiry command.
10. Timelines, expected ready date, reports label.
11. Full RBAC matrix in fresh processes plus flow tests. Gateways are mocked; destructive routes are only hit with non-existent IDs.

**Risks:**
- Stage 9 changes shared payment code.
- The triggers need the `TRIGGER` privilege, and `log_bin_trust_function_creators` if binary logging is on. XAMPP root is fine.
- None of this can be tested on SQLite.
- Existing cash orders stay "Unrecorded" until an admin records their payments.

**Decisions I need from you:**
- **D1. Amendment after payment.** My recommendation: the same order stays, `current_agreement_id` is re-pointed, and the price difference is collected through Record Payment, with refunds handled outside the system. The alternative is to block amendments once anything is paid.
- **D2. Payment window.** My recommendation: the customer must start paying by the later of quote expiry and acceptance + 24h. Otherwise accepting on day 7 leaves almost no time to pay.
- **D3. Expected ready date.** Count production days as Mon–Sat working days (my default) or as calendar days?
- **D4. Test database.** I'd create a separate MySQL test database for this feature's tests, since SQLite can't run the triggers or generated columns.
- **D5. Policy text.** Send it whenever it's ready; v1 is a placeholder until then.

Separately, CLAUDE.md is out of date in two places: it says Stripe webhooks are logged to `StripeWebhookEvent` (they aren't), and its priority checklist doesn't reflect recent commits.

---

## Approved decisions and changes

These were approved after the plan above was written. **Where they conflict with the plan above, this section wins.**

### Decisions

- **D1: amendments are blocked once any payment is recorded.** Once a payment exists on the order, the admin cancels it with a reason and creates a new request instead. The amendments stage (stage 7 above) is moved to the end of the build order and may be skipped entirely.
- **D2: payment window.** Payment must be started by the later of quote expiry or acceptance + 24h.
- **D3: expected ready date.** Production days count Mon–Sat working days, and the date is shown as "about [date]".
- **D4: test database.** Tests use a separate MySQL database `furniture_db_test`, with a guard that aborts any test run on a database whose name doesn't end in `_test`. Destructive tests are never run on `furniture_db`.
- **D5: policy text.** The refund and cancellation text is a placeholder (policy v1) until it is supplied.

### Other changes

- **Minimum deposit:** 50% for customized orders, kept as one named constant.
- **Delivery fee:** set by the admin on each agreement (default 500).
- **No MySQL triggers and no generated columns.** Immutability comes from `RESTRICT` foreign keys, model update/delete guards that throw, no update or delete routes, and the SHA-256 check. "One open quote per request" and "no double acceptance" are enforced by `lockForUpdate` on the request row plus plain unique indexes only.
- **Installment scheduler:** it must accept a deposit amount (at least 50%) when it moves to `App\Support\InstallmentScheduler`. Catalog behavior (exactly 50%) must stay identical, re-tested for all 6 duration/frequency combinations.
- **Migrations:** additive, guarded with `hasColumn`/`hasTable`, and each has a working `down()`.

### What this supersedes in the plan above

- §2 M3: the `open_slot` generated column and its UNIQUE index are dropped. Use `lockForUpdate` on the request row plus plain unique indexes (e.g. UNIQUE on `request_id` + `version`).
- §2 M4 and M5: the `BEFORE UPDATE` / `BEFORE DELETE` triggers are dropped. Immutability uses the mechanisms listed above.
- §2 "How migrations will be written": the triggers and generated-column notes no longer apply.
- §7 "How immutability is enforced": items 2–4 (`RESTRICT` foreign keys, model guards, SHA-256 check) stand; item 1 (triggers) is dropped; add "no update or delete routes".
- §8 build order: the Amendments stage (7) moves to the end and may be skipped. §3 and §5 amendment behavior only applies if that stage is built, and only while no payment is recorded.
- §8 "Decisions I need from you": D1–D5 are settled as above, replacing my recommendations where they differed.
- §5 and §8 risks: the triggers privilege risk no longer applies.

### Rules for every stage

1. RBAC first.
2. Admin routes go in `['admin','admin.timeout']`.
3. Customer routes check ownership.
4. Nothing touches guard or login logic.
5. Never send real requests to destructive endpoints.
6. Mock gateways.
7. Clean up test data.
8. One commit per stage, with the file list shown first.
