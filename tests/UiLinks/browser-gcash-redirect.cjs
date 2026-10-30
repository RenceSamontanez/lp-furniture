/*
 * GCash redirect screen walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011): "Back" is as wide and tall as "Continue to GCash"
 * at 320, 360, 390, 430, 768, 1100 and 1440 px. Needs seed_checkout_steps.php and seed_back_buttons.php first.
 * Screenshots: docs/checkout/screenshots-back/gcash-redirect-<width>.png
 *   node --experimental-websocket tests/UiLinks/browser-gcash-redirect.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'checkout', 'screenshots-back');
fs.mkdirSync(OUT, { recursive: true });
const php = (f, ...a) => execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', f), ...a], { cwd: lib.ROOT, encoding: 'utf8' }).trim();
const orders = JSON.parse(php('seed_back_buttons.php').split('\n').pop());
const cookie = JSON.parse(php('session-cookie-for.php', 'flow@example.test', 'web'));
let fail = 0;

(async () => {
  const c = await lib.start(9500 + Math.floor(Math.random() * 300));
  try {
    for (const w of [320, 360, 390, 430, 768, 1100, 1440]) {
      await c.setCookie(cookie);
      await c.viewport(w, w < 700 ? 800 : 900);
      await c.go(`/payment/gcash/paymongo/${orders.gcash}`);
      await c.evalJs(`document.getElementById('pay-btn').click()`);
      await c.sleep(600);
      const r = await c.evalJs(`(() => {
        const cont = document.getElementById('gcash-continue-btn').getBoundingClientRect();
        const back = document.querySelector('#gcash-redirect-confirm .lp-btn--back').getBoundingClientRect();
        return { cw: cont.width, bw: back.width, ch: cont.height, bh: back.height, gap: back.top - cont.bottom, cl: cont.left, bl: back.left };
      })()`);
      const good = Math.abs(r.cw - r.bw) <= 1 && Math.abs(r.ch - r.bh) <= 1 && Math.abs(r.cl - r.bl) <= 1;
      if (!good) fail++;
      console.log(good ? 'PASS' : 'FAIL', w, JSON.stringify(r));
      const s = await c.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(OUT, `gcash-redirect-${w}.png`), Buffer.from(s.data, 'base64'));
    }
  } finally { c.close(); }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
