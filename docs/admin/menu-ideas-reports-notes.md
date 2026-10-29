# Admin menu, Ideas to Review, Reports and Analytics

Spec: `docs/admin/admin-menu-v1.html` (the Customers pages in that file are a separate, later task).

## Menu
- `resources/views/partials/admin-sidebar.blade.php`, styles `public/assets/css/admin-menu.css`, behaviour `public/assets/js/admin.js`.
- Seven groups, text only. The one icon allowed is the "Find a page" magnifier (a CSS background); the only svgs in the markup are the seven group chevrons.
- Every admin sees every item (the only gate is the `admin` middleware), so the groups are the same for everyone.
- Red counts come from the same queries as before (notifications, custom orders, customize requests, other services, refunds, identity, reviews, ideas). A collapsed group shows the sum of its counts.
- Group state is kept in `localStorage` key `lpAdminMenuShut` (read and written inside try/catch). The group holding the current page can't be collapsed.
- Phone drawer (<= 1000px): burger, close button, dim backdrop, Escape, focus in and back to the burger, Tab trapped, page behind locked.

## Ideas to Review
- `Admin\IdeasReviewController::index` renders every idea (up to 500) and the page filters in the browser (`admin-ideas-review.js`). The old `?status=`, `?kind=`, `?q=`, `?sort=newest` links set the starting view.
- **Where an idea came from is derived, not stored** (`App\Support\IdeaOrigins`): a furniture-type idea matches requests whose "Something else" text is the same, a wood idea matches the "Other wood" name, an idea phrase matches by being inside the description. One query per kind. A phrase match can be approximate. If an exact link is wanted later it needs a table recording idea id + request id when a request is submitted.
- Routes unchanged: `ideas.answer`, `ideas.option`, `ideas.dismiss`, `ideas.undo`, `ideas.delete`. The old Write Answer / Add as Option / Dismiss / Delete per-row windows are replaced by one View window, one Add as Option window, one Delete confirmation and a Help window (all the shared `lp-modal`).
- Send Reply = the old Write Answer (same route and validation). New server rule: leftover `[brackets]` are refused.
- Add as Option keeps the real behaviour: a furniture type becomes a "Something else" quick pick (name only), an idea phrase becomes a quick idea for a furniture type or a style, a wood can never be added (the wood list is fixed). There is no choice-list picker beyond that and no note field.
- A dismissed idea gets Restore Idea in its View window (the old Undo Dismiss button), and the Undo notice after Dismiss still appears.
- A refused save redirects back; the page embeds `reopen` (modal, idea id, typed values, errors) and the script opens the same window with the values and the message under the field.

## Reports and Analytics (`App\Support\AdminReports`)
- Kept from the old page: revenue = `total_amount` of non-cancelled orders by the day placed; money collected = `amount_paid` of those orders; catalog vs customized split on `design_agreement_id`; best sellers, category and low stock queries.
- Period switch (`?period=m|q`, `?tab=`): `m` = this month so far vs the whole of last month; `q` = the 3 calendar months up to now vs the 3 before.
- Cancelled Orders = cancelled / orders placed in the period (lower is better, so a drop is green). Luma Answered = messages in `chatbot_logs` whose `intent_detected` is not `unmatched`.
- Chip: percent for money and orders ("New" when the previous value is 0), plus or minus for counts, "pts" for rates.
- A "design" is a Customize request with at least one generated picture; "became a request" = sent to the team; "became a paid order" = an order points at it (`orders.design_request_id`), is not cancelled and has money received.
- Most used wood is derived: catalog orders by their product's `material`, customized orders by the request's wood. Orders with neither are left out.
- Orders by Status lists all seven real statuses (the prototype showed five) so the total matches the order count.
- Why Orders Get Cancelled: the customer's chosen reason (parsed from the stored cancel note), "Cancelled By The Shop" for an admin cancel, "Not Paid In Time" for the unpaid-order sweep.
- Unpaid Orders To Follow Up has no Send Reminder button: no reminder feature exists.

## Walk
`node --experimental-websocket tests/UiLinks/browser-admin-menu-ideas-reports.cjs [functional|sizes|shots|all]` (scratch DB; seeds: `tests/Rbac/seed_screenshots.php`, `tests/UiLinks/seed_ideas_admin.php`, `tests/UiLinks/seed_admin_menu.php`). Screenshots in `docs/admin/screenshots-menu/`.
