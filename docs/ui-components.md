# Shared UI components

Both live in `public/assets/style.css`, which is loaded by the storefront layout, the admin layout and Luma. Never write one-off button or notice colours: reuse these.

## Buttons: `.lp-btn`

`<a|button class="lp-btn lp-btn--primary|secondary|danger [lp-btn--chip] [lp-btn--block]">`

* **primary**: the single next step on the page (dark brown, cream text).
* **secondary**: everything else (white, brown border).
* **danger**: destructive or "decline" actions (white, terracotta border).
* **chip**: small pill for inline actions (the only variant allowed under 44px).
* Colour rules are written with two classes so they beat the site-wide link colour rules in every state: default, hover, focus, active, visited and disabled.
* Every button/background pair must measure at least 4.5:1 in the browser per state (disabled at least 3:1) and be at least 44px tall on a 390px screen. Check with `tests/ButtonContrast/measure.js` over every page you touch.

## Important notice: `.lp-notice` (Stage 21f)

For anything the reader must not miss ("Pay the rest after the service", "Cancellation requested. Payments are paused until we reply."). Never use plain body text for these.

```blade
<x-notice variant="waiting" title="Pay the rest after the service">
  The second half of ₱2,000.00 opens when we mark your service as done. We will notify you.
  <x-slot:actions><a href="..." class="lp-btn lp-btn--secondary">See request</a></x-slot:actions>   {{-- optional --}}
</x-notice>
```

Component file: `resources/views/components/notice.blade.php`. Props: `variant` (default `info`), `title`. The slot is the one short plain sentence. `actions` is an optional slot for buttons.

Design: a tinted background that fits the cream and brown palette, a 4px left accent bar, a 24px inline SVG icon chosen by variant, a bold title (16.5px, semibold) and the sentence under it (15px). Heavier and larger than body text, but calm: no red fill, no animation, no capitals. Full width, wraps cleanly at 390px, never clipped (`overflow-wrap: anywhere`). `role="status"` (variant `problem` uses `role="alert"`).

| Variant | Icon | Use for | Background | Accent bar | Title text | Body text |
|---|---|---|---|---|---|---|
| `info` | circle with "i" | general must-read information | `#FBF1DA` gold tint | `#C8963E` site gold | `#2C1810` | `#4A3426` |
| `waiting` | clock | something is pending, a next step is coming | `#FBF1DA` | `#C8963E` | `#2C1810` | `#4A3426` |
| `blocked` | lock | an action is closed or paused (cancelled, on hold) | `#EFE9E2` muted grey-brown | `#7A6A5E` | `#2C1810` | `#43382F` |
| `success` | check in circle | something finished well (refund sent) | `#E7F0E2` muted green | `#5B8A4E` | `#17301A` | `#29432A` |
| `problem` | warning triangle | something went wrong; use sparingly | `#F7E7E0` muted terracotta | `#B5543A` | `#3F170D` | `#5A2A1D` |

Colours are CSS variables (`--notice-*`) at the end of `style.css`. Measured contrast of every text/background pair is listed in the Stage 21f report and re-checked in the browser; the required minimum is 4.5:1.

### Where it is used

Customer: the half-and-half invoice page (second half waiting, "Half paid" lines, blocked/on-hold invoice), the cancelled invoice page (refund details), the cancellation form (work state, refund expectation), the confirmation page, and every status of the request page. Admin: ticket and invoice banners (Cancelled, On hold, Work started), the "payment on this service" prompt, and the request detail page.

## Confirmation modal: `.lp-modal` (Stage 21h)

One accessible modal replaces every native `confirm()` on risky Other Services actions (Start work, Mark service done, Record cash payment, Cancel invoice, Decline, Approve, Approve as exception, Mark refund as sent, Refund did not go through, Close ticket, Open a cancellation request, Delete draft, and the customer's cancellation or refund confirmation). Markup `resources/views/components/modal.blade.php`, behaviour `public/assets/js/lp-modal.js` (loaded by both layouts), styles at the end of `style.css`.

```blade
<button type="button" class="lp-btn lp-btn--primary" data-lp-modal-open="my-modal">Start work</button>
<x-modal id="my-modal" title="Start work on SI-2026-1001?" summary-form="my-form">   {{-- summary-form is optional --}}
  <p>The sentence the reader must see.</p>
  <x-notice variant="info" title="After this, the customer can no longer get an automatic refund."/>
  <x-slot:footer>
    <button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Go back</button>
    <button type="submit" form="my-form" class="lp-btn lp-btn--primary" data-lp-confirm>Start work</button>
  </x-slot:footer>
</x-modal>
```

Behaviour: backdrop, `role="dialog"` + `aria-modal="true"` + `aria-labelledby` the title, focus moves into the modal (to the secondary button, so the safe choice is focused) and is trapped with Tab/Shift+Tab, ESC and a click outside close it, focus returns to the button that opened it, the page behind does not scroll, the body scrolls inside the box on a 390px screen (the box is a bottom sheet there), buttons are `.lp-btn` and 44px tall on phones.

Hooks (all optional):

* `form[data-lp-gate="modalId"]` + a button with `data-lp-gate-submit="formId"`: the form's own validation runs first, then the modal opens; the confirm button submits the real form. `data-lp-final-action="url"` on the form changes where the confirmed form posts (the customer cancellation form posts to the review page without JavaScript and straight to the store route with it).
* `data-lp-summary-form="formId"` on the modal: `[data-summary="field"]` elements receive that field's value (select and radio show their label text), `[data-summary-if="field=value"]` elements show only when the field has that value (for example the GCash lines).
* `data-lp-requires="checkboxId"` on the confirm button: it stays disabled until the box is ticked. The modal un-ticks `[data-lp-reset]` boxes each time it opens.
* After the first confirm the modal is locked (`data-busy`): it cannot be closed and the button is disabled, so a request cannot be sent twice.

The server never relies on the modal: every route validates everything again (the modal-backed routes are the existing ones).

## Text links: `.lp-link` (Stage 21h)

For every inline text link in content: `<a href="..." class="lp-link">Other Services terms</a>`. Brown `#6A3E1E` (hover `#2C1810`), weight 600, a 1.5px gold underline with a 3px offset (3px thick on hover), the same look when visited, and a visible focus ring. Measured against cream `#F5F0E8`, white and the gold tint `#FBF1DA`: all four states are at least 4.5:1 (run `tests/UiLinks/measure-links.js` in the browser). `.lp-link--tap` makes a link its own 44px tap target (quiet row actions such as "Request cancellation or refund"). The rules are written with `:any-link` so they beat page-level link rules. Do not use it on buttons (`.lp-btn`), the main navigation, filter tiles or card-as-link elements.

`php tests/UiLinks/audit.php` lists every `<a>` in the Other Services, refund, invoice and notification views (customer and admin) that is neither a button nor an `.lp-link`, and exits 1 if any remain.

## Progress stepper: `.lp-stepper` (Stage 21h)

```blade
<x-stepper :steps="[['Sent','Sent'], ['Under review','Review'], ['Approved','Approved'], ['Refunded','Refunded']]" :current="2" variant="normal"/>
```

Steps are `[long label, short label]` (the short label shows at 480px and below). `variant`: `normal` (steps before `current` are done, `current` is emphasised), `problem` (the current step shows a warning icon: Refund problem), `stopped` (the current step shows an X: Declined), `complete` (every step done with a check). Equal-width columns, circle and label centred, the number or icon centred in the circle, the connector running between circle centres. `tests/UiLinks/stepper-align.js` measures all of this with `getBoundingClientRect` at 390px and 1280px.

## Half and half control, manual live check

The admin invoice form has no JavaScript test runner behind it, so the live behaviour is covered by markup assertions in `tests/Feature/HalfAndHalfRuleTest.php` plus this manual check: open an invoice form, type a service cost of 1,200: the box is disabled, unticked, and a notice says "Half and half needs a total of ₱3,000.00 or more. This invoice is ₱1,200.00."; change the cost to 3,000 or add a fee that takes the total there: the box enables with no reload and the notice reads "₱1,500.00 now and ₱1,500.00 when you tap "Service done"."; go back under 3,000: it unticks and disables again. Works the same when editing a draft.

## Luma, the chat assistant (Prompt 9a)

The chat widget has its own stylesheet, `public/assets/css/luma.css`, and its own tokens (`--lc-*`, scoped to `.chatbot-window` and `.chatbot-fab`, light and dark). It still uses the shared components:

* Buttons are `.lp-btn` (reply buttons, the send button, quick-reply chips with `.lp-btn--chip`). Inside the widget only, the `--btn-*` colours are re-pointed to Luma's palette, so the shared rules still decide every state, including `:visited`. Do not write a one-off button colour in the widget.
* Must-read messages are `.lp-notice` (the after-hours box uses the `waiting` variant), made smaller inside the chat. The refund form card keeps `.lp-link` and `.lp-notice`.
* Her face is a circle that shows the whole head (see `docs/luma-poses.md`, "Face fit"); the launcher and header avatar share one crop.
* Contrast, measured in a real browser (`tests/Luma/browser-9a.cjs`, light and dark, 390 and 1280): primary button 9.7:1 (hover 8.3, active 13.4), secondary 12.3:1, quick-reply chip 9.5:1 (hover 8.4, active 7.4), disabled 5.1:1, Luma bubble 16.3:1, customer bubble 9.7:1, muted text 6.2:1. Dark: primary 9.0:1, secondary 11.8:1, chip 9.9:1, disabled 6.1:1, bubbles 11.8:1 and 5.5:1.
* The focus ring is 3px `#B7791F` (`#F0C46A` in dark and on the dark header).

Poses, the hours pill, the after-hours notice and how to add a pose: `docs/luma-poses.md`.
