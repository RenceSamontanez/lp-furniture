# Stripe Checkout audit (Stage 14)

Real test-mode calls were made with `tests/Stripe/live_check.php` (scratch database only,
`sk_test_` key only, nothing paid, every session expired afterwards). "Live" below means
the live Stripe API accepted or rejected that exact value in that run.

| What we send | Our value | Stripe's limit | Verified |
|---|---|---|---|
| `expires_at` (custom orders) | earlier of the payment due date and now + 23h; never under now + 31 min; omitted when the due date is under 31 min away | must be LESS than 24 h after creation; documented minimum 30 min | Live: now + 24h − 1 min **rejected**, now + 24h rejected, now + 23h accepted, now + 31 min accepted. Live: 30 and 29 min were also accepted (we still use 31 min). This machine's clock was about 1 minute ahead of Stripe's, which is why the old "24h − 1 min" failed. |
| `expires_at` (catalog) | not sent (Stripe default, 24h) | default 24 h | Live: catalog sessions came back with exactly 24h00m. |
| `currency` | `php` (lowercase) | ISO code, lowercase accepted | Live: accepted in every run. |
| `unit_amount` | integer centavos `round(amount * 100)` | integer, smallest unit | Live: accepted in every run. |
| Minimum charge | PHP 40.00 (4000 centavos) | at least 50 US cents after conversion | Live: PHP 31.00 rejected, PHP 32.00 accepted (the rate moves, so we keep margin). |
| Maximum charge | 99,999,999 centavos (PHP 999,999.99) | documented maximum for PHP | Documentation / own knowledge. Live: 99,999,999 accepted; 100,000,000 was also accepted at session creation in test mode, so this is not enforced there. We keep the documented cap. |
| `product_data.name` | cut to 250 characters | documented 250 | Own knowledge. Live: 250 accepted, and 251 was accepted too, so 250 is conservative. |
| `metadata` | 2 keys (`order_id`, `user_id`), string values | up to 50 keys, key up to 40, value up to 500 characters | Live: key 40 / value 500 accepted; key 41 and value 501 rejected. 50 keys: own knowledge. |
| `success_url` / `cancel_url` | absolute (`route()` URLs) | must be a valid absolute URL | Live: a relative URL was rejected ("Not a valid URL"). The `{CHECKOUT_SESSION_ID}` template was accepted. |
| `payment_method_types` | `['card']` | valid value | Live: accepted. |
| `mode`, `quantity` | `payment`, `1` | valid | Live: accepted. |
| Reuse: retrieve a session | `retrieve()` then reuse only if `status = open`, amount matches and a URL exists | expired sessions can still be retrieved | Live: an expired session retrieved fine (`status=expired`, `url=null`), so it is replaced, not reused. |
| Live-mode HTTPS | not testable here | live mode needs https success/cancel URLs | Own knowledge: the production APP_URL must be https. |

Real shapes sent through the real `PaymentController` code and accepted by Stripe (test mode, expired afterwards):
catalog full, catalog 50% downpayment, custom full, custom deposit, custom deposit due in 4 months,
custom deposit due in 40 minutes, custom deposit due in 20 minutes.
