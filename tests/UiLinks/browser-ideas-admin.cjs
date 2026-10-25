/*
 * Customize part 2 admin walk (SCRATCH DATABASE ONLY): Ideas to review (with data, filtered, empty), the Quick ideas editor and a
 * request detail with the reference photo and the call badge, at 1366x768 (and 820 tablet). Button contrast via
 * tests/ButtonContrast/measure.js, keyboard focus, the confirmation modal, the private photo route. Screenshots to docs/customize/screenshots/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php ; php tests/UiLinks/seed_my_designs.php ; php tests/UiLinks/seed_ideas_admin.php
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-ideas-admin.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'customize', 'screenshots');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');

(async () => {
  const c = await lib.start(9391);
  const report = { checks: [] };
  const note = (name, ok, detail) => { report.checks.push({ name, ok, detail }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const logins = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const press = async (key, code, vk, mods = 0) => { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, modifiers: mods, text: key === 'Enter' ? '\r' : undefined }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk, modifiers: mods }); };
  const overflow = () => c.evalJs(`document.documentElement.scrollWidth - document.documentElement.clientWidth`);

  try {
    const admin = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.admin_email, 'admin'], { cwd: lib.ROOT, encoding: 'utf8' }));
    await c.setCookie(admin);

    for (const [w, h, tag] of [[1366, 768, '1366x768'], [820, 1100, '820-tablet']]) {
      await c.viewport(w, h);

      await c.go('/admin/ideas-to-review');
      const m = await c.evalJs(`({ rows: document.querySelectorAll('.ir-table tbody tr').length, badge: (document.querySelector('a[href$="/admin/ideas-to-review"] .admin-nav-badge') || {}).textContent, wood: [...document.querySelectorAll('.ir-table tbody tr')].filter((r) => /Wood/.test(r.innerText) ).map((r) => r.querySelector('[data-lp-modal-open^="ir-option"]') ? 'has-option' : 'no-option') })`);
      note(`${tag} Ideas to review: rows, menu badge, wood rows have no Add as option`, m.rows >= 5 && /^\d+$/.test(m.badge) && m.wood.every((x) => x === 'no-option'), m);
      note(`${tag} Ideas to review: no horizontal overflow`, (await overflow()) <= 0);
      await shot(`admin-ideas-${tag}`);
      let b = await c.evalJs(`${BUTTONS}`);
      note(`${tag} Ideas to review: button contrast`, b.failures.length === 0, b.failures.slice(0, 4));

      // modal flow: open Write answer by keyboard, focus trapped, Escape returns focus
      await c.evalJs(`document.querySelector('[data-lp-modal-open^="ir-answer"]').focus()`);
      await press('Enter', 'Enter', 13);
      await c.sleep(300);
      const open = await c.evalJs(`({ open: !!document.querySelector('.lp-modal:not([hidden])'), inside: !!document.querySelector('.lp-modal:not([hidden])') && document.querySelector('.lp-modal:not([hidden])').contains(document.activeElement), title: (document.querySelector('.lp-modal:not([hidden]) .lp-modal__title') || {}).textContent })`);
      note(`${tag} Write answer opens the shared modal with focus inside`, open.open && open.inside, open);
      if (tag === '1366x768') await shot('admin-ideas-answer-modal-1366x768');
      await press('Escape', 'Escape', 27);
      await c.sleep(300);
      note(`${tag} Escape closes it`, await c.evalJs(`!document.querySelector('.lp-modal:not([hidden])')`));

      await c.go('/admin/ideas-to-review?kind=wood&status=all&sort=newest');
      note(`${tag} filter by kind wood`, await c.evalJs(`[...document.querySelectorAll('.ir-table tbody tr')].every((r) => /Wood/.test(r.innerText))`));

      await c.go('/admin/ideas-to-review?status=dismissed');
      const dis = await c.evalJs(`!!document.querySelector('form[action$="/undo"] button, form[action*="/undo"] button')`);
      note(`${tag} dismissed rows have Undo`, dis);
      if (tag === '1366x768') await shot('admin-ideas-dismissed-1366x768');

      await c.go('/admin/ideas-to-review?q=zzzzzz-nothing');
      note(`${tag} empty filter result says so`, /No ideas match these filters/.test(await c.evalJs(`document.querySelector('.ir-empty').innerText`)));
      await shot(`admin-ideas-empty-${tag}`);

      await c.go('/admin/quick-ideas');
      note(`${tag} Quick ideas: no horizontal overflow`, (await overflow()) <= 0);
      const q = await c.evalJs(`({ cards: document.querySelectorAll('.qi-card').length, rows: document.querySelectorAll('.qi-row').length })`);
      note(`${tag} Quick ideas: groups and rows`, q.cards >= 15 && q.rows >= 30, q);
      await shot(`admin-quick-ideas-${tag}`);
      b = await c.evalJs(`${BUTTONS}`);
      note(`${tag} Quick ideas: button contrast`, b.failures.length === 0, b.failures.slice(0, 4));
      if (tag === '1366x768') {
        await c.evalJs(`document.querySelector('.qi-row .qi-input').focus()`);
        await press('Tab', 'Tab', 9);
        await press('Tab', 'Tab', 9, 8);
        const ring = await c.evalJs(`getComputedStyle(document.activeElement).outlineStyle + ' ' + getComputedStyle(document.activeElement).outlineWidth`);
        note('Quick ideas: keyboard focus ring visible', /solid 3px/.test(ring), ring);
      }

      await c.go('/admin/custom-orders/9');
      const d = await c.evalJs(`({ badge: (document.querySelector('.call-badge') || {}).innerText, thumb: !!document.querySelector('.ref-thumb img'), dl: !!document.querySelector('a[href*="reference-photo"][href*="download=1"]'),
        style: /Rustic/.test(document.body.innerText), publicUrl: /storage\\/design-references/.test(document.documentElement.innerHTML) })`);
      note(`${tag} request detail: call badge with phone, thumbnail, download, style, no public URL`, /Call customer about wood quantity/.test(d.badge || '') && /09171234567/.test(d.badge) && d.thumb && d.dl && d.style && !d.publicUrl, d);
      note(`${tag} request detail: no horizontal overflow`, (await overflow()) <= 0);
      await c.evalJs(`document.querySelector('.ref-thumb').scrollIntoView({ block: 'center' })`);
      await shot(`admin-request-detail-${tag}`);
      if (tag === '1366x768') {
        await c.evalJs(`document.querySelector('.ref-thumb').click()`);
        await c.sleep(500);
        const big = await c.evalJs(`(() => { const i = document.querySelector('.lp-modal:not([hidden]) .ref-big'); return i ? { w: i.naturalWidth, shown: i.getBoundingClientRect().width } : null; })()`);
        note('thumbnail opens the photo larger in the shared modal', !!big && big.w > 0 && big.shown > 150, big);
        await shot('admin-request-photo-open-1366x768');
        await press('Escape', 'Escape', 27);
      }
      b = await c.evalJs(`${BUTTONS}`);
      const mine = b.failures.filter((f) => !f.cls.includes('admin-btn'));   // the existing admin buttons are the coming admin redesign's job
      note(`${tag} request detail: button contrast (new controls)`, mine.length === 0, { mine: mine.slice(0, 4), existingAdminBtnFailures: b.failures.length - mine.length });

      await c.go('/admin/custom-orders');
      note(`${tag} request list shows the style and the call hint`, await c.evalJs(`/Rustic style/.test(document.body.innerText) && /Call about wood quantity/.test(document.body.innerText)`));
      await shot(`admin-requests-list-${tag}`);
    }

    // the photo route over HTTP as the admin, then with no session at all
    const status = await c.evalJs(`fetch('/admin/custom-requests/9/reference-photo', { credentials: 'same-origin' }).then((r) => r.status + ' ' + r.headers.get('content-type') + ' ' + r.headers.get('cache-control'))`);
    note('admin gets the photo with a private cache header', /^200 image\/jpeg/.test(status) && /no-store/.test(status), status);
    const direct = await c.evalJs(`fetch('/storage/design-references/' + 'x/seed-reference.jpg', { credentials: 'same-origin' }).then((r) => r.status)`);
    note('the old public /storage URL does not serve it', direct !== 200, direct);
    await c.setCookie(null);
    const guest = await c.evalJs(`fetch('/admin/custom-requests/9/reference-photo', { credentials: 'same-origin', redirect: 'manual' }).then((r) => r.type + ' ' + r.status)`);
    note('no session: the photo is not served', !/^basic 200/.test(guest), guest);
  } finally {
    fs.writeFileSync(path.join(OUT, '_admin-ideas-checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
})().catch((e) => { console.error(e); process.exit(1); });
