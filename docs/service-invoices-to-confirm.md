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
   invoice is issued. Registered receipts usually need a BIR-approved series. Confirm the series,
   the prefix and whether the yearly restart is allowed.
3. **Required details.** Registered receipts need items the invoice does not have today, such as
   the registered business name and address, TIN, VAT or non-VAT status, and the authorised
   printer details. Confirm the list and give it to the developer.
4. **Whether this invoice stays a separate document** once official receipts are issued.

## How invoices behave (for reference)

- Draft: editable. Issued: locked (lines, amounts, customer details and number can never change).
- Paid: the admin records method, reference, amount received and date. History is kept.
- Void: needs a reason. An issued invoice is never edited or deleted: void it and issue a new one.
- Every action writes a timestamped line in the ticket's admin notes.
- The customer sees issued and paid invoices (not drafts or void ones) under "My requests".
