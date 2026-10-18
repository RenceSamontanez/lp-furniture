# Other Services terms: what the owner or a lawyer must confirm before launch

The page is `/services/terms` (`resources/views/services/terms.blade.php`). It is separate from the main Terms and Conditions (version 2), which were not edited, and it does not touch terms acceptance for furniture orders. The Other Services request form has a required checkbox and stores `terms_accepted_at` on the ticket (existing tickets are unaffected).

Everything below was decided with the owner and is written into the page and the code. None of it has been reviewed by a lawyer.

1. **No refund once work starts.** The page says the work is non-refundable once the laborer starts. Confirm the wording is enforceable for your customers and your area (consumer protection rules, distance selling rules).
2. **What counts as "started".** The system knows work has started only when an admin taps "Work started" (or "Service done"). Confirm the staff will tap it the moment the laborer begins, because the refund rules depend on it. A customer who asks for a refund a day after the laborer began, but before the admin tapped the button, would be offered a normal refund.
3. **Full refund.** If approved before work starts, the customer gets back the full amount paid. Confirm there is no deduction for a payment gateway fee (Stripe and PayMongo may not return their fee to you).
4. **Refund methods.** GCash or cash only, chosen by the customer, even if they paid by card. Confirm you accept a card payment being refunded outside the card.
5. **Seven days.** "Up to 7 days after approval." Confirm this is realistic and whether weekends and holidays count. The system highlights overdue refunds in the admin list.
6. **Wrong GCash number.** The page says the customer is asked to send the right number and that the 7 days start again. Confirm who bears the cost of money sent to a wrong number that the customer typed.
7. **Requests after work starts.** Reviewed individually, may be declined with a written reason, or approved as an exception. The exception option exists in the admin screen ("Approve as an exception", reason required, flagged and logged). The owner may ask for it to be removed.
8. **Cash refunds.** The customer picks the cash up from the shop. Confirm the collection rules (ID, who may collect, how long it is held) and whether a receipt is signed.
9. **Part payments.** The first half is also refunded in full if work has not started. Confirm that is what you want for the half-and-half plan.
10. **Nothing paid.** A customer who has paid nothing and whose work has not started is cancelled at once, with no approval. Confirm this is acceptable.
11. **Data.** The refund request stores the customer's phone, email and GCash number (encrypted at rest, shown masked in lists, never in logs). Confirm the privacy notice covers this and how long it is kept.
12. **Taxes.** This page is not tax advice. Refund and cancellation of an invoice affects your records after BIR registration; see `docs/service-invoices-to-confirm.md`.
