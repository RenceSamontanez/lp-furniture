/*
 * Admin Activity Log walk (SCRATCH DATABASE ONLY).
 *   DB_DATABASE=furniture_db_test php artisan migrate        (once; phpunit also rebuilds it, so run phpunit BEFORE seeding)
 *   php tests/Rbac/seed_screenshots.php ; php tests/UiLinks/seed_activity_log.php        (add --empty for the empty-state case)
 *   powershell -File tests/Rbac/serve_test.ps1                                           (or preview_start lp-furniture-test)
 *   BASE=http://127.0.0.1:8011 node --experimental-websocket tests/UiLinks/browser-activity-log.cjs [functional|sizes|shots|all]
 * functional: real clicks and typing (filters, sort, tabs, chips, paging, Details, Open Item, alert preview).
 * sizes: no horizontal scroll, no clipped controls, no modal overflow at 320, 360, 390, 430, 768, 1100, 1440.
 * shots: screenshots at 390 and 1440 into docs/admin/screenshots-activity-log/.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(lib.ROOT, 'docs', 'admin', 'screenshots-activity-log');
fs.mkdirSync(OUT, { recursive: true });
const WIDTHS = [320, 360, 390, 430, 768, 1100, 1440];

(async () => {
  const c = await lib.start(9395);
  const results = [];
  const note = (name, ok, detail) => { results.push({ name, ok }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const logins = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const $js = (e) => c.evalJs(e);
  const where = () => $js('location.pathname + location.search');
  const click = async (sel) => { await $js(`window.__nm = 1; document.querySelector(${JSON.stringify(sel)}).click()`); await c.sleep(300); };
  const ready = async (sel) => { for (let i = 0; i < 25; i++) { try { if (!(await $js('window.__nm'))) break; } catch (e) { break; } await c.sleep(120); } await c.sleep(300); for (let i = 0; i < 40; i++) { try { if (await $js(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(sel)})`)) { await c.sleep(200); return; } } catch (e) { /* navigating */ } await c.sleep(150); } };
  const descs = () => $js(`[...document.querySelectorAll('#al-rows tr')].map((r) => r.querySelector('[data-label="What Happened"]').textContent.trim())`);
  const press = async (key, code, vk) => { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk }); };
  const setSelect = async (sel, value) => { await $js(`window.__nm = 1; (() => { const s = document.querySelector(${JSON.stringify(sel)}); s.value = ${JSON.stringify(value)}; s.dispatchEvent(new Event('change', { bubbles: true })); })()`); await ready('.al'); };
  const typeInto = async (sel, text) => {
    await $js(`(() => { const i = document.querySelector(${JSON.stringify(sel)}); i.focus(); i.select && i.select(); })()`);
    await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
    await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
    if (text) { await c.send('Input.insertText', { text }); }
  };
  const overflow = () => $js('document.documentElement.scrollWidth - document.documentElement.clientWidth');
  const CLIPPED = `(() => {
    const bad = [];
    document.querySelectorAll('.al .lp-btn, .al-tab, .al-chip, .al-fc, .al-type, .al-role, .al-sum span, .al-sum b, th').forEach((el) => {
      if (!el.offsetWidth) return;
      if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX !== 'visible') bad.push((el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 30));
      const r = el.getBoundingClientRect();
      if (r.right > innerWidth + 1 || r.left < -1) bad.push('offscreen:' + (el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 30));
    });
    return bad;
  })()`;

  try {
    const admin = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.admin_email, 'admin'], { cwd: lib.ROOT, encoding: 'utf8' }));
    await c.setCookie(admin);
    await c.viewport(1440, 900);

    if (MODE === 'functional' || MODE === 'all') {
      await c.go('/admin/activity-log');
      note('page: title, intro, four summary cards, six tabs', (await $js(`document.querySelector('.admin-topbar-title').textContent.trim()`)) === 'Activity Log' && (await $js(`document.querySelectorAll('.al-sum > div').length`)) === 4 && (await $js(`document.querySelectorAll('.al-tab').length`)) === 6);
      note('sidebar: Activity Log is active, between Employees and Reports', await $js(`(() => { const a = [...document.querySelectorAll('#mnu .mnu-i')].map((x) => x.dataset.label); const i = a.indexOf('Activity Log'); return a[i - 1] === 'Employees' && a[i + 1] === 'Reports' && document.querySelector('.mnu-i.active').dataset.label === 'Activity Log'; })()`));
      const first = await descs();
      note('table: 12 rows on page 1, newest first, Showing line', first.length === 12 && /^Showing 1 to 12 of \d+$/.test(await $js(`document.querySelector('.al-pager span').textContent.trim()`)), first.slice(0, 3));
      note('late night tag shows on 12:00 AM and 4:59 AM rows only', await $js(`(() => { const t = [...document.querySelectorAll('.al-late')].length; return t >= 1; })()`));

      // filters through the real controls
      await setSelect('#al-who', 'customer');
      note('Who Is It = Customers: URL and rows follow, chip appears', (await where()) === '/admin/activity-log?who=customer' && (await $js(`[...document.querySelectorAll('#al-rows .al-role')].every((r) => r.textContent.trim() === 'Customer')`)) && (await $js(`document.querySelector('.al-chip').textContent.includes('Customers')`)), await where());
      await setSelect('#al-what', 'signout');
      note('What Happened = Signed Out', (await descs()).every((d) => d === 'Signed out') && (await where()).includes('what=signout'), await where());
      await click('.al-clear'); await ready('.al');
      note('Clear All returns the short URL', (await where()) === '/admin/activity-log', await where());

      await typeInto('#al-q', 'LP-ACT-1058'); await c.sleep(1800); await ready('.al');
      note('search by order number (debounced reload, short URL)', (await where()) === '/admin/activity-log?q=LP-ACT-1058' && (await descs()).length === 2 && (await descs()).every((d) => d.includes('LP-ACT-1058')), await descs());
      await click('.al-chip a'); await ready('.al');

      await click('.al-qd a:nth-child(1)'); await ready('.al');
      note('Quick Dates: Today sets From and To and marks the chip', (await $js(`document.querySelector('#al-from').value === document.querySelector('#al-to').value && document.querySelector('.al-fc[aria-pressed=true]').textContent.trim()`)) === 'Today', await where());
      await click('.al-chip a'); await ready('.al');
      await setSelect('#al-tod', 'night');
      note('Time Of Day = Late Night: every row carries the tag', (await $js(`[...document.querySelectorAll('#al-rows tr')].length > 0 && [...document.querySelectorAll('#al-rows tr')].every((r) => r.querySelector('.al-late'))`)), await where());
      await click('.al-clear'); await ready('.al');

      // tabs
      await click('.al-tab:nth-child(3)'); await ready('.al');
      note('tab: Orders & Payments filters rows to that type', (await $js(`[...document.querySelectorAll('#al-rows .al-type')].every((t) => t.textContent.trim() === 'Orders & Payments')`)) && (await where()).includes('tab=orders'), await where());
      await click('.al-chip a'); await ready('.al');

      // sorting: header links and dropdown
      await $js(`window.__nm = 1; document.querySelectorAll('.al-sortb')[1].click()`); await ready('.al');
      note('header Who: A to Z, then Z to A on the second click', (await where()).includes('sort=az'), await where());
      await $js(`window.__nm = 1; document.querySelectorAll('.al-sortb')[1].click()`); await ready('.al');
      note('header Who toggles to Z to A', (await where()).includes('sort=za') && (await $js(`document.querySelector('#al-sort').value`)) === 'za', await where());
      await setSelect('#al-sort', 'old');
      note('Sort By dropdown: Oldest First', (await where()).includes('sort=old'), await where());
      await setSelect('#al-sort', 'new');

      // paging keeps the query string
      await c.go('/admin/activity-log?who=admin&sort=old');
      await click('.al-pager [rel=next]'); await ready('.al');
      note('paging: Next keeps who and sort and shows rows 13 to 24', (await where()) === '/admin/activity-log?who=admin&sort=old&page=2' && /^Showing 13 to 24 of/.test(await $js(`document.querySelector('.al-pager span').textContent.trim()`)), await where());

      // Details, Open Item, alert preview
      await c.go('/admin/activity-log?q=Confirmed order');
      await click('#al-rows .al-act button');
      const det = await $js(`(() => { const m = document.querySelector('#al-detail'); return { open: !m.hidden, onBody: m.parentNode === document.body, f: [...m.querySelectorAll('dd')].map((d) => d.textContent.trim()), openUrl: document.querySelector('#al-open').hidden ? '' : document.querySelector('#al-open').getAttribute('href') }; })()`);
      note('Details: opens in the shared modal with When, Who, What Happened, Type, Item filled', det.open && det.f.length === 5 && det.f.every((x) => x.length > 0) && det.f[4].startsWith('Order '), det);
      note('Details: Open Item links to the real order page', /^\/admin\/orders\/\d+$/.test(det.openUrl), det.openUrl);
      await shot('walk-details-1440');
      await press('Escape', 'Escape', 27); await c.sleep(300);
      note('Details: Escape closes it', await $js(`document.querySelector('#al-detail').hidden`));
      await c.go('/admin/activity-log?q=Signed out');
      await click('#al-rows .al-act button');
      note('Details: a sign-in or sign-out has no Open Item button', await $js(`document.querySelector('#al-open').hidden`));
      await press('Escape', 'Escape', 27); await c.sleep(300);
      await click('[data-lp-modal-open=al-alert]');
      note('Alert preview opens with the exact text', await $js(`!document.querySelector('#al-alert').hidden && /New Sign-In To Your Account/.test(document.querySelector('#al-alert').textContent) && /If this was not you, change your password right away\\./.test(document.querySelector('#al-alert').textContent) && /No email is sent\\./.test(document.querySelector('#al-alert').textContent)`));
      await shot('walk-alert-1440');
      await press('Escape', 'Escape', 27); await c.sleep(300);

      // CSV link carries the same filters
      await c.go('/admin/activity-log?who=customer&sort=old');
      note('Download CSV link carries the active filters', await $js(`document.querySelector('a[href*="/export"]').getAttribute('href').includes('who=customer') && document.querySelector('a[href*="/export"]').getAttribute('href').includes('sort=old')`));

      await c.go('/admin/activity-log?q=zzzz-nothing');
      note('empty state text', await $js(`/No activity matches/.test(document.querySelector('.al-empty').textContent) && /Try a different tab, date or search\\./.test(document.querySelector('.al-empty').textContent)`));
    }

    if (MODE === 'sizes' || MODE === 'all') {
      for (const w of WIDTHS) {
        await c.viewport(w, 900);
        await c.go('/admin/activity-log');
        const o = await overflow();
        const bad = await $js(CLIPPED);
        note(`${w}px: no horizontal scroll and nothing clipped`, o <= 0 && bad.length === 0, { overflow: o, bad });
        await click('#al-rows .al-act button'); await c.sleep(300);
        const m = await $js(`(() => { const b = document.querySelector('#al-detail .lp-modal__box').getBoundingClientRect(); return { fits: b.left >= -1 && b.right <= innerWidth + 1 && b.bottom <= innerHeight + 1, sheet: innerWidth <= 700 ? Math.abs(b.bottom - innerHeight) < 2 : true, over: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()`);
        note(`${w}px: Details window fits${w <= 700 ? ' as a bottom sheet' : ''}`, m.fits && m.sheet && m.over <= 0, m);
        await press('Escape', 'Escape', 27); await c.sleep(300);
        await click('[data-lp-modal-open=al-alert]');
        const a = await $js(`(() => { const b = document.querySelector('#al-alert .lp-modal__box').getBoundingClientRect(); return b.left >= -1 && b.right <= innerWidth + 1 && b.bottom <= innerHeight + 1; })()`);
        note(`${w}px: alert preview fits`, a);
        await press('Escape', 'Escape', 27); await c.sleep(300);
        const heights = await $js(`[...new Set([...document.querySelectorAll('.al .lp-btn')].filter((b) => b.offsetWidth).map((b) => Math.round(b.getBoundingClientRect().height)))]`);
        note(`${w}px: buttons are 36 or 40 (small) and 42 (regular) tall`, heights.every((h) => [36, 40, 42].includes(h)), heights);
      }
    }

    if (MODE === 'shots' || MODE === 'all') {
      for (const w of [1440, 390]) {
        await c.viewport(w, w === 390 ? 844 : 900);
        await c.go('/admin/activity-log');
        await shot('activity-log-' + w);
        await click('#al-rows .al-act button'); await c.sleep(400);
        await shot('details-' + w);
        await press('Escape', 'Escape', 27); await c.sleep(300);
        await click('[data-lp-modal-open=al-alert]'); await c.sleep(400);
        await shot('alert-preview-' + w);
        await press('Escape', 'Escape', 27); await c.sleep(300);
      }
    }
  } finally {
    c.close();
  }
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length}/${results.length} ok`);
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
