/*
 * Cancel Order dialog walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011): the dialog is mounted on <body>, sits above the navbar, Luma and the
 * notification dropdown, is fully visible and clickable at 1440 / 1001 / 390 with the order page scrolled to the top and down, closes with
 * the x, Escape and the backdrop, traps focus, returns focus to the Cancel Order button, shows a loading button and an in-dialog error on a
 * failed request, and finally cancels order 2 for real. Screenshots: docs/cancel-dialog/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_order_tracking.php        (order 2 = unpaid)
 *   (start the scratch server on :8011: tests/Rbac/serve_test.ps1)
 *   node --experimental-websocket tests/UiLinks/browser-cancel-dialog.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'cancel-dialog');
fs.mkdirSync(OUT, { recursive: true });
const cookieFor = (email, guard) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, guard], { cwd: lib.ROOT, encoding: 'utf8' }));
let pass = 0, fail = 0;
const ok = (name, cond, detail) => { cond ? pass++ : fail++; console.log(cond ? 'PASS' : 'FAIL', name, cond || detail === undefined ? '' : JSON.stringify(detail)); };

(async () => {
  const c = await lib.start(9300 + Math.floor(Math.random() * 500));
  const E = (x) => c.evalJs(x);
  const snap = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const mouse = async (x, y) => {
    await c.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await c.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await c.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  };
  const key = async (k, code) => {
    await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code: code || k, windowsVirtualKeyCode: k === 'Escape' ? 27 : 9 });
    await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code: code || k });
  };
  // click by real coordinates of the element's centre (fails if something paints over it, which is the bug under test)
  const realClick = async (sel, scroll) => {
    const r = await E(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; if (${!!scroll}) e.scrollIntoView({ block: 'center' }); const b = e.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
    if (!r) throw new Error('missing ' + sel);
    await c.sleep(80);
    await mouse(r.x, r.y);
    await c.sleep(150);
  };
  // is the element's centre actually the top-most thing at that point (and inside the viewport)?
  const reachable = (sel) => E(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return 'missing'; const modal = e.closest('.lp-modal'); const inner = modal && modal.querySelector('.lp-modal__body'); if (inner && inner.contains(e)) e.scrollIntoView({ block: 'nearest' }); const b = e.getBoundingClientRect(); const x = b.left + b.width / 2, y = b.top + b.height / 2; if (x < 0 || y < 0 || x > innerWidth || y > innerHeight) return 'offscreen ' + Math.round(y) + '/' + innerHeight; const t = document.elementFromPoint(x, y); return t && (t === e || e.contains(t)) ? true : 'covered by ' + (t && (t.className || t.tagName)); })()`);
  const isOpen = () => E(`!document.getElementById('cancel-order-2').hidden`);

  try {
    await c.setCookie(cookieFor('ot-customer@example.test', 'web'));

    /* ── 2: the My Orders list has no Cancel Order button, only View Details ── */
    await c.viewport(1440, 900);
    await c.go('/orders');
    ok('list: no Cancel Order button or dialog', await E(`!/Cancel Order|Cancel This Order/.test(document.body.innerText) && !document.querySelector('[data-lp-modal-open]')`));
    ok('list: View Details is shown', await E(`document.body.innerText.includes('View Details')`));
    await snap('list-1440');

    /* ── 1: the dialog on the order view ── */
    for (const [w, h] of [[1440, 900], [1001, 800], [390, 844]]) {
      await c.viewport(w, h);
      for (const scroll of ['top', 'down']) {
        for (const extra of ['plain', 'luma', 'bell']) {
          if (extra === 'bell' && w < 1001) { continue; }
          const tag = `${w}-${scroll}-${extra}`;
          await c.go('/orders/2');
          if (extra === 'luma') { await realClick('#chatbot-fab-btn'); await c.sleep(500); }
          if (extra === 'bell') { await realClick('#sn-bell'); await c.sleep(500); }
          if (scroll === 'down') { await E(`window.scrollTo(0, document.body.scrollHeight)`); await c.sleep(250); }
          // open it with the real button on the order view
          await E(`(() => { const b = document.querySelector('[data-lp-modal-open=cancel-order-2]'); b.scrollIntoView({ block: 'center' }); window.__scrollBefore = window.scrollY; })()`);
          if (scroll === 'top') { await E(`window.scrollTo(0, 0)`); }
          await c.sleep(150);
          await E(`document.querySelector('[data-lp-modal-open=cancel-order-2]').click()`); // the page may hide the button under the bar at the top: open via click(), then check the dialog itself
          await c.sleep(300);
          ok(`${tag}: dialog open`, await isOpen());
          ok(`${tag}: mounted directly on <body>`, await E(`document.getElementById('cancel-order-2').parentNode === document.body`));
          ok(`${tag}: backdrop covers the whole screen`, await E(`(() => { const b = document.getElementById('cancel-order-2').getBoundingClientRect(); return b.left === 0 && b.top === 0 && Math.round(b.width) === innerWidth && Math.round(b.height) === innerHeight; })()`));
          // the topmost element at the navbar, Luma launcher and bell is the backdrop
          ok(`${tag}: backdrop is above the navbar, Luma launcher and bell`, await E(`['#site-nav', '#chatbot-fab-btn', '#sn-bell'].every(s => { const e = document.querySelector(s); if (!e) return true; const b = e.getBoundingClientRect(); if (!b.width) return true; const t = document.elementFromPoint(Math.min(innerWidth - 2, Math.max(2, b.left + b.width / 2)), Math.min(innerHeight - 2, Math.max(2, b.top + b.height / 2))); return !!t && t.closest('#cancel-order-2') !== null; })`));
          await realClick('#cancel-order-2 input[value=Other]');
          for (const [label, sel] of [['x', '#cancel-order-2 .lp-modal__x'], ['first reason', '#cancel-order-2 input[name=reason]'], ['Other box', '#cancel-order-2 textarea'], ['Keep Order', '#cancel-order-2 .lp-modal__foot .lp-btn--secondary'], ['Cancel Order', '#cancel-order-2 [data-oc-confirm]']]) {
            const r = await reachable(sel);
            ok(`${tag}: ${label} visible and clickable`, r === true, r);
          }
          ok(`${tag}: page behind does not scroll`, await E(`getComputedStyle(document.body).overflow === 'hidden' && getComputedStyle(document.documentElement).overflow === 'hidden'`));
          if (w === 1440 || w === 390 || extra !== 'plain') { await snap(tag); }
          // Escape closes, focus goes back to the Cancel Order button on the page
          await key('Escape');
          await c.sleep(200);
          ok(`${tag}: Escape closes it`, !(await isOpen()));
          ok(`${tag}: focus returned to the page's Cancel Order button`, await E(`document.activeElement === document.querySelector('[data-lp-modal-open=cancel-order-2]')`));
        }
      }
    }

    /* ── close paths, focus trap, loading and failure at 390 ── */
    await c.viewport(390, 844);
    await c.go('/orders/2');
    await E(`document.querySelector('[data-lp-modal-open=cancel-order-2]').click()`);
    await c.sleep(250);
    await realClick('#cancel-order-2 .lp-modal__x');
    ok('x closes it', !(await isOpen()));
    await E(`document.querySelector('[data-lp-modal-open=cancel-order-2]').click()`);
    await c.sleep(250);
    await mouse(195, 8); // backdrop above the box
    await c.sleep(200);
    ok('backdrop click closes it', !(await isOpen()));
    await E(`document.querySelector('[data-lp-modal-open=cancel-order-2]').click()`);
    await c.sleep(250);
    for (let i = 0; i < 14; i++) { await key('Tab'); }
    ok('focus stays inside the dialog after many Tabs', await E(`document.getElementById('cancel-order-2').contains(document.activeElement)`));

    // failed request: fake a 409 after a delay; the dialog must stay open, button loading + disabled meanwhile, then the error inside the dialog
    await E(`window.__realFetch = window.fetch; window.fetch = () => new Promise(r => setTimeout(() => r(new Response(JSON.stringify({ message: 'Test failure: this order cannot be cancelled.' }), { status: 409, headers: { 'Content-Type': 'application/json' } })), 900));`);
    await realClick('#cancel-order-2 input[value="Changed My Mind"]');
    await realClick('#cancel-order-2 [data-oc-confirm]');
    await c.sleep(300);
    const busy = await E(`(() => { const b = document.querySelector('#cancel-order-2 [data-oc-confirm]'); return { disabled: b.disabled, busy: b.getAttribute('aria-busy'), text: b.textContent.trim(), open: !document.getElementById('cancel-order-2').hidden }; })()`);
    ok('while sending: button disabled, busy, says Cancelling…, dialog still open', busy.disabled && busy.busy === 'true' && /Cancelling/.test(busy.text) && busy.open, busy);
    await snap('loading-390');
    await key('Escape'); await c.sleep(100);
    ok('while sending: Escape does not close it', await isOpen());
    await c.sleep(1100);
    const failed = await E(`(() => { const e = document.querySelector('#cancel-order-2 [data-oc-error]'); const b = document.querySelector('#cancel-order-2 [data-oc-confirm]'); return { shown: !e.hidden, text: e.textContent, open: !document.getElementById('cancel-order-2').hidden, enabled: !b.disabled }; })()`);
    ok('failure: stays open, error shown inside, button usable again', failed.shown && failed.open && failed.enabled && /Test failure/.test(failed.text), failed);
    ok('failure: error is reachable', (await reachable('#cancel-order-2 [data-oc-error]')) === true);
    await snap('error-390');
    await E(`window.fetch = window.__realFetch`);

    /* ── real cancel at 1440 ── */
    await realClick('#cancel-order-2 [data-oc-confirm]');
    await c.sleep(2500);
    ok('success: dialog closed and the order page shows it cancelled', await E(`location.pathname === '/orders/2' && !document.getElementById('cancel-order-2') && /cancelled/i.test(document.body.innerText)`));
    ok('success: the result message is shown', await E(`document.body.innerText.includes('Your order was cancelled')`));
    await snap('cancelled-390');
  } finally {
    c.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
  }
})();
