/*
 * Back button FUNCTIONAL walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011): real taps (phone) and real mouse clicks (desktop).
 * Every Back goes to the right earlier step or page; Next/Continue still advance; the early-validation warning, the ready glow and
 * Place Order still work; the payment buttons submit their form (the form submit is intercepted in the page, so no gateway is called:
 * the gateway side is covered by the PHPUnit tests, which fake Stripe and PayMongo); the GCash redirect screen's Continue and Back work.
 * Screenshots (real flow, scrolled to the bottom of each phone screen) go to docs/checkout/screenshots-back/.
 *
 *   needs the same seeds as browser-back-buttons.cjs; node --experimental-websocket tests/UiLinks/browser-back-functional.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'checkout', 'screenshots-back');
fs.mkdirSync(OUT, { recursive: true });
const php = (f, ...a) => execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', f), ...a], { cwd: lib.ROOT, encoding: 'utf8' }).trim();
const orders = JSON.parse(php('seed_back_buttons.php').split('\n').pop());
let pass = 0, fail = 0;
const ok = (name, cond, detail) => { cond ? pass++ : fail++; console.log(cond ? 'PASS' : 'FAIL', name, cond || detail === undefined ? '' : JSON.stringify(detail)); };
const IDS = ['review', 'delivery', 'paytype', 'paymethod', 'notes', 'finalreview'];

(async () => {
  const c = await lib.start(9700 + Math.floor(Math.random() * 200));
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const as = async (email) => c.setCookie(JSON.parse(php('session-cookie-for.php', email, 'web')));
  const center = (sel) => c.evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; e.scrollIntoView({ block: 'center' }); const r = e.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, w: r.width }; })()`);
  const mouse = async (sel) => {
    await center(sel); await c.sleep(450);   // let the accordion finish moving before aiming
    const p = await center(sel); if (!p) throw new Error('missing ' + sel);
    await c.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: p.x, y: p.y });
    await c.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: p.x, y: p.y, button: 'left', clickCount: 1 });
    await c.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: p.x, y: p.y, button: 'left', clickCount: 1 });
    await c.sleep(550);
  };
  const tap = async (sel) => { const p = await center(sel); if (!p) throw new Error('missing ' + sel); await c.tap(p.x, p.y); await c.sleep(600); };
  const openScreen = () => c.evalJs(`(() => { const o = [...document.querySelectorAll('.checkout-screen.open')].map((s) => s.id.replace('screen-', '')); return o.join(','); })()`);
  const path_ = () => c.evalJs('location.pathname');
  const waitPath = async (re) => { for (let i = 0; i < 40; i++) { if (re.test(await path_())) return true; await c.sleep(250); } return false; };
  const active = () => c.evalJs(`[...document.querySelectorAll('.checkout-screen.ckn-active')].map((s) => s.id.replace('screen-', '')).join(',')`);
  const settle = async (get, want) => { for (let i = 0; i < 16; i++) { if ((await get()) === want) return want; await c.sleep(150); } return get(); };
  const hookSubmit = () => c.evalJs(`(() => { window.__subs = []; const rec = (f) => window.__subs.push({ action: f.getAttribute('action') || f.action, method: (f.method || '').toUpperCase() });
    const orig = HTMLFormElement.prototype.submit; HTMLFormElement.prototype.submit = function () { rec(this); };
    document.addEventListener('submit', (e) => { rec(e.target); e.preventDefault(); }, true); return true; })()`);

  try {
    // ───────── phone wizard: forward, backward, place order ─────────
    for (const [w, h] of [[360, 800], [390, 844]]) {
      await as('flow@example.test'); php('seed_back_buttons.php', 'cart');
      await c.viewport(w, h);
      await c.go('/checkout');
      await c.sleep(1800);
      await tap('.ckn-preparing-next');
      for (let i = 0; i < IDS.length; i++) {
        const id = IDS[i];
        const now = await settle(openScreen, id); ok(`phone ${w}: screen ${id} is open`, now === id, now);
        await c.evalJs(`(() => { const b = document.querySelector('#screen-${id} .checkout-screen-body'); b.scrollTop = b.scrollHeight; })()`);
        await c.sleep(150); await shot(`flow-phone-${w}-${id}`);
        if (i < IDS.length - 1) { await tap(`#screen-${id} .checkout-screen-footer .checkout-screen-continue`); }
      }
      // Back chain from the last screen down to Review, then Back to Cart
      for (let i = IDS.length - 1; i > 0; i--) {
        await tap(`#screen-${IDS[i]} .checkout-screen-footer .checkout-screen-prev`);
        const bk = await settle(openScreen, IDS[i - 1]); ok(`phone ${w}: Back from ${IDS[i]} goes to ${IDS[i - 1]}`, bk === IDS[i - 1], bk);
      }
      // forward once more to Place Order (the glow is desktop-only; Place Order must still create the order)
      for (let i = 0; i < IDS.length - 1; i++) { await tap(`#screen-${IDS[i]} .checkout-screen-footer .checkout-screen-continue`); }
      await tap('#screen-finalreview .checkout-place-order-btn');
      ok(`phone ${w}: Place Order creates the order and leaves checkout`, await waitPath(/^\/(payment|orders)\//), await path_());
      await c.go('/checkout'); // an empty cart now bounces; only Back to Cart is tested on a fresh cart below
      php('seed_back_buttons.php', 'cart');
      await c.go('/checkout'); await c.sleep(1800); await tap('.ckn-preparing-next');
      await tap('#screen-review .checkout-screen-footer .checkout-screen-prev');
      ok(`phone ${w}: Back to Cart from Review lands on /cart`, await waitPath(/^\/cart/), await path_());
    }

    // phone: early validation warning (account without a phone) and the keyboard rule
    await as('steps@example.test');
    await c.viewport(390, 844);
    await c.go('/checkout'); await c.sleep(1800); await tap('.ckn-preparing-next');
    await c.evalJs('goToReview()'); await c.sleep(500);
    await tap('#screen-finalreview .checkout-place-order-btn');
    const warn = await c.evalJs(`({ toast: !!document.querySelector('.lp-toast'), open: [...document.querySelectorAll('.checkout-screen.open')].map((s) => s.id).join(','), tag: !!document.querySelector('#screen-delivery .ckn-need') })`);
    ok('phone: Place Order with missing details shows the warning, opens Delivery Option, tags it', warn.toast && /delivery/.test(warn.open) && warn.tag, warn);
    const kb = await c.evalJs(`(() => { const f = document.querySelector('#screen-delivery input[type=text], #screen-delivery input:not([type])'); f.focus(); const sc = document.getElementById('screen-delivery');
      return new Promise((r) => setTimeout(() => r({ kb: sc.classList.contains('ck-kb'), backHidden: getComputedStyle(sc.querySelector('.checkout-screen-prev')).display === 'none', primaryShown: getComputedStyle(sc.querySelector('.checkout-screen-continue')).display !== 'none' }), 150)); })()`);
    ok('phone: focusing a text field hides only the stacked Back (primary stays)', kb.kb && kb.backHidden && kb.primaryShown, kb);
    await c.evalJs('document.activeElement.blur()'); await c.sleep(250);
    ok('phone: Back returns after the field loses focus', await c.evalJs(`getComputedStyle(document.querySelector('#screen-delivery .checkout-screen-prev')).display !== 'none'`));

    // ───────── desktop accordion ─────────
    for (const w of [1440, 1001]) {
      await as('flow@example.test'); php('seed_back_buttons.php', 'cart');
      await c.viewport(w, 1000);
      await c.go('/checkout');
      const D = ['review', 'delivery', 'paytype', 'paymethod', 'notes'];
      if ((await active()) !== 'review') { await mouse('#screen-review .ckn-row-header'); }
      for (let i = 0; i < D.length - 1; i++) {
        await mouse(`#screen-${D[i]} .ckn-continue-btn`);
        const a = await settle(active, D[i + 1]); ok(`desktop ${w}: Continue on ${D[i]} opens ${D[i + 1]}`, a === D[i + 1], a);
      }
      for (let i = D.length - 1; i > 0; i--) {
        await mouse(`#screen-${D[i]} .ckn-back-btn`);
        const a = await settle(active, D[i - 1]); ok(`desktop ${w}: Back on ${D[i]} opens ${D[i - 1]}`, a === D[i - 1], a);
      }
      for (let i = 0; i < D.length - 1; i++) { await mouse(`#screen-${D[i]} .ckn-continue-btn`); }
      await mouse('#screen-notes .ckn-continue-btn');
      const rdy = await c.evalJs(`({ cls: document.getElementById('checkout-summary').className, tag: document.getElementById('ckn-ready-tag').textContent, active: [...document.querySelectorAll('.checkout-screen.ckn-active')].map((s) => s.id).join(',') })`);
      ok(`desktop ${w}: Continue to Place Order sets the ready state (glow + tag)`, /is-ready/.test(rdy.cls) && /ready/.test(rdy.tag), rdy);
      await shot(`flow-desktop-${w}-ready`);
      await mouse('#place-order-btn');
      ok(`desktop ${w}: Place Order creates the order`, await waitPath(/^\/(payment|orders)\//), await path_());
      if (w === 1440) {
        php('seed_back_buttons.php', 'cart');
        await c.go('/checkout');
        if ((await active()) !== 'review') { await mouse('#screen-review .ckn-row-header'); }
        await mouse('#screen-review .ckn-back-btn');
        ok('desktop: Back to Cart on step 1 lands on /cart', await waitPath(/^\/cart/), await path_());
      }
    }
    // desktop early warning
    await as('steps@example.test');
    await c.viewport(1440, 1000);
    await c.go('/checkout');
    await mouse('#place-order-btn');
    const dw = await c.evalJs(`({ toast: !!document.querySelector('.lp-toast'), tag: !!document.querySelector('#screen-delivery .ckn-need'), active: document.querySelector('.checkout-screen.ckn-active')?.id })`);
    ok('desktop: Place Order with missing details shows the warning and opens Delivery Option', dw.toast && dw.tag && dw.active === 'screen-delivery', dw);

    // ───────── payment pages ─────────
    await as('flow@example.test');
    for (const w of [1440, 390]) {
      await c.viewport(w, 900);
      const phone = w < 700;
      const click = phone ? tap : mouse;
      // Stripe: the one Back goes to the order; the pay button submits its form to the checkout route
      await c.go(`/payment/stripe/${orders.stripe}`);
      await click('.payment-container .lp-btn--back');
      ok(`stripe ${w}: Back goes to the order page`, await waitPath(new RegExp(`^/orders/${orders.stripe}$`)), await path_());
      await c.go(`/payment/stripe/${orders.stripe}`);
      await hookSubmit();
      await click('.btn-pay-stripe');
      const s = await c.evalJs('window.__subs');
      ok(`stripe ${w}: Pay button submits the checkout form`, s.length === 1 && s[0].method === 'POST' && s[0].action.includes(`/payment/stripe/${orders.stripe}/checkout`), s);
      // GCash: Back, redirect screen Back, redirect screen Continue
      await c.go(`/payment/gcash/paymongo/${orders.gcash}`);
      await click('.payment-container .lp-btn--back');
      ok(`gcash ${w}: Back goes to the order page`, await waitPath(new RegExp(`^/orders/${orders.gcash}$`)), await path_());
      await c.go(`/payment/gcash/paymongo/${orders.gcash}`);
      await click('#pay-btn');
      ok(`gcash ${w}: Pay opens the redirect screen`, await c.evalJs(`document.getElementById('gcash-redirect-overlay').classList.contains('visible')`));
      await click('#gcash-redirect-confirm .lp-btn--back');
      ok(`gcash ${w}: redirect-screen Back goes to the order page`, await waitPath(new RegExp(`^/orders/${orders.gcash}$`)), await path_());
      await c.go(`/payment/gcash/paymongo/${orders.gcash}`);
      await hookSubmit();
      await click('#pay-btn');
      await click('#gcash-continue-btn');
      await c.sleep(700);
      const g = await c.evalJs('window.__subs');
      ok(`gcash ${w}: Continue to GCash submits the checkout form`, g.length >= 1 && g[0].method === 'POST' && g[0].action.includes(`/paymongo/${orders.gcash}/checkout`), g);
    }
  } finally {
    c.close();
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
