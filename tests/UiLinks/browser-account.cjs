/*
 * My Account + order details walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011).
 * Personal info and Security tabs, and the order details step (delivery: no saved address, saved address, different address;
 * pickup with the shop location from Contact Settings or the footer fallback; pickup with no shop location), at 1920x1080, 1440x900, 1366x768,
 * 820x1180 and 390x844. PASS=noshop runs only the "no shop location" shots (start the server with the fallback location emptied). Records page/pane scroll, horizontal overflow, whether the open province list is clipped (it must not be),
 * keyboard use of the combobox, and the button contrast audit. Screenshots go to docs/account/screenshots/, results to _checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_account.php > storage/app/account-seed.json
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-account.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'account', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const seed = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'account-seed.json'), 'utf8').replace(/^﻿/, ''));
const SIZES = [[1920, 1080], [1440, 900], [1366, 768], [820, 1180], [390, 844]];
const NOSHOP = process.env.PASS === 'noshop';
const report = { sizes: SIZES.map((s) => s.join('x')), pages: [], notes: [] };

const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, 'web'], { cwd: lib.ROOT, encoding: 'utf8' }));

const METRICS = `(() => {
  const d = document.documentElement, ma = document.getElementById('ma'), pb = document.querySelector('.ma .pb');
  const r = ma ? ma.getBoundingClientRect() : null;
  return {
    horizontalScroll: d.scrollWidth > innerWidth + 1,
    maBottom: r ? Math.round(r.bottom) : null, viewportH: innerHeight,
    maFitsViewport: r ? r.bottom <= innerHeight + 1 : null,
    paneScrolls: pb ? pb.scrollHeight > pb.clientHeight + 1 : null,
    paneOverflowPx: pb ? Math.max(0, pb.scrollHeight - pb.clientHeight) : null,
    footerBar: (() => { const f = document.getElementById('foot'); if (!f || f.hidden) return null; const b = f.getBoundingClientRect(); return { bottom: Math.round(b.bottom), visible: b.top < innerHeight && b.bottom > 0 }; })(),
  };
})()`;

(async () => {
  const c = await lib.start(9395);
  await c.send('Emulation.setFocusEmulationEnabled', { enabled: true });   // headless pages are not focused: blur/focus events need this
  // cdp-lib writes into its own folder; this walk keeps its pictures with the account docs
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  try {
    for (const [w, h] of SIZES) {
      await c.viewport(w, h);
      const tag = `${w}x${h}`;

      // ───── My Account tabs ─────
      const plan = [
        ['fresh', ['personal', 'security']],
        ['saved', ['personal', 'security']],
        ['google', ['security']],
        ['pending', ['personal']],
      ];
      for (const [who, tabs] of (NOSHOP ? [] : plan)) {
        await c.setCookie(cookieFor(seed.users[who]));
        for (const t of tabs) {
          await c.go(`/profile?tab=${t}`);
          const m = await c.evalJs(METRICS);
          const errs = await c.evalJs('window.__errs || []');
          await shot(`account-${who}-${t}-${tag}`);
          report.pages.push({ page: `account/${who}/${t}`, size: tag, ...m });
        }
      }

      // ───── dropdown: never clipped, keyboard works, cascade loads lazily (desktop and phone) ─────
      if (!NOSHOP) {
      await c.setCookie(cookieFor(seed.users.saved));
      await c.go('/profile?tab=personal');
      const dd = await c.evalJs(`(async () => {
        const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
        const out = {};
        const prov = document.getElementById('pprovince');
        prov.scrollIntoView({ block: 'center' }); prov.focus(); prov.click(); await sleep(900);
        const ul = document.getElementById('pprovince-l');
        const r = ul.getBoundingClientRect();
        out.listOpen = !ul.hidden; out.items = ul.querySelectorAll('[role=option]').length; out.letters = ul.querySelectorAll('.ltr').length;
        out.inViewport = r.top >= 0 && r.bottom <= innerHeight && r.left >= 0 && r.right <= innerWidth;
        const hit = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(40, r.height / 2));
        out.notClipped = !!hit && ul.contains(hit);
        out.insideBody = ul.parentNode === document.body;
        prov.value = ''; prov.dispatchEvent(new Event('input')); document.execCommand('insertText', false, 'cebu'); await sleep(300);
        out.filtered = [...ul.querySelectorAll('[role=option]')].map((o) => o.textContent).slice(0, 3);
        prov.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true })); await sleep(300);
        out.provPicked = prov.value; out.provId = document.querySelector('input[name=province_id]').value;
        const city = document.getElementById('pcity');
        out.cityEnabled = !city.disabled && document.querySelector('input[name=city_id]').value === '';
        out.cityCleared = city.value === '';
        // a wrong word is refused
        prov.focus(); prov.value = 'Atlantis'; prov.dispatchEvent(new Event('input')); prov.blur(); await sleep(400);
        out.badWordMessage = prov.closest('.fld').querySelector('.err').textContent;
        return out;
      })()`);
      report.pages.push({ page: 'combobox', size: tag, ...dd });
      await shot(`account-saved-dropdown-${tag}`);
      }

      // ───── order details step in its three address states ─────
      const states = [['noaddr', 'none-yet', 'delivery'], ['saved', 'already-saved', 'delivery'], ['noaddr', 'pickup', 'pickup']];
      for (const [who, label, opt] of states) {
        if (NOSHOP && label !== 'pickup') { continue; }
        await c.setCookie(cookieFor(seed.users[who]));
        await c.go('/checkout');
        await c.evalJs(`(() => { if (innerWidth > 768) { openSection('delivery'); } else { const p = document.getElementById('ckn-preparing'); if (p) { p.style.display = 'none'; } goToScreen(null, 'delivery'); } })()`);
        await c.sleep(500);
        if (opt === 'delivery') { await c.evalJs(`document.querySelector('input[name=delivery_option][value=delivery]').click()`); await c.sleep(300); }
        await c.evalJs(`document.querySelector('[data-order-details]').scrollIntoView({ block: 'start' })`);
        await c.sleep(300);
        const m = await c.evalJs(`(() => ({ horizontalScroll: document.documentElement.scrollWidth > innerWidth + 1, continueDisabled: [...document.querySelectorAll('[data-od-continue]')].map((b) => b.getAttribute('aria-disabled')), msg: document.querySelector('[data-od-msg]').textContent }))()`);
        const name = label === 'pickup' ? (NOSHOP ? 'pickup-no-shop-location' : 'pickup-with-shop-location') : label;
        if (label === 'pickup') {
          Object.assign(m, await c.evalJs(`(() => { const pk = document.querySelector('[data-od-pickup]'); const f = document.querySelector('[data-od-form]'); const map = pk.querySelector('iframe'); const r = map ? map.getBoundingClientRect() : null; return { cardShown: !pk.hidden, addressFormHidden: f.hidden, hasMap: !!map, mapInsideViewportWidth: r ? r.left >= 0 && r.right <= innerWidth : null, mapHeight: r ? Math.round(r.height) : null, soon: /coming soon/i.test(pk.textContent), directions: !!pk.querySelector('a[href*="google.com/maps"]') }; })()`));
        }
        await shot(`order-${name}-${tag}`);
        report.pages.push({ page: `order/${name}`, size: tag, ...m });
        if (who === 'saved' && !NOSHOP) {
          await c.evalJs(`document.querySelector('[data-od-diff]').click()`);
          await c.sleep(500);
          const m2 = await c.evalJs(`(() => ({ horizontalScroll: document.documentElement.scrollWidth > innerWidth + 1, legend: document.querySelector('[data-od-ask-legend]').textContent, defaultKeep: document.querySelector('input[name=keep_address]:checked').value, continueDisabled: document.querySelector('[data-od-continue]').getAttribute('aria-disabled'), msg: document.querySelector('[data-od-msg]').textContent }))()`);
          await shot(`order-different-${tag}`);
          report.pages.push({ page: 'order/different-address', size: tag, ...m2 });
        }
      }
    }

    // ───── button contrast on the account page and the checkout block ─────
    await c.viewport(1440, 900);
    await c.setCookie(cookieFor(seed.users.fresh));
    for (const t of (NOSHOP ? [] : ['personal', 'security'])) {
      await c.go(`/profile?tab=${t}`);
      const r = await c.evalJs(BUTTONS);
      report.pages.push({ page: `contrast/${t}`, buttons: r.count, failing: r.failures.length, failures: r.failures.map((f) => ({ text: f.text, cls: f.cls, min: f.min, height: f.height })) });
    }
  } finally {
    fs.writeFileSync(path.join(OUT, NOSHOP ? '_checks-noshop.json' : '_checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
  console.log('walk finished: ' + report.pages.length + ' checks, results in ' + path.join(OUT, '_checks.json'));
})().catch((e) => { console.error(e); process.exit(1); });
