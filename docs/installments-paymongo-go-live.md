# Installments: what PayMongo must enable before going live

Status checked against PayMongo's **test** API with the shop's `sk_test_` key (2026-10-31).

## What the test account refuses today

| Call | Answer |
| --- | --- |
| `POST /v1/payment_intents` with `setup_future_usage` `on_session` | 400 `parameter_invalid`: "On session payments are not yet supported." |
| same with `off_session` | 400 `parameter_invalid`: "Off session payments are not yet supported." |
| `GET /v1/customers/{id}/payment_methods` after a successful card payment | empty (the card is not vaulted) |
| Charging a card payment method again, with nobody present | 3-D Secure redirect (`awaiting_next_action`); a method cannot be re-attached (`parameter_attached_state`) |
| `/v1/subscriptions/plans`, `/v1/subscriptions` | 403 `merchant_invalid_state`: "no subscription payment methods are configured for this organization." |

So today a card can be paid once (the 50% down payment) and nothing more can be charged to it unattended.

## What to ask PayMongo support (support@paymongo.com) to enable, on the test AND the live account

1. **Card vaulting** (saving the customer's card from the down payment, `setup_future_usage`).
2. **Subscriptions** (recurring card charges without the customer present). Documented behaviour: the first invoice is created immediately and must be paid within 24 hours; a failed invoice is retried once a day, up to three times; on-demand plans are Maya only.

## Open questions for PayMongo (they decide the design of the live driver)

1. **One fixed plan amount vs our last-row remainder.** A plan has one amount per cycle. Our schedule splits the balance into equal installments and the *last one absorbs the remainder*. Options: one plan per distinct amount, a plan for the equal installments plus a separate charge for the last, or rounding so every installment is equal. Which does PayMongo support for a plan with a fixed number of cycles?
2. **The first subscription invoice vs the 50% down payment.** A subscription creates its first invoice immediately. Our first payment is the 50% down payment (a different amount) and the first *installment* is due 15 or 30 days later. Can the first invoice be skipped, delayed, or be the down payment itself? Can the billing anchor be set to the first due date?
3. Can the shop **trigger a cycle** (or charge a vaulted card) from its own daily job when a cycle needs a retry on our schedule (retry next day, Pay Now link, 3-day grace), or does PayMongo's own retry (daily x3) replace ours?
4. Which **webhook events** report a cycle's result, and do they carry the invoice / payment intent id and metadata we can match to an installment?
5. How does the customer **update the card** on a subscription?

## How the code is prepared

* `InstallmentGateway` is the only thing the installment engine talks to. `PayMongoInstallmentGateway` is the real driver; `SandboxInstallmentGateway` extends it and only replaces the unattended card charge with a simulated result (see CLAUDE.md, Installments).
* `config('installments.gateway')` = `paymongo` | `sandbox` (unset: sandbox with an `sk_test_` key, paymongo otherwise). The sandbox refuses to run, and is not bound, with any other key.
* `config('installments.vault_cards')` (`INSTALLMENT_VAULT_CARDS`, default off) makes the down payment ask PayMongo to vault the card. Turn it on only after PayMongo has enabled vaulting.

## Going live checklist

1. PayMongo has enabled card vaulting + Subscriptions (above) and approved the live account.
2. `.env`: live `PAYMONGO_PUBLIC_KEY` / `PAYMONGO_SECRET_KEY` (`sk_live_`), `INSTALLMENT_GATEWAY=paymongo`, `INSTALLMENT_VAULT_CARDS=true`, `PAYMONGO_WEBHOOK_SECRET` of the live webhook.
3. Register `https://<site>/webhook/paymongo` in PayMongo's live dashboard (signature field `li=` is checked for `sk_live_` keys).
4. `php artisan migrate`, and the `schedule:run` cron line on the server.
5. Fill the shop values in `config/installments.php` (contact, hours, stop-auto-debit days, refund policy) and have the terms reviewed.

No schema or flow change is needed to switch: the "Sandbox: simulated charge" labels and the Simulate Due Date outcome choice disappear by themselves, and plans that were created with a simulated card fall back to Pay Now links (they are never charged for real).

If PayMongo answers the subscription questions above with a different model than "charge the vaulted card from our job", a second real driver implementing the same `InstallmentGateway` interface is the only code that changes.
