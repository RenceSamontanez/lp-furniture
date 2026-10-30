/*
 * Admin Customers list and Customer profile walk (SCRATCH DATABASE ONLY).
 *   DB_DATABASE=furniture_db_test php artisan migrate
 *   php tests/Rbac/seed_screenshots.php ; php tests/UiLinks/seed_customers_admin.php          (add --empty for the empty-list case)
 *   powershell -File tests/Rbac/serve_test.ps1                                                (or preview_start lp-furniture-test)
 *   BASE=http://127.0.0.1:8011 node --experimental-websocket tests/UiLinks/browser-admin-customers.cjs [functional|sizes|shots|all]
 * functional: real clicks and typing on both pages. sizes: overflow / clipped text / modal layering / button heights / contrast / fonts at 11 widths.
 * shots: screenshots at 1440, 1001 and 390 into docs/admin/screenshots-customers/.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(lib.ROOT, 'docs', 'admin', 'screenshots-customers');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const WIDTHS = [320, 360, 390, 430, 600, 768, 1000, 1100, 1280, 1440, 1920];

(async () => {
  const c = await lib.start(9394);
  const results = [];
  const note = (name, ok, detail) => { results.push({ name, ok }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const logins = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const $js = (e) => c.evalJs(e);
  const where = () => $js('location.pathname + location.search');
  // click leaves a marker on the page; ready() waits for it to disappear (a new document) before looking, so a link click is never read too early
  const click = async (sel) => { await $js(`window.__nm = 1; document.querySelector(${JSON.stringify(sel)}).click()`); await c.sleep(300); };
  const ready = async (sel) => { for (let i = 0; i < 25; i++) { try { if (!(await $js('window.__nm'))) break; } catch (e) { break; } await c.sleep(120); } await c.sleep(300); for (let i = 0; i < 40; i++) { try { if (await $js(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(sel)})`)) { await c.sleep(200); return; } } catch (e) { /* navigating */ } await c.sleep(150); } };
  const rows = () => $js(`[...document.querySelectorAll('.cu-row .cu-name strong')].map((e) => e.textContent.trim())`);
  const typeInto = async (sel, text) => {
    await $js(`(() => { const i = document.querySelector(${JSON.stringify(sel)}); i.focus(); i.select && i.select(); })()`);
    await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
    await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Backspace', code: 'Backspace', windowsVirtualKeyCode: 8 });
    if (text) { await c.send('Input.insertText', { text }); }
  };
  const press = async (key, code, vk) => { await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, text: key === 'Enter' ? '\r' : undefined }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk }); };
  const overflow = () => $js(`document.documentElement.scrollWidth - document.documentElement.clientWidth`);

  const CLIPPED = `(() => {
    const bad = [];
    document.querySelectorAll('.lp-btn, .cp-tab, .cu-stat, .cu-chip, .cu-vchip, .cp-pst, .cu-fc span, th, .cp-tile b, .cp-tile span').forEach((el) => {
      if (!el.offsetWidth || getComputedStyle(el).visibility === 'hidden') return;
      if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX !== 'visible' && !el.closest('.cp-tabs')) bad.push((el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 30));
      const r = el.getBoundingClientRect();
      if ((r.right > innerWidth + 1 || r.left < -1) && !el.closest('.cp-tabs')) bad.push('offscreen:' + (el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 30));
    });
    // a pane or table that scrolls sideways inside its card hides content just as much as clipping does
    document.querySelectorAll('.cp-pane:not([hidden]), .cp-tabs, .cu-panel, .cp-tiles, .cp-head').forEach((el) => { if (el.scrollWidth > el.clientWidth + 1) bad.push('inner-scroll:' + el.className); });
    return bad;
  })()`;
  const HEIGHTS = `(() => {
    const out = {};
    document.querySelectorAll('.lp-btn').forEach((b) => { if (!b.offsetWidth) return; const k = b.classList.contains('cu-small') ? 'small' : 'regular'; (out[k] = out[k] || new Set()).add(Math.round(b.getBoundingClientRect().height)); });
    const tabs = new Set(); document.querySelectorAll('.cp-tab').forEach((t) => tabs.add(Math.round(t.getBoundingClientRect().height)));
    out.tabs = tabs;
    return Object.fromEntries(Object.entries(out).map(([k, v]) => [k, [...v]]));
  })()`;
  // WCAG contrast of every visible text node's element against the first opaque background above it
  const CONTRAST = `(() => {
    const lum = (c) => { const v = c.map((x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
    const parse = (s) => { const m = s.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(',').map(parseFloat); return { rgb: p.slice(0, 3), a: p.length > 3 ? p[3] : 1 }; };
    const bgOf = (el) => { for (let e = el; e; e = e.parentElement) { const b = parse(getComputedStyle(e).backgroundColor); if (b && b.a > 0.9) return b.rgb; } return [255, 255, 255]; };
    const bad = [];
    const roots = document.querySelectorAll('.cu, .cp, .lp-modal:not([hidden]) .lp-modal__box');
    roots.forEach((root) => root.querySelectorAll('*').forEach((el) => {
      if (!el.offsetWidth || ![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) return;
      const cs = getComputedStyle(el); const fg = parse(cs.color); if (!fg) return;
      const bg = bgOf(el); const a = lum(fg.rgb), b = lum(bg); const r = (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
      if (r < 4.5 && cs.opacity !== '0') bad.push(el.className + ':' + el.textContent.trim().slice(0, 24) + ':' + r.toFixed(2));
    }));
    return bad;
  })()`;
  const FONTS = `(() => { const f = {}; document.querySelectorAll('.cu *, .cp *, .lp-modal__box *').forEach((e) => { if (!e.offsetWidth || ![...e.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) return; const k = getComputedStyle(e).fontFamily.split(',')[0].replace(/["']/g, '').trim(); f[k] = (f[k] || 0) + 1; }); return { page: f, topbar: getComputedStyle(document.querySelector('.admin-topbar-title')).fontFamily.split(',')[0].replace(/["']/g, ''), cp: document.querySelector('.cp-av') ? getComputedStyle(document.querySelector('.cp-av')).fontFamily.split(',')[0] : null }; })()`;
  const audit = async (w) => { if (w <= 1000) { await $js(`document.querySelector('#admin-burger').click()`); await c.sleep(450); } const b = await $js(BUTTONS); if (w <= 1000) { await $js(`document.querySelector('#admin-sidebar-close').click()`); await c.sleep(300); } return b; };

  const profileLinks = async () => $js(`[...document.querySelectorAll('.cu-row')].map((r) => [r.querySelector('.cu-name strong').textContent.trim(), r.querySelector('.cu-act a').href])`);

  try {
    const admin = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.admin_email, 'admin'], { cwd: lib.ROOT, encoding: 'utf8' }));
    await c.setCookie(admin);
    await c.viewport(1440, 900);

    /* ───────────────────────── functional ───────────────────────── */
    if (MODE === 'functional' || MODE === 'all') {
      await c.go('/admin/customers');
      const base = await rows();
      const stats = await $js(`[...document.querySelectorAll('.cu-stat')].map((a) => a.textContent.trim())`);
      note('list: three stat cards with real counts', stats.length === 3 && /^\d+Total Customers$/.test(stats[0]) && /Verified$/.test(stats[1]) && /Unverified$/.test(stats[2]), stats);
      note('list: first page shows 15 rows and a pager', base.length === 15 && (await $js(`!!document.querySelector('.cu-pager nav, .cu-pager .pagination, .cu-pager [rel=next]')`)), base.length);
      note('list: Showing line', /^Showing 1 to 15 of \d+ customers/.test(await $js(`document.querySelector('#cu-count').textContent.trim().replace(/\\s+/g, ' ')`)));

      // stat cards (real clicks)
      await click('.cu-stat--v'); await ready('.cu-stat');
      const verifiedNow = await rows();
      note('stat card: Verified filters the list and the URL keeps it', (await where()).includes('status=verified') && verifiedNow.length > 0 && (await $js(`!!document.querySelector('.cu-stat--v.is-on')`)), { url: await where(), n: verifiedNow.length });
      await click('.cu-stat--u'); await ready('.cu-stat');
      note('stat card: Unverified', (await where()).includes('status=unverified') && (await $js(`document.querySelectorAll('.cu-vchip.is-v').length === 0`)), await where());
      await click('.cu-stat:first-child'); await ready('.cu-stat');
      note('stat card: Total Customers clears the status', !(await where()).includes('status='), await where());

      // live search by typing; focus must stay in the box and the URL must follow
      await typeInto('#cu-q', 'zamora-no-one'); await c.sleep(1700);
      note('search: typing with no match shows the empty state and keeps focus', (await $js(`!!document.querySelector('.cu-empty') && /No customers match/.test(document.querySelector('.cu-empty').textContent) && document.activeElement.id === 'cu-q'`)) && (await where()).includes('search=zamora-no-one'), await where());
      await click('.cu-empty-act a'); await ready('.cu-row');
      note('empty state: Clear Filters returns the full list', (await rows()).length === 15 && !(await where()).includes('search='), await where());
      await typeInto('#cu-q', 'ros'); await c.sleep(1700);
      const found = await rows();
      note('search: finds by name as you type (no reload)', found.length >= 1 && found.every((n) => /ros/i.test(n)) && found.some((n) => n.startsWith('Rosalinda')), found);
      await typeInto('#cu-q', '0917'); await c.sleep(1700);
      note('search: phone number', (await rows()).length > 1, (await rows()).length);
      await typeInto('#cu-q', 'longaddress@'); await c.sleep(1700);
      note('search: email', (await rows()).length === 1 && (await rows())[0].startsWith('Rosalinda'), await rows());
      await typeInto('#cu-q', ''); await c.sleep(1700);
      note('search: clearing it restores the list and the short URL', (await rows()).length === 15 && (await where()) === '/admin/customers', await where());
      // a refresh shows the same view
      await typeInto('#cu-q', 'ros'); await c.sleep(1700);
      await c.go('/admin/customers?search=ros');
      note('URL: a refresh shows the same search with the box filled', (await $js(`document.querySelector('#cu-q').value`)) === 'ros' && (await rows()).some((n) => n.startsWith('Rosalinda')));

      // Filters modal
      await c.go('/admin/customers');
      await click('.cu-filter-btn');
      const m = await $js(`(() => { const m = document.querySelector('#cu-filters'); const box = m.querySelector('.lp-modal__box').getBoundingClientRect(); const top = document.elementFromPoint(box.left + 30, box.top + 30); const side = document.elementFromPoint(40, 300);
        return { open: !m.hidden, onBody: m.parentNode === document.body, inside: m.contains(document.activeElement), above: m.contains(top), sideCovered: !document.querySelector('#admin-sidebar').contains(side) || m.contains(side), locked: document.body.classList.contains('lp-modal-open'), groups: [...m.querySelectorAll('legend')].map((l) => l.textContent.trim()), chips: m.querySelectorAll('.cu-fc').length, vh: box.bottom <= innerHeight && box.top >= 0 }; })()`);
      note('Filters: shared modal mounted on the body, above the menu, all five groups, fits the screen', m.open && m.onBody && m.above && m.locked && m.vh && m.chips === 11 && m.groups.join() === 'Status,Orders,Joined,Total Spent', m);
      await shot('walk-filters-open-1440');
      // range error: minimum above maximum
      await typeInto('#cu-min', '9000'); await typeInto('#cu-max', '100');
      await click('#cu-fapply');
      const err = await $js(`({ shown: !document.querySelector('#cu-ferr').hidden, open: !document.querySelector('#cu-filters').hidden, bad: document.querySelectorAll('#cu-filters .is-bad').length, url: location.search })`);
      note('Filters: min above max shows the error, stays open, and nothing is applied', err.shown && err.open && err.bad === 2 && err.url === '', err);
      await shot('walk-filters-error-1440');
      // fix and apply a combination
      await typeInto('#cu-max', '100000'); await typeInto('#cu-min', '1000');
      await $js(`document.querySelector('#cu-filters input[name=status][value=verified]').click()`);
      await $js(`document.querySelector('#cu-filters input[name=orders][value="1"]').click()`);
      await click('#cu-fapply'); await ready('.cu-chip');
      const chips = await $js(`[...document.querySelectorAll('.cu-chip')].map((x) => x.firstChild.textContent.trim())`);
      note('Filters: Show Results applies status, orders and spent; chips, badge and URL agree', chips.length === 3 && (await $js(`document.querySelector('#cu-badge').textContent.trim()`)) === '3' && /status=verified/.test(await where()) && /orders=1/.test(await where()) && /min=1000&max=100000/.test(await where()), { chips, url: await where() });
      note('Filters: results obey every filter', (await $js(`[...document.querySelectorAll('.cu-row')].every((r) => r.querySelector('.cu-vchip').textContent.trim() === 'Verified' && +r.querySelector('[data-label=Orders]').textContent >= 1)`)), await rows());
      note('Filters: Clear All link shows with two or more chips', await $js(`!!document.querySelector('.cu-clear')`));
      // reopen: the choices are remembered
      await click('.cu-filter-btn');
      note('Filters: reopening shows the active choices', await $js(`document.querySelector('#cu-filters input[name=status][value=verified]').checked && document.querySelector('#cu-min').value === '1000' && document.querySelector('#cu-max').value === '100000'`));
      await click('#cu-freset');
      note('Filters: Clear All inside the window resets the choices without applying', await $js(`document.querySelector('#cu-filters input[name=status][value=all]').checked && document.querySelector('#cu-min').value === '' && location.search.includes('status=verified')`));
      await press('Escape', 'Escape', 27); await c.sleep(300);
      note('Filters: Escape closes it', await $js(`document.querySelector('#cu-filters').hidden`));
      // remove one chip, then Clear All
      await click('.cu-chip a'); await ready('.cu-row');
      note('chip: removing one drops only that filter', !(await where()).includes('status=verified') && (await where()).includes('orders=1'), await where());
      await $js(`document.querySelector('.cu-filter-btn').click()`); await c.sleep(300);
      await $js(`document.querySelector('#cu-filters input[name=joined][value="30"]').click()`); await click('#cu-fapply'); await ready('.cu-chip');
      await click('.cu-clear'); await ready('.cu-row');
      note('Clear All removes every filter and the short URL returns', (await where()) === '/admin/customers' && (await $js(`document.querySelectorAll('.cu-chip').length === 0`)), await where());

      // sort dropdown: every option, and the URL
      const opts = await $js(`[...document.querySelectorAll('#cu-sort option')].map((o) => o.value)`);
      note('Sort: the eight options in the prototype order', opts.join() === 'joined-desc,joined-asc,name-asc,name-desc,orders-desc,orders-asc,spent-desc,spent-asc', opts);
      for (const o of opts.filter((x) => x !== 'joined-desc')) {
        await $js(`(() => { const s = document.querySelector('#cu-sort'); s.value = ${JSON.stringify(o)}; s.dispatchEvent(new Event('change', { bubbles: true })); })()`);
        await ready('.cu-row');
        const names = await rows();
        const vals = await $js(`[...document.querySelectorAll('.cu-row')].map((r) => ({ n: r.querySelector('.cu-name strong').textContent.trim(), o: +r.querySelector('[data-label=Orders]').textContent, s: +r.querySelector('[data-label="Total Spent"]').textContent.replace(/[^\\d.]/g, ''), j: r.querySelector('[data-label=Joined]').textContent.trim() }))`);
        const key = o.split('-')[0], asc = o.endsWith('asc');
        const ok = (a, b) => (asc ? a <= b : a >= b);
        const sorted = key === 'name' ? vals.every((v, i) => !i || (asc ? vals[i - 1].n.localeCompare(v.n) <= 0 : vals[i - 1].n.localeCompare(v.n) >= 0)) : key === 'orders' ? vals.every((v, i) => !i || ok(vals[i - 1].o, v.o)) : key === 'spent' ? vals.every((v, i) => !i || ok(vals[i - 1].s, v.s)) : vals.every((v, i) => !i || ok(new Date(vals[i - 1].j), new Date(v.j)));
        note(`Sort: ${o} orders the rows and sets the URL`, sorted && (await where()).includes('sort=' + o) && (await $js(`document.querySelector('#cu-sort').value`)) === o, { first: names[0], url: await where() });
      }
      // clickable column headers
      await c.go('/admin/customers');
      for (const [col, t1, t2] of [['Name', 'name-asc', 'name-desc'], ['Orders', 'orders-desc', 'orders-asc'], ['Total Spent', 'spent-desc', 'spent-asc'], ['Joined', 'joined-desc', 'joined-asc']]) {
        await $js(`[...document.querySelectorAll('.cu-sortb')].find((a) => a.textContent.trim() === ${JSON.stringify(col)}).click()`); await ready('.cu-row');
        const u1 = await where();
        await $js(`[...document.querySelectorAll('.cu-sortb')].find((a) => a.textContent.trim() === ${JSON.stringify(col)}).click()`); await ready('.cu-row');
        const u2 = await where();
        note(`Header "${col}": first click ${t1}, second click ${t2}, arrow marks it`, u1.includes('sort=' + t1) === (t1 !== 'joined-desc') && (u2.includes('sort=' + t2) === (t2 !== 'joined-desc')) && (await $js(`!!document.querySelector('.cu-sortb.is-on') && document.querySelector('th[aria-sort]') !== null`)), { u1, u2 });
      }

      // View → profile → Back keeps search, filters, sort and page
      await c.go('/admin/customers?status=verified&sort=name-desc&search=a');
      const links = await profileLinks();
      note('View: link carries the list state', links.length > 0 && links.every(([, h]) => h.includes('status=verified') && h.includes('sort=name-desc') && h.includes('search=a')), links[0]);
      await $js(`document.querySelector('.cu-row .cu-act a').click()`); await ready('.cp-name');
      note('View: opens the profile', /\/admin\/customers\/\d+\?/.test(await where()));
      await click('.cp-back a'); await ready('.cu-row');
      note('Back to Customers: the same search, filter and sort come back', (await where()) === '/admin/customers?search=a&status=verified&sort=name-desc' && (await $js(`document.querySelector('#cu-q').value`)) === 'a' && (await $js(`document.querySelector('#cu-sort').value`)) === 'name-desc', await where());
      await c.go('/admin/customers?page=2');
      await $js(`document.querySelector('.cu-row .cu-act a').click()`); await ready('.cp-name');
      await click('.cp-back a'); await ready('.cu-row');
      note('Back to Customers: keeps the page too', (await where()).includes('page=2'), await where());

      // a hand-edited link with min above max opens the Filters window with the message
      await c.go('/admin/customers?min=900&max=10');
      await c.sleep(500);
      note('URL: min above max in a link opens Filters with the message and applies nothing', (await $js(`!document.querySelector('#cu-filters').hidden && !document.querySelector('#cu-ferr').hidden && document.querySelectorAll('.cu-chip').length === 0`)));

      // ─── profile ───
      const busyId = await $js(`fetch('/admin/customers?search=longaddress@').then((r) => r.text()).then((t) => (t.match(/customers\\/(\\d+)/) || [])[1])`);
      await c.go('/admin/customers/' + busyId);
      const head = await $js(`({ name: document.querySelector('.cp-name').textContent.trim(), av: document.querySelector('.cp-av').textContent.trim(), chip: document.querySelector('.cp-meta .cu-vchip').textContent.trim(), meta: document.querySelector('.cp-meta').textContent.replace(/\\s+/g, ' ').trim(), tiles: [...document.querySelectorAll('.cp-tile')].map((t) => t.textContent.trim()), review: !!document.querySelector('.cp-head .lp-btn') })`);
      note('profile: avatar initials, name, verified chip, last activity, four tiles, no review button when verified', head.av === 'RV' && head.chip === 'Verified' && /Last activity/.test(head.meta) && head.tiles.length === 4 && !head.review, head);
      const tabsOk = await $js(`[...document.querySelectorAll('.cp-tab')].map((t) => t.textContent.trim())`);
      note('profile: four tabs with counts', tabsOk.join() === 'Orders14,Designs4,Activity,Name Changes1', tabsOk);
      for (const [tab, probe] of [['orders', `document.querySelectorAll('#cp-pane-orders tbody tr').length === 14 && !!document.querySelector('#cp-pane-orders a.cp-num')`], ['designs', `document.querySelectorAll('#cp-pane-designs .cp-dz').length === 4 && document.querySelectorAll('#cp-pane-designs a.cp-dz').length === 3`], ['activity', `document.querySelectorAll('#cp-pane-activity li').length > 10`], ['names', `document.querySelectorAll('#cp-pane-names tbody tr').length === 1`]]) {
        await click(`.cp-tab[data-tab=${tab}]`);
        note(`profile tab ${tab}: shows its panel only`, (await $js(`(() => { const on = [...document.querySelectorAll('.cp-pane')].filter((p) => !p.hidden).map((p) => p.dataset.pane); return on.length === 1 && on[0] === ${JSON.stringify(tab)} && document.querySelector('.cp-tab[aria-selected=true]').dataset.tab === ${JSON.stringify(tab)}; })()`)) && (await $js(probe)), tab === 'orders' ? undefined : await where());
      }
      // keyboard: arrows move between tabs
      await $js(`document.querySelector('.cp-tab[data-tab=orders]').focus()`); await press('ArrowRight', 'ArrowRight', 39); await c.sleep(150);
      note('profile tabs: ArrowRight moves to the next tab', await $js(`document.querySelector('.cp-tab[aria-selected=true]').dataset.tab === 'designs'`));
      await click('.cp-tab[data-tab=orders]');
      const ordHref = await $js(`document.querySelector('#cp-pane-orders a.cp-num').getAttribute('href')`);
      note('profile Orders: order number links to the real order page', /\/admin\/orders\/\d+$/.test(ordHref), ordHref);
      await c.go('/admin/customers/' + busyId + '?tab=activity');
      note('profile: ?tab= opens that tab on load', await $js(`document.querySelector('.cp-tab[aria-selected=true]').dataset.tab === 'activity' && !document.querySelector('#cp-pane-activity').hidden`));
      const side = await $js(`[...document.querySelectorAll('.cp-kv')].map((r) => r.textContent.replace(/\\s+/g, ' ').trim())`);
      note('profile: Contact shows the city and the address from the saved delivery address', side.some((s) => /LocationSan Juan, Batangas/.test(s)) && side.some((s) => /Saved AddressSitio Pulo Ilaya, Brgy\. Calit-calit, San Juan, Batangas 4226/.test(s)), side);

      // pending identity → Review button
      const pendId = await $js(`fetch('/admin/customers?search=Test%20User').then((r) => r.text()).then((t) => (t.match(/customers\\/(\\d+)/) || [])[1])`);
      await c.go('/admin/customers/' + pendId);
      note('profile: pending identity shows Review Identity Validation', await $js(`[...document.querySelectorAll('.cp-head .lp-btn')].some((b) => b.textContent.trim() === 'Review Identity Validation' && /\\/admin\\/validations\\/\\d+$/.test(b.getAttribute('href')))`));
      await click('.cp-head .lp-btn'); await c.sleep(1700);
      note('profile: that button opens the real identity page', /\/admin\/validations\/\d+/.test(await where()), await where());

      // a customer with nothing
      const noneId = await $js(`fetch('/admin/customers?search=Nothing').then((r) => r.text()).then((t) => (t.match(/customers\\/(\\d+)/) || [])[1])`);
      await c.go('/admin/customers/' + noneId);
      const empties = await $js(`({ o: document.querySelector('#cp-pane-orders .cp-empty b').textContent, tiles: [...document.querySelectorAll('.cp-tile b')].map((b) => b.textContent.trim()) })`);
      await click('.cp-tab[data-tab=designs]'); const d = await $js(`document.querySelector('#cp-pane-designs .cp-empty b').textContent`);
      await click('.cp-tab[data-tab=names]'); const nme = await $js(`document.querySelector('#cp-pane-names .cp-empty b').textContent`);
      await click('.cp-tab[data-tab=activity]'); const act = await $js(`document.querySelectorAll('#cp-pane-activity li').length`);
      note('profile with nothing: friendly empty states and zeros', empties.o === 'No orders yet' && d === 'No designs yet' && nme === 'No name changes' && act === 1 && empties.tiles[0] === '0' && empties.tiles[1] === '₱0.00', { empties, d, nme, act });
    }

    /* ───────────────────────── sizes ───────────────────────── */
    if (MODE === 'sizes' || MODE === 'all') {
      await c.go('/admin/customers');
      const busyId = await $js(`fetch('/admin/customers?search=longaddress@').then((r) => r.text()).then((t) => (t.match(/customers\\/(\\d+)/) || [])[1])`).catch(() => null) || '0';
      for (const w of WIDTHS) {
        await c.viewport(w, 900);
        const tag = `@${w}`;
        const pages = [['list', '/admin/customers'], ['list filtered', '/admin/customers?status=verified&orders=1&min=1000&max=100000&sort=spent-desc'], ['list page 2', '/admin/customers?page=2'], ['empty', '/admin/customers?search=zzzzzz']];
        for (const [label, url] of pages) {
          await c.go(url); await ready('.cu');
          const ov = await overflow(); const cl = await $js(CLIPPED);
          note(`${tag} ${label}: no horizontal scroll, nothing clipped`, ov <= 0 && cl.length === 0, { ov, cl: cl.slice(0, 4) });
          if (label === 'list') {
            const hs = await $js(HEIGHTS);
            note(`${tag} list: button heights (regular 42, small ${w <= 700 ? 40 : 36})`, (hs.regular || [42]).every((h) => h === 42) && (hs.small || [w <= 700 ? 40 : 36]).every((h) => h === (w <= 700 ? 40 : 36)), hs);
            const layout = await $js(`(() => { const t = document.querySelector('.cu-table'); const tbody = t.querySelector('tbody'); return { thead: getComputedStyle(t.querySelector('thead')).display, cols: getComputedStyle(tbody).display === 'grid' ? getComputedStyle(tbody).gridTemplateColumns.split(' ').length : 'table' }; })()`);
            const expect = w > 1380 ? { thead: 'table-header-group', cols: 'table' } : { thead: 'none', cols: w > 700 ? 2 : 1 };
            note(`${tag} list: ${w > 1380 ? 'table' : w > 700 ? 'two-column cards' : 'one-column cards'}`, layout.thead === expect.thead && layout.cols === expect.cols, layout);
            if (w === 1440 || w === 390) {
              const b = await audit(w); note(`${tag} list: button contrast`, b.failures.length === 0, b.failures.slice(0, 4));
              const ct = await $js(CONTRAST); note(`${tag} list: text contrast >= 4.5`, ct.length === 0, ct.slice(0, 5));
              const f = await $js(FONTS); note(`${tag} list: Montserrat everywhere, Cormorant only in the top bar`, Object.keys(f.page).every((k) => k === 'Montserrat') && f.topbar === 'Cormorant Garamond', f);
            }
          }
        }
        // Filters modal
        await c.go('/admin/customers'); await ready('.cu');
        await click('.cu-filter-btn');
        const mv = await $js(`(() => { const m = document.querySelector('#cu-filters'); const r = m.querySelector('.lp-modal__box').getBoundingClientRect(); const foot = [...m.querySelectorAll('.lp-modal__foot .lp-btn')].map((b) => { const q = b.getBoundingClientRect(); return q.width > 0 && q.left >= 0 && q.right <= innerWidth && q.bottom <= innerHeight; }); return { open: !m.hidden, fits: r.left >= -1 && r.right <= innerWidth + 1 && r.bottom <= innerHeight + 1, foot, scroll: m.querySelector('.lp-modal__body').scrollWidth <= m.querySelector('.lp-modal__body').clientWidth + 1, hs: [...m.querySelectorAll('.lp-modal__foot .lp-btn')].map((b) => Math.round(b.getBoundingClientRect().height)) }; })()`);
        note(`${tag} Filters window: visible, fits, both buttons on screen, 42px`, mv.open && mv.fits && mv.foot.every(Boolean) && mv.scroll && mv.hs.every((h) => h >= 42), mv);
        if (w === 1440 || w === 390) { const ct = await $js(CONTRAST); note(`${tag} Filters window: text contrast >= 4.5`, ct.length === 0, ct.slice(0, 5)); }
        await typeInto('#cu-min', '9000'); await typeInto('#cu-max', '100'); await click('#cu-fapply');
        note(`${tag} Filters window: range error visible and nothing clipped`, await $js(`!document.querySelector('#cu-ferr').hidden && document.documentElement.scrollWidth <= innerWidth + 1`));
        await press('Escape', 'Escape', 27); await c.sleep(250);

        // profile
        for (const [label, url] of [['profile busy', '/admin/customers/' + busyId], ['profile designs', '/admin/customers/' + busyId + '?tab=designs'], ['profile activity', '/admin/customers/' + busyId + '?tab=activity'], ['profile names', '/admin/customers/' + busyId + '?tab=names']]) {
          await c.go(url); await ready('.cp-name');
          const ov = await overflow(); const cl = await $js(CLIPPED);
          note(`${tag} ${label}: no horizontal scroll, nothing clipped`, ov <= 0 && cl.length === 0, { ov, cl: cl.slice(0, 4) });
          if (label === 'profile busy') {
            const hs = await $js(HEIGHTS);
            note(`${tag} profile: tab rows all ${JSON.stringify(hs.tabs)} high (48) and buttons 42/${w <= 700 ? 40 : 36}`, hs.tabs.every((h) => h === 48) && (hs.regular || [42]).every((h) => h === 42) && (hs.small || [36]).every((h) => h === (w <= 700 ? 40 : 36)), hs);
            if (w === 1440 || w === 390) {
              const ct = await $js(CONTRAST); note(`${tag} profile: text contrast >= 4.5`, ct.length === 0, ct.slice(0, 5));
              const f = await $js(FONTS); note(`${tag} profile: fonts`, Object.keys(f.page).every((k) => k === 'Montserrat') && f.cp === 'Montserrat' && f.topbar === 'Cormorant Garamond', f);
            }
          }
        }
      }
    }

    /* ───────────────────────── shots ───────────────────────── */
    if (MODE === 'shots' || MODE === 'all') {
      await c.go('/admin/customers');
      const idOf = (q) => $js(`fetch('/admin/customers?search=${q}').then((r) => r.text()).then((t) => (t.match(/customers\\/(\\d+)/) || [])[1])`);
      await c.viewport(1440, 900);
      const busyId = await idOf('longaddress@'), noneId = await idOf('Nothing'), pendId = await idOf('Test');
      for (const w of [1440, 1001, 390]) {
        await c.viewport(w, 900);
        await c.go('/admin/customers'); await shot(`list-default-${w}`);
        await c.go('/admin/customers?status=verified&orders=1&min=1000&max=100000&sort=spent-desc'); await shot(`list-filters-on-${w}`);
        await c.go('/admin/customers'); await click('.cu-filter-btn'); await c.sleep(300); await shot(`list-filters-modal-${w}`);
        await typeInto('#cu-min', '9000'); await typeInto('#cu-max', '100'); await click('#cu-fapply'); await c.sleep(300); await shot(`list-filters-error-${w}`);
        await c.go('/admin/customers?search=zzzzzz'); await shot(`list-empty-${w}`);
        for (const tab of ['orders', 'designs', 'activity', 'names']) { await c.go('/admin/customers/' + busyId + '?tab=' + tab); await shot(`profile-data-${tab}-${w}`); }
        await c.go('/admin/customers/' + pendId); await shot(`profile-pending-${w}`);
        for (const tab of ['orders', 'designs', 'activity', 'names']) { await c.go('/admin/customers/' + noneId + '?tab=' + tab); await shot(`profile-none-${tab}-${w}`); }
      }
    }
  } finally {
    c.close();
  }
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length} ok, ${failed.length} to check`);
  fs.writeFileSync(path.join(OUT, 'walk-report-' + MODE + '.json'), JSON.stringify(results, null, 1));
  process.exit(failed.length ? 1 : 0);
})();
