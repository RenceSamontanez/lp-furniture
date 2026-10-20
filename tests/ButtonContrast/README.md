# Button contrast audit

`measure.js` lists every button and link-as-button on the page you run it on, with the measured WCAG contrast
for each state (default, visited, hover, focus, active, disabled) and the rendered height. Required: 4.5 (3 for
disabled buttons), and at least 44px high on a 390px screen.

Browsers can not report a colour "while hovered" from script, so each state is resolved by replaying the CSS cascade
(specificity, then order, following `inherit`, blending translucent backgrounds). It was cross-checked against real
`getComputedStyle` after a real hover on the 404 page buttons.

Run it: open the page in the browser (390px and 1280px), paste the file into the console (or the Browser pane's
javascript tool). It returns `{ count, failures, rows }`; `failures` must be empty.

## Nav and toast text contrast

`measure.js` checks buttons only. Text on the smart nav and the glass toasts sits on translucent glass over changing page content, so it is measured from real pixels by `tests/UiLinks/browser-nav.cjs` (section 4/9: text is made transparent, the page is screenshot, and each text colour is compared with the worst pixel behind it, in both the dark and light variants, over the hero photo, light pages, a dark photo under the light bar, the mobile menu and the admin). The same walk runs `measure.js` over both nav variants. Results go to `docs/stage-nav-screenshots/_checks.json` (`contrastMinByGroup`).
