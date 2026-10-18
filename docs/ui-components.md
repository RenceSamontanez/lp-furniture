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
