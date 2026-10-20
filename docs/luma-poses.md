# Luma's poses and look

Front end only. The look is copied from the approved prototype, `docs/luma/luma-mockup-template.html`. Nothing here changes
the chatbot's intents, replies, refund flow, payments, routes or the database.

## Where things live

| What | Where |
| --- | --- |
| Markup (launcher, panel, header, stage, input) | `resources/views/layouts/app.blade.php` ("CHATBOT (Luma)") |
| The nav item that brings her back ("Assistant") | `resources/views/partials/luma-nav-item.blade.php` (included for guests and customers) |
| Styles and design tokens | `public/assets/css/luma.css` (loaded after `style.css`) |
| Pose engine, hours pill, after-hours notice, thinking bubble | `public/assets/main.js`, section 2 (`LUMA_POSES`, `lumaSetPose`, `lumaShopClosed`, ...) |
| Pose images (500x500 transparent PNG) | `public/images/luma/luma-{greeting,waiting,asked,working,smiling,wondering}.png` |
| Originals (untouched) | `docs/luma/luma-reference-*.png` |

### Tokens

All colours are `--lc-*` custom properties declared on `.chatbot-window, .chatbot-fab` at the top of `luma.css`, with a
`prefers-color-scheme: dark` block. They are not global. The shared button colours `--btn-*` are re-pointed to Luma's palette
inside the widget only, so every `.lp-btn` keeps the shared two-class state rules (default, visited, hover, focus, active,
disabled). Fonts: Fraunces for her name and form titles, Figtree for everything else (added to the Google Fonts request the
layout already makes, so no new third-party host).

## State to pose

| Widget state (existing code) | Pose | Notes |
| --- | --- | --- |
| Panel just opened (first time or again before the first question) | `greeting` | after 2.6 s it settles to `waiting` |
| Idle (2.6 s after opening, 2.5 s after the last key, 9 s after an answer) | `waiting` | |
| Customer is typing, or has just sent | `asked` | the `input` event, then `sendChat()` |
| Waiting for the reply | `working` | the "Luma is thinking" line is in the log meanwhile |
| Answered | `smiling` | |
| Clarifying question or fallback (see below) | `wondering` | |

"Clarifying question or fallback" is detected from the reply text only, because the chat endpoint does not return the
matched intent: the reply contains a `?`, or starts with the fallback sentence ("I couldn't quite find an answer" /
"Pasensya na po, hindi ko po masagot"). If the fallback wording in `config/luma.php` or `Luma::fallback()` is ever changed,
update the pattern in `sendChat()`. A failed request also shows `wondering`.

The stage shrinks from 150 px to 76 px (`.is-compact`) once the first customer message is sent.

## Face fit (launcher and header avatar)

The circles show her whole head. The crop is a 330 px square of the 500 px image centred on the head (x 242, y 162), which is
`width/height 151.5%`, `left -23.3%`, `top 3.4%` of the circle. It was derived from the real alpha bounding box of the smiling
pose (x 109-378, y 39-484; the head is roughly x 110-378, y 40-285) and checked at 1x and 2x in
`docs/stage9a-screenshots/zoom-*-2x.png`. Both circles always use the smiling pose, so one crop fits both.

## Standing on the platform

Every pose has a different alpha bounding box, so `LUMA_POSES[name].bbox = [x0, x1, y0, y1]` is used to translate the image
so her feet (bbox bottom) land on the same baseline (y 484 of 500) and she is centred horizontally. The gold platform is the
`.luma-pic::before` ellipse. Poses fade over 200 ms; the float, glow and pulse animations are switched off under
`prefers-reduced-motion`.

## How to add a pose

1. Export a 500x500 PNG with a real alpha channel (no black or white background) and save it as
   `public/images/luma/luma-<name>.png`. Keep the original in `docs/luma/`.
2. Measure its alpha bounding box (pixels with alpha above about 40) and add
   `<name>: { bbox: [x0, x1, y0, y1], say: { en: '...', tl: '...' } }` to `LUMA_POSES` in `main.js`.
3. Call `lumaSetPose('<name>')` where the state happens. Nothing else is needed: the baseline shift is computed from the bbox.
4. Add a row to the table above, then run `node --experimental-websocket tests/Luma/browser-9a.cjs` and look at the stage.

## After-hours notice

Business hours come from `config/luma.php` (`hours.open`, `hours.close`, `hours.days`, zone `Asia/Manila`), the same source the
chat's "are you open?" answer uses; the layout passes them to the page as `APP.lumaHours`. The Contact Settings page holds
phone, email and Facebook link only, not hours. `lumaShopClosed()` compares the browser's clock, read in Asia/Manila through
`Intl`, with those hours. When the panel opens and the shop is closed, the pill turns amber ("Shop closed, Luma is on") and one
extra Luma message is added once per session, in Tagalog when `window.lumaLang === 'tl'` (set from her earlier replies), else
English. It is not repeated on later replies, is not an intent and writes nothing to the database. With no `luma.hours`
configured the pill stays "Available 24/7" and no notice is shown. To test it, fake the browser clock before the page loads
(see `fakeClock()` in `tests/Luma/browser-9a.cjs`).

## Launcher

Her face in a gold ring, the frosted "Ask Luma" label for six seconds on a first visit (`localStorage` key
`lp_luma_tip_seen`), and a small X that hides her (`lp_chat_dismissed`, the same flag dragging her to the drop zone sets).
Once hidden, the **Assistant** link in the main nav brings her back. The X has a 44 px hit area around its 22 px disc.

## Proof

`node --experimental-websocket tests/Luma/browser-9a.cjs` (scratch app on port 8011, scratch database only) writes
`docs/stage9a-screenshots/` and audits every scene: shared button contrast (`tests/ButtonContrast/measure.js`), the contrast of
every visible text node against its real background, 44 px tap targets (quick-reply chips excepted, as before), and horizontal
overflow, in light and dark, at 390 and 1280 px. `tests/Luma/seed_9a.php` adds three sample chairs to the scratch database so
the product-card reply can be shown.
