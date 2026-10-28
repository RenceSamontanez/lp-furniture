/*
 * Back button placement walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011).
 *  (a) phone wizard: for every screen, scroll the body to the bottom and assert nothing is under the footer, Back sits above the primary
 *      button (12px gap, same height, full width), at 320/360/390/430 wide and 568 high;
 *  (b) desktop/tablet: Back and Continue are 12px apart, equal height, tops within 1px, left aligned, at 1440/1001/800;
 *  (c) payment pages: exactly one page-level Back; (d) the GCash redirect screen: Back as wide and tall as Continue to GCash.
 * Screenshots go to docs/checkout/screenshots-back/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_checkout_steps.php      (customer steps@example.test, no phone)
 *   php tests/UiLinks/seed_back_buttons.php        (customer flow@example.test with phone + address, and two unpaid orders)
 *   (start the scratch server on :8011: tests/Rbac/serve_test.ps1)
 *   node --experimental-websocket tests/UiLinks/browser-back-buttons.cjs
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
const SCREENS = ['review', 'delivery', 'paytype', 'paymethod', 'notes', 'finalreview'];

(async () => {
  const c = await lib.start(9600 + Math.floor(Math.random() * 300));
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  try {
    const as = async (email) => c.setCookie(JSON.parse(php('session-cookie-for.php', email, 'web')));
    await as('steps@example.test');

    // ───────── (a) phone wizard ─────────
    for (const [w, h] of [[320, 700], [360, 800], [390, 844], [430, 932], [390, 568], [320, 568]]) {
      await c.viewport(w, h);
      await c.go('/checkout');
      for (const id of SCREENS) {
        const r = await c.evalJs(`(() => {
          document.getElementById('ckn-preparing').style.display = 'none'; document.querySelectorAll('.checkout-screen').forEach((s) => { s.classList.remove('open', 'ckn-active'); });
          const sc = document.getElementById('screen-${id}'); sc.style.transition = 'none'; sc.classList.add('open');
          const body = sc.querySelector('.checkout-screen-body'); body.scrollTop = body.scrollHeight;
          const footer = sc.querySelector('.checkout-screen-footer'), back = footer.querySelector('.checkout-screen-prev'), prim = footer.querySelector('.checkout-screen-continue');
          const f = footer.getBoundingClientRect(), b = back.getBoundingClientRect(), p = prim.getBoundingClientRect();
          let last = 0; body.querySelectorAll('*').forEach((el) => { const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || el.children.length) return; const r = el.getBoundingClientRect(); if (r.height > 0 && r.width > 0) last = Math.max(last, r.bottom); });
          return { footerTop: f.top, footerBottom: f.bottom, vh: innerHeight, gap: p.top - b.bottom, backH: b.height, primH: p.height, backW: b.width, primW: p.width, last,
                   backText: back.textContent.trim(), topBackrow: !!sc.querySelector('.checkout-screen-backrow'), hscroll: document.documentElement.scrollWidth > innerWidth, order: back.compareDocumentPosition(prim) & Node.DOCUMENT_POSITION_FOLLOWING ? 'back-first' : 'prim-first' };
        })()`);
        const tag = `phone ${w}x${h} ${id}`;
        ok(`${tag}: Back above primary, 12px gap`, r.order === 'back-first' && Math.abs(r.gap - 12) <= 1, r);
        ok(`${tag}: same height and width`, Math.abs(r.backH - r.primH) <= 1 && Math.abs(r.backW - r.primW) <= 1, r);
        ok(`${tag}: nothing under the footer (last content bottom <= footer top)`, r.last <= r.footerTop + 0.5, r);
        ok(`${tag}: footer inside the viewport, no top back row, no sideways scroll`, r.footerBottom <= r.vh + 0.5 && !r.topBackrow && !r.hscroll, r);
        if ((w === 360 || w === 390) && h > 700) { await c.sleep(150); await shot(`phone-${w}-${id}`); }
      }
    }

    // ───────── (b) desktop / tablet ─────────
    for (const w of [1440, 1001, 800]) {
      await c.viewport(w, 1000);
      await c.go('/checkout');
      for (const id of ['review', 'delivery', 'paytype', 'paymethod', 'notes']) {
        const r = await c.evalJs(`(async () => {
          const sc = document.getElementById('screen-${id}');
          if (!sc.classList.contains('ckn-active')) sc.querySelector('.ckn-row-header').click();
          await new Promise((r) => setTimeout(r, 700));
          const row = sc.querySelector('.ckn-actions'), bk = row.querySelector('.ckn-back-btn'), ct = row.querySelector('.ckn-continue-btn');
          row.scrollIntoView({ block: 'center' });
          const b = bk.getBoundingClientRect(), n = ct.getBoundingClientRect(), rw = row.getBoundingClientRect();
          return { gap: n.left - b.right, bh: b.height, nh: n.height, bt: b.top, nt: n.top, bb: b.bottom, nb: n.bottom, backLeft: b.left - rw.left, back: bk.textContent.trim(), cont: ct.textContent.trim() };
        })()`);
        const tag = `desktop ${w} ${id}`;
        ok(`${tag}: gap 12px`, Math.abs(r.gap - 12) <= 1, r);
        ok(`${tag}: equal height, tops and bottoms aligned`, Math.abs(r.bh - r.nh) <= 1 && Math.abs(r.bt - r.nt) <= 1 && Math.abs(r.bb - r.nb) <= 1, r);
        ok(`${tag}: left aligned`, r.backLeft <= 1, r);
        if (w !== 800) { await shot(`desktop-${w}-${id}`); }
      }
    }

    // ───────── (c) payment pages: one Back ─────────
    await as('flow@example.test');
    for (const [label, url] of [['stripe', `/payment/stripe/${orders.stripe}`], ['gcash', `/payment/gcash/paymongo/${orders.gcash}`]]) {
      for (const [w, h] of [[1440, 900], [390, 844]]) {
        await c.viewport(w, h);
        await c.go(url);
        const r = await c.evalJs(`(() => {
          const vis = (e) => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
          const backs = [...document.querySelectorAll('.lp-btn--back')].filter(vis);
          return { visible: backs.length, inDom: document.querySelectorAll('.lp-btn--back').length, path: location.pathname };
        })()`);
        ok(`${label} ${w}: exactly one visible page-level Back`, r.visible === 1, r);
        await shot(`${label}-pay-${w}`);
      }
    }

    // ───────── (d) GCash redirect screen ─────────
    for (const [w, h] of [[1440, 900], [390, 844]]) {
      await c.viewport(w, h);
      await c.go(`/payment/gcash/paymongo/${orders.gcash}`);
      await c.evalJs(`document.getElementById('pay-btn').click()`);
      await c.sleep(700);
      const r = await c.evalJs(`(() => {
        const cont = document.getElementById('gcash-continue-btn').getBoundingClientRect();
        const back = document.querySelector('#gcash-redirect-confirm .lp-btn--back').getBoundingClientRect();
        return { cw: cont.width, bw: back.width, ch: cont.height, bh: back.height, gap: back.top - cont.bottom, cl: cont.left, bl: back.left };
      })()`);
      ok(`redirect ${w}: Back as wide and tall as Continue, 12px gap`, Math.abs(r.cw - r.bw) <= 1 && Math.abs(r.ch - r.bh) <= 1 && Math.abs(r.gap - 12) <= 1 && Math.abs(r.cl - r.bl) <= 1, r);
      await shot(`gcash-redirect-${w}`);
    }
  } finally {
    c.close();
  }
  console.log(`\n${pass} passed, ${fail} failed`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
