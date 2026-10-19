# Other Services invoices: TO CONFIRM with the owner

The invoice for an Other Services ticket (labor only, repairs, anything priced by phone) is a
plain invoice. It is **not** an official receipt, and it is not part of the order, payment
ledger, Stripe or PayMongo code.

## TO CONFIRM (owner or accountant)

After the business is registered with the BIR, the following must follow the registered receipt
rules. The owner or accountant must confirm each one before the invoices are used as receipts:

1. **Footer wording.** The footer line currently reads "This is an invoice for services rendered.
   It is not an official receipt." Change it in one place only: `config/invoice.php` (or set
   `INVOICE_FOOTER` in `.env`). Do not edit the print view.
2. **Numbering.** Invoices are numbered `INV-YYYY-NNNN`, restarting each year, given when the
   invoice is sent. Registered receipts usually need a BIR-approved series. Confirm the series,
   the prefix and whether the yearly restart is allowed.
3. **Required details.** Registered receipts need items the invoice does not have today, such as
   the registered business name and address, TIN, VAT or non-VAT status, and the authorised
   printer details. Confirm the list and give it to the developer.
4. **Whether this invoice stays a separate document** once official receipts exist.

## How invoices behave (for reference)

- Draft: editable, as many times as needed, and can be deleted (drafts have no number). Sent: locked (lines, amounts, customer details and number can never change).
- Paid: the admin records method, reference, amount received and date. History is kept.
- Cancelled: needs a reason. A sent invoice is never edited or deleted: cancel it and send a new one.
- Every action writes a timestamped line in the ticket's admin notes.
- The customer sees sent and paid invoices (not drafts or cancelled ones) under "My requests".

## Online payments (Stage 21e): defaults to confirm

Set in one place, `App\Support\InvoicePaymentRules`:

|---|---|
| Half and half, all service types (Labor only, Repair or restoration, Something else, any future type) | allowed from an invoice total of 3,000 pesos (rule changed in Stage 21h; below it, full payment only) |
| Half and half available from an invoice total of | 3,000 pesos |
| Minimum online payment (GCash or card) | 40 pesos (Stripe's minimum) |
| Second half becomes payable | when the admin taps "Service done" |

Before going live, fill `STRIPE_WEBHOOK_SECRET` and `PAYMONGO_WEBHOOK_SECRET` in the server `.env`.
Both webhooks reject every request while their secret is empty, so no online payment can be confirmed until they are set.
Refunds are never done by the system: record a refund note, then refund in the Stripe dashboard, GCash or cash.
