# RBAC matrix (Stage 11)

108 routes x 4 actors = 432 probes, plus 166 owner-route probes, 0 failures. Database: furniture_db_test

Expected: guest, customer and customer-credentials-on-admin-guard are redirected to /admin/login; admin gets anything except that redirect, 401, 403, 419 or 5xx. Routes with an id were sent id 999999999 only. One fresh PHP process per actor per route.

Stage 21c: no routes were added. "Send to customer" from the edit page is the existing `POST /admin/service-invoices/{id}` (update) with `then=send`, so that row covers it; the feature test `test_save_and_send_is_admin_only` also probes it with the send flag.

Stage 21d: the customer invoice page `GET /services/invoices/{id}` is owner-only and is probed in the last section of this file. A cancelled invoice now answers its owner with a 200 explanation page; another customer, a draft and an unknown id are all 404; a guest is sent to /login.

| Route | guest | customer (web guard) | customer creds on admin guard | admin |
|---|---|---|---|---|
| `GET /admin/agreements/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/agreements/999999999/image` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/agreements/999999999/print` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/chatbot` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/chatbot` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `DELETE /admin/chatbot/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `PUT /admin/chatbot/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/chatbot/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/chatbot/999999999/toggle` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/chatbot/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/custom-orders` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/custom-orders/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/custom-orders/999999999/call-update` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/custom-orders/999999999/calls` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/custom-orders/999999999/decline` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/custom-orders/999999999/furniture-type` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/custom-orders/999999999/quote-preview` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/custom-orders/999999999/quotes` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/custom-orders/999999999/quotes/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/custom-orders/999999999/quotes/999999999/confirm` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/custom-orders/999999999/quotes/999999999/delete` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/custom-orders/999999999/quotes/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/custom-orders/999999999/quotes/999999999/send` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/custom-orders/999999999/quotes/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/custom-orders/999999999/unreachable` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/customers` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/customers/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/dashboard` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/design-requests` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/design-requests/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/design-requests/999999999/contacted` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/design-requests/999999999/reject` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/employees` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/employees` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `DELETE /admin/employees/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `PUT /admin/employees/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/employees/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/employees/999999999/status` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/employees/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/installments` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/installments/999999999/paid` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/inventory` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/inventory` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `DELETE /admin/inventory/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `PUT /admin/inventory/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/inventory/999999999/adjust` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/inventory/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/inventory/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/login` | 200 (public page) ✓ | 200 (public page) ✓ | 200 (public page) ✓ | 302 → /admin/dashboard (public page) ✓ |
| `POST /admin/login` | 302 →  (public page) ✓ | 302 →  (public page) ✓ | 302 →  (public page) ✓ | 302 →  (public page) ✓ |
| `POST /admin/logout` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ |
| `GET /admin/notifications` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/notifications/999999999/read` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/notifications/read-all` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/orders` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/orders/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/orders/999999999/payments` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/orders/999999999/review` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/orders/999999999/status` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/products` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/products` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `DELETE /admin/products/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `PUT /admin/products/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/products/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/products/999999999/stock` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/products/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/refund-requests` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/refund-requests/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/refund-requests/999999999/approve` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/refund-requests/999999999/approve-exception` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/refund-requests/999999999/ask-info` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/refund-requests/999999999/decline` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/refund-requests/999999999/mark-refunded` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/refund-requests/999999999/refund-failed` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/reports` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/reviews` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/reviews/999999999/approve` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/reviews/999999999/reject` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-inquiries` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `POST /admin/service-inquiries` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/service-inquiries/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-inquiries/999999999/call-update` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/service-inquiries/999999999/close` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/service-inquiries/999999999/invoices` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-inquiries/999999999/invoices/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-inquiries/999999999/notes` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `POST /admin/service-inquiries/999999999/refund-request` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-inquiries/999999999/work-started` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-inquiries/999999999/work-started/undo` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-inquiries/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/service-inquiries/customer-search` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/service-invoices/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/cash` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/delete` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-invoices/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/issue` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/paid` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-invoices/999999999/print` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/service-done` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/void` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/settings/contact` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `PUT /admin/settings/contact` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/validations` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/validations/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/validations/999999999/approve` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/validations/999999999/document/id` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/validations/999999999/reject` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |

## Owner-only customer routes: `/services/invoices/{id}` and what hangs off it

Not /admin routes. Each cell is one fresh PHP process. Expected: a guest, and an admin signed in only on the admin guard, are redirected to /login; another customer gets 404 for every id (so a pay attempt reveals nothing); a draft or an unknown id is 404 for the owner too; for a cancelled invoice the owner gets the 200 explanation page on the show route and is redirected to it from print, the return pages and pay (no payment can start). Stage 21e: the owner paying a SENT invoice is deliberately not probed here, because that would call the real Stripe or PayMongo API from this process; it is covered by PHPUnit with a fake gateway.

| Route | Invoice | guest | owner | another customer | admin (admin guard only) |
|---|---|---|---|---|---|
| `GET /services/invoices/{id}` | cancelled | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}` | sent | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/print` | cancelled | 302 → /login ✓ | 302 → /services/invoices/163 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/print` | sent | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/print` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/print` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/success` | cancelled | 302 → /login ✓ | 302 → /services/invoices/163 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/success` | sent | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/success` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/success` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/cancelled` | cancelled | 302 → /login ✓ | 302 → /services/invoices/163 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/cancelled` | sent | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/cancelled` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/cancelled` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/failed` | cancelled | 302 → /login ✓ | 302 → /services/invoices/163 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/failed` | sent | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/failed` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/invoices/{id}/payment/failed` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/stripe` | cancelled | 302 → /login ✓ | 302 → /services/invoices/163 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/stripe` | sent | 302 → /login ✓ | not probed (PHPUnit) | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/stripe` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/stripe` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/gcash` | cancelled | 302 → /login ✓ | 302 → /services/invoices/163 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/gcash` | sent | 302 → /login ✓ | not probed (PHPUnit) | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/gcash` | draft | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/invoices/{id}/pay/gcash` | missing | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |

## Stage 21f: owner-only cancellation and refund routes

The request form, summary, confirm, confirmation page, request page and reply route belong to the ticket's owner. Expected: a guest, and an admin signed in only on the admin guard, are redirected to /login; another customer gets 404 for every record (so nothing reveals whether a request exists); the owner reaches the record, and the owner's empty POSTs are refused by validation or by the request status and change nothing. The new /admin routes (Cancellations & Refunds, the actions, opening a request on a customer's behalf, Work started) are in the table above.

| Route | Record | guest | owner | another customer | admin (admin guard only) |
|---|---|---|---|---|---|
| `GET /services/requests/{x}/cancellation` | exists | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/requests/{x}/cancellation` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/requests/{x}/cancellation/review` | exists | 302 → /login ✓ | 302 →  ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/requests/{x}/cancellation/review` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/requests/{x}/cancellation` | exists | 302 → /login ✓ | 302 → /services/requests/223/cancellation ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/requests/{x}/cancellation` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/refunds/{x}` | exists | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/refunds/{x}` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/refunds/{x}/sent` | exists | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /services/refunds/{x}/sent` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/refunds/{x}/reply` | exists | 302 → /login ✓ | 302 → /services/refunds/REF-2026-0001 ✓ | 404 ✓ | 302 → /login ✓ |
| `POST /services/refunds/{x}/reply` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |

## Stage 21h: owner-only notification details

`GET /notifications/{id}` shows a read-only details page for an Other Services notification. Expected: a guest, and an admin signed in only on the admin guard, are redirected to /login; another customer gets 404 (and does not mark it read); the owner gets 200 and the notification is marked read. No new /admin routes were added in Stage 21h: the Start work, Mark service done, Approve, Decline, Mark refund as sent and related modals post to the existing routes in the table above, and the 'Ready to start' filter is a query string on `GET /admin/service-inquiries`.

| Route | Record | guest | owner | another customer | admin (admin guard only) |
|---|---|---|---|---|---|
| `GET /notifications/{id}` | exists | 302 → /login ✓ | 200 ✓ | 404 ✓ | 302 → /login ✓ |
| `GET /notifications/{id}` | does not exist | 302 → /login ✓ | 404 ✓ | 404 ✓ | 302 → /login ✓ |
