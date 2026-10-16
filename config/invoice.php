<?php

/**
 * Printed service invoices ("Other Services"). The one place to change the footer line.
 *
 * TO CONFIRM with the owner or accountant: after BIR registration the footer and the invoice
 * numbering must follow the registered receipt rules. See docs/service-invoices-to-confirm.md.
 */
return [
    'footer' => env('INVOICE_FOOTER', 'This is an invoice for services rendered. It is not an official receipt.'),
];
