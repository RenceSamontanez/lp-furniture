/*
 * Order tracking walk: admin order page and customer Track My Order page at 1440x900 and 390x844 (SCRATCH DATABASE ONLY, http://127.0.0.1:8011).
 * Takes full-page screenshots into docs/order-tracking/screenshots, checks for horizontal scroll, that the update card comes first on a phone,
 * that the dialog is centred and its confirm button waits for the tick, runs a real advance with the mouse, and runs tests/ButtonContrast/measure.js
 * on every page. Results: docs/order-tracking/screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_order_tracking.php
 *   (start the scratch server on :8011 with MAIL_MAILER=log: tests/Rbac/serve_test.ps1)
 *   node --experimental-websocket tests/UiLinks/browser-order-tracking.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'order-tracking', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });
const SIZES = [[1440, 900], [390, 844]];
const MEASURE = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const cookieFor = (email, guard) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, guard], { cwd: lib.ROOT, encoding: 'utf8' }));
const report = { checks: [], pass: 0, fail: 0 };
const ok = (name, cond, detail) => { report.checks.push({ name, ok: !!cond, detail }); cond ? report.pass++ : report.fail++; console.log(cond ? 'PASS' : 'FAIL', name, cond || detail === undefined ? '' : JSON.stringify(detail)); };

(async () => {
  const c = await lib.start(9300 + Math.floor(Math.random() * 500));
  const E = (x) => c.evalJs(x);
  const snap = async (name) => {
    const r = await c.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64'));
  };
  const mouse = async (x, y) => {
    await c.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await c.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await c.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  };
  const click = async (sel) => {
    const r = await E(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; e.scrollIntoView({ block: 'center' }); const b = e.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
    if (!r) throw new Error('missing ' + sel);
    await c.sleep(80);
    await mouse(r.x, r.y);
  };
  const measure = async (label) => {
    const res = await E(`(() => { const r = (0, eval)(${JSON.stringify(MEASURE)}); return { count: r.count, failures: r.failures.map(f => f.text + ':' + f.min) }; })()`);
    ok(`contrast ${label}: ${res.count} buttons, none below 4.5:1`, res.failures.length === 0, res.failures);
  };
  const noScroll = async (label) => ok(`no horizontal scroll ${label}`, await E('document.documentElement.scrollWidth <= innerWidth + 1'));

  try {
    for (const [w, h] of SIZES) {
      const tag = `${w}`;
      await c.viewport(w, h);

      /* ───── admin ───── */
      await c.setCookie(cookieFor('ot-admin@example.test', 'admin'));
      for (const [id, name] of [[1, 'paid'], [2, 'unpaid-locked'], [3, 'partly-paid-correction'], [5, 'cancelled']]) {
        await c.go('/admin/orders/' + id);
        await snap(`admin-${name}-${tag}`);
        await noScroll(`admin ${name} ${tag}`);
        await measure(`admin ${name} ${tag}`);
      }
      await c.go('/admin/orders/2');
      ok(`admin ${tag}: unpaid order shows the Waiting for payment box and no Next-step button`, await E(`document.body.innerText.includes('Waiting for payment') && !document.querySelector('[data-ot-kind=advance]')`));
      await c.go('/admin/orders/3');
      ok(`admin ${tag}: the correction is listed as Internal only`, await E(`document.body.innerText.includes('Marked ready too early') && document.body.innerText.includes('Internal only')`));
      if (w < 700) {
        await c.go('/admin/orders/1');
        ok('admin 390: the Update progress card comes before the history', await E(`document.getElementById('ot-up-t').getBoundingClientRect().top < document.getElementById('ot-hist-t').getBoundingClientRect().top`));
      }

      /* ───── dialog and a real advance (order 4, pickup, ready -> completed; only at the first size) ───── */
      if (w >= 700) {
        await c.go('/admin/orders/4');
        ok('admin: pickup order has no Out for delivery stage', await E(`!document.body.innerText.includes('Out for delivery')`));
        await click('[data-ot-kind=advance]');
        await c.sleep(300);
        await snap('admin-dialog-1440');
        const d = await E(`(() => { const b = document.getElementById('ot-dlg').getBoundingClientRect(); return { cx: Math.round(b.left + b.width / 2), cy: Math.round(b.top + b.height / 2), vw: innerWidth, vh: innerHeight, yes: document.getElementById('ot-dlg-yes').disabled, q: document.getElementById('ot-dlg-q').textContent }; })()`);
        ok('dialog is centred', Math.abs(d.cx - d.vw / 2) < 4 && Math.abs(d.cy - d.vh / 2) < d.vh * 0.2, d);
        ok('dialog question is the prototype wording', d.q === 'Has the customer collected the furniture?', d.q);
        ok('confirm button is disabled until the box is ticked', d.yes === true);
        await click('#ot-dlg-chk');
        ok('confirm button is enabled after the tick', await E(`!document.getElementById('ot-dlg-yes').disabled`));
        await click('#ot-dlg-yes');
        await c.sleep(1800);
        ok('order moved to Completed with a success toast', await E(`document.body.innerText.includes('Order moved to Completed') && document.querySelector('.ot .badge.b-stage').textContent.trim() === 'Completed'`));
        await snap('admin-after-advance-1440');
      }

      /* ───── customer ───── */
      await c.setCookie(cookieFor('ot-customer@example.test', 'web'));
      for (const [id, name] of [[1, 'in-production-update'], [2, 'unpaid'], [3, 'partly-paid'], [5, 'cancelled']]) {
        await c.go('/orders/' + id);
        await snap(`customer-${name}-${tag}`);
        await noScroll(`customer ${name} ${tag}`);
        await measure(`customer ${name} ${tag}`);
      }
      await c.go('/orders/3');
      ok(`customer ${tag}: never sees a correction`, await E(`!/Marked ready too early|Corrected/.test(document.body.innerText)`));
      await c.go('/orders/1');
      ok(`customer ${tag}: sees the admin's message and photo`, await E(`document.body.innerText.includes('The frame is assembled') && !!document.querySelector('.ot img[src*="order-update-photos"]')`));
      await c.go('/admin/orders/1');
      ok(`customer ${tag}: cannot open the admin order page`, await E(`location.pathname.startsWith('/admin/login') || location.pathname === '/admin/login'`));
    }
  } catch (e) {
    ok('walk finished without an exception', false, String(e && e.stack || e));
  }

  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  console.log(`\n${report.pass} passed, ${report.fail} failed`);
  c.close();
  process.exit(report.fail ? 1 : 0);
})();
