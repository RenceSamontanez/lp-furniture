# Customer typography (spec: typography-specimen-v2.html, customer rows, Fonts = New)

- Scope: `<body class="lp-type">` in `layouts/app` and `layouts/focus`, plus `public/assets/css/customer-type.css` (loaded only by those two). `layouts/admin`, `admin.css` and `style.css` are untouched; admin pages are pixel-identical (walk: `tests/UiLinks/browser-customer-type.cjs admin before|after`).
- Fonts: self-hosted only (`public/assets/css/fonts.css`, files in `public/fonts`). Cormorant Garamond has one 600 file answering weights 400-700 and `size-adjust: 122%` (the specimen's --hs); Montserrat 400 answers 100-400, 500, 600 answers 600-900. No faux bold anywhere.
- Fallback faces `Montserrat Fallback` / `Cormorant Fallback` use size-adjust/ascent/descent overrides. The numbers are close estimates (not measured on every OS); tune them in fonts.css if a jump is visible.
- Pages choose the display font through `var(--font-display)` and the body font through `var(--font-body)`; `.lp-type` re-points both. Stylesheets shared with admin (`order-tracking.css`) are not edited; customer-type.css overrides them under `.lp-type`.
- Back controls: `<x-back-link :href label>` (or `type="button"` for checkout steps) = `.lp-btn.lp-btn--secondary.lp-btn--back`, 45px.
- Footer is a dark area: it keeps its light link colours (specimen's #5d4b37 would fail contrast), only font/underline-on-hover apply.
- No Privacy page/route exists yet (the register link is `#`).
