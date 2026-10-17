# RBAC matrix (Stage 11)

95 routes x 4 actors = 380 probes, 0 failures. Database: furniture_db_test

Expected: guest, customer and customer-credentials-on-admin-guard are redirected to /admin/login; admin gets anything except that redirect, 401, 403, 419 or 5xx. Routes with an id were sent id 999999999 only. One fresh PHP process per actor per route.

Stage 21c: no routes were added. "Send to customer" from the edit page is the existing `POST /admin/service-invoices/{id}` (update) with `then=send`, so that row covers it; the feature test `test_save_and_send_is_admin_only` also probes it with the send flag.

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
| `GET /admin/service-inquiries/create` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/service-inquiries/customer-search` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/service-invoices/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/delete` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-invoices/999999999/edit` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/issue` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/paid` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/service-invoices/999999999/print` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/service-invoices/999999999/void` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/settings/contact` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `PUT /admin/settings/contact` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
| `GET /admin/validations` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 200 ✓ |
| `GET /admin/validations/999999999` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/validations/999999999/approve` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `GET /admin/validations/999999999/document/id` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 404 ✓ |
| `POST /admin/validations/999999999/reject` | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 → /admin/login ✓ | 302 →  ✓ |
