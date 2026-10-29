/*
 * Admin menu, Ideas to Review and Reports and Analytics walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011).
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   php tests/Rbac/seed_screenshots.php ; php tests/UiLinks/seed_ideas_admin.php ; php tests/UiLinks/seed_admin_menu.php
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-admin-menu-ideas-reports.cjs [functional|sizes|shots|all]
 * functional: real clicks and keys. sizes: overflow / clipped text / modal layering / button heights / contrast / fonts at 11 widths.
 * shots: screenshots at 1440, 1001 and 390 into docs/admin/screenshots-menu/.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(lib.ROOT, 'docs', 'admin', 'screenshots-menu');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const WIDTHS = [320, 360, 390, 430, 600, 768, 1000, 1100, 1280, 1440, 1920];

(async () => {
  const c = await lib.start(9393);
  const results = [];
  const note = (name, ok, detail) => { results.push({ name, ok }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const logins = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const press = async (key, code, vk, mods = 0) => {
    await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: vk, modifiers: mods, text: key === 'Enter' ? '\r' : undefined });
    await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key, code, windowsVirtualKeyCode: vk, modifiers: mods });
  };
  const $js = (e) => c.evalJs(e);
  const click = async (sel) => { await $js(`document.querySelector(${JSON.stringify(sel)}).click()`); await c.sleep(250); };
  // the button audit reads the cascade, so the phone drawer has to be open for the menu buttons to be measured as shown
  const audit = async (w) => { if (w <= 1000) { await $js(`document.querySelector('#admin-burger').click()`); await c.sleep(450); } const b = await $js(BUTTONS); if (w <= 1000) { await $js(`document.querySelector('#admin-sidebar-close').click()`); await c.sleep(300); } return b; };
  // after a form post the server answers with a redirect: wait until the Ideas page is back and its script has run
  const settle = async () => { await c.sleep(700); for (let i = 0; i < 40; i++) { try { if (await $js(`document.readyState === 'complete' && !!document.querySelector('.ir2-tab') && !!document.querySelector('#ir-rows')`)) { await c.sleep(300); return; } } catch (e) { /* navigating */ } await c.sleep(250); } };
  const ready = async (sel) => { await c.sleep(600); for (let i = 0; i < 40; i++) { try { if (await $js(`document.readyState === 'complete' && !!document.querySelector(${JSON.stringify(sel)})`)) { await c.sleep(200); return; } } catch (e) { /* navigating */ } await c.sleep(250); } };
  const overflow = () => $js(`document.documentElement.scrollWidth - document.documentElement.clientWidth`);

  /** Text that is cut off where it should not be: a control or label whose content is wider than its box and not meant to show an ellipsis. */
  const CLIPPED = `(() => {
    const bad = [];
    document.querySelectorAll('.lp-btn, .ir2-tab, .rp-tab, .rp-seg a, .mnu-i, .mnu-h__name, .ir2-chip, .rp-trend, .rp-mv, th, .rp-kpi__l, .rp-four span').forEach((el) => {
      if (!el.offsetWidth || getComputedStyle(el).visibility === 'hidden') return;
      if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflowX !== 'visible') bad.push((el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 30));
      const r = el.getBoundingClientRect();
      if ((r.right > innerWidth + 1 || r.left < -1) && !el.closest('.rp-tabs')) bad.push('offscreen:' + (el.className || el.tagName) + ':' + el.textContent.trim().slice(0, 30));
    });
    return bad;
  })()`;
  const HEIGHTS = `(() => {
    const out = {};
    document.querySelectorAll('.lp-btn').forEach((b) => { if (!b.offsetWidth) return; const k = b.classList.contains('ir2-small') || b.classList.contains('rp-small') ? 'small' : (b.classList.contains('lp-btn--chip') ? 'chip' : 'regular'); (out[k] = out[k] || new Set()).add(Math.round(b.getBoundingClientRect().height)); });
    return Object.fromEntries(Object.entries(out).map(([k, v]) => [k, [...v]]));
  })()`;

  try {
    const admin = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.admin_email, 'admin'], { cwd: lib.ROOT, encoding: 'utf8' }));
    await c.setCookie(admin);

    /* ───────────────────────── functional ───────────────────────── */
    if (MODE === 'functional' || MODE === 'all') {
      await c.viewport(1440, 900);
      await c.go('/admin/dashboard');
      await $js(`localStorage.removeItem('lpAdminMenuShut')`);
      await c.go('/admin/ideas-to-review');

      const groups = await $js(`[...document.querySelectorAll('.mnu-h__name')].map((e) => e.textContent.trim())`);
      note('menu: seven groups in order', JSON.stringify(groups) === JSON.stringify(['Overview', 'Orders & Payments', 'Catalog & Stock', 'Customers', 'Luma & Customize Studio', 'Team & Reports', 'Site Settings']), groups);
      note('menu: text only (no svg or img inside the sidebar)', await $js(`!document.querySelector('.mnu-i svg, .mnu-signout svg, #admin-sidebar img')`));
      note('menu: Sign Out pinned at the bottom, full width', await $js(`(() => { const s = document.querySelector('.mnu-signout').getBoundingClientRect(), a = document.querySelector('#admin-sidebar').getBoundingClientRect(); return Math.abs(a.bottom - s.bottom) < 24 && s.width > a.width - 40; })()`));
      note('menu: the active row is gold and has aria-current', await $js(`(() => { const a = document.querySelector('.mnu-i[aria-current="page"]'); return !!a && a.textContent.includes('Ideas to Review') && getComputedStyle(a).backgroundColor === 'rgb(200, 150, 62)'; })()`));
      note('menu: no glass / blur / shadow on the panel', await $js(`(() => { const s = getComputedStyle(document.querySelector('#admin-sidebar')); return (s.backdropFilter === 'none' || !s.backdropFilter) && s.boxShadow === 'none' && s.borderRadius === '0px'; })()`));

      // collapse another group: slides closed, chevron turns, the sum of its counts shows, state is remembered
      await click('[data-group="orders"] .mnu-h');
      const shut = await $js(`(() => { const g = document.querySelector('[data-group="orders"]'); return { cls: g.classList.contains('is-shut'), exp: g.querySelector('.mnu-h').getAttribute('aria-expanded'), hidden: getComputedStyle(g.querySelector('.mnu-l')).visibility, pill: getComputedStyle(g.querySelector('.mnu-g-pill') || document.body).display, stored: localStorage.getItem('lpAdminMenuShut') }; })()`);
      await c.sleep(350);
      note('menu: a group collapses (aria-expanded false, links hidden, sum pill shown, state stored)', shut.cls && shut.exp === 'false' && /orders/.test(shut.stored || ''), shut);
      await shot('menu-collapsed-1440');
      await c.go('/admin/ideas-to-review');
      note('menu: the collapsed state is remembered after a reload', await $js(`document.querySelector('[data-group="orders"]').classList.contains('is-shut')`));
      await click('[data-group="orders"] .mnu-h');
      note('menu: expands again', await $js(`!document.querySelector('[data-group="orders"]').classList.contains('is-shut')`));

      // the group of the current page cannot be collapsed away
      await click('[data-group="luma"] .mnu-h');
      note('menu: the current page\'s group stays open', await $js(`!document.querySelector('[data-group="luma"]').classList.contains('is-shut') && document.querySelector('[data-group="luma"] .mnu-h').getAttribute('aria-disabled') === 'true'`));

      // keyboard: Tab reaches a header, Enter and Space toggle it
      await $js(`document.querySelector('[data-group="catalog"] .mnu-h').focus()`);
      await press('Enter', 'Enter', 13); await c.sleep(250);
      note('menu: Enter toggles a header', await $js(`document.querySelector('[data-group="catalog"]').classList.contains('is-shut')`));
      await press(' ', 'Space', 32); await c.sleep(250);
      note('menu: Space toggles it back', await $js(`!document.querySelector('[data-group="catalog"]').classList.contains('is-shut')`));

      // Find a page
      await $js(`(() => { const f = document.querySelector('#mnu-find'); f.focus(); f.value = 'refund'; f.dispatchEvent(new Event('input', { bubbles: true })); })()`);
      const f1 = await $js(`[...document.querySelectorAll('.mnu-i')].filter((a) => !a.hidden && !a.closest('[hidden]')).map((a) => a.dataset.label)`);
      note('Find a page: filters live', JSON.stringify(f1) === JSON.stringify(['Cancellations & Refunds']), f1);
      await $js(`(() => { const f = document.querySelector('#mnu-find'); f.value = 'zzzzz'; f.dispatchEvent(new Event('input', { bubbles: true })); })()`);
      note('Find a page: "No page matches that."', await $js(`!document.querySelector('#mnu-none').hidden`));
      await $js(`(() => { const f = document.querySelector('#mnu-find'); f.value = ''; f.dispatchEvent(new Event('input', { bubbles: true })); })()`);

      // every menu link answers
      const links = await $js(`[...document.querySelectorAll('.mnu-i')].map((a) => a.getAttribute('href'))`);
      const codes = await $js(`Promise.all(${JSON.stringify(links)}.map((u) => fetch(u, { credentials: 'same-origin' }).then((r) => r.status)))`);
      note('menu: every link opens (HTTP 200)', codes.every((s) => s === 200), links.map((l, i) => l.replace(/^.*\/admin/, '') + '=' + codes[i]).filter((x) => !/=200/.test(x)));

      // ───── Ideas ─────
      await c.go('/admin/ideas-to-review');
      const tabs = await $js(`[...document.querySelectorAll('.ir2-tab')].map((t) => t.textContent.replace(/\\s+/g, ' ').trim())`);
      note('ideas: five tabs with counts', tabs.length === 5 && tabs[0].startsWith('Needs Review') && tabs[4].startsWith('All'), tabs);
      note('ideas: default tab is Needs Review and only new ideas show', await $js(`[...document.querySelectorAll('.ir2-row')].filter((r) => !r.hidden).every((r) => r.dataset.status === 'new')`));
      await click('[data-tab="answered"]');
      note('ideas: Answered tab shows only answered', await $js(`(() => { const v = [...document.querySelectorAll('.ir2-row')].filter((r) => !r.hidden); return v.length > 0 && v.every((r) => r.dataset.status === 'answered'); })()`));
      await click('[data-tab="all"]');
      await $js(`(() => { const q = document.querySelector('#ir-q'); q.value = 'teak'; q.dispatchEvent(new Event('input', { bubbles: true })); })()`);
      note('ideas: live search', await $js(`(() => { const v = [...document.querySelectorAll('.ir2-row')].filter((r) => !r.hidden); return v.length >= 1 && v.every((r) => /teak/i.test(r.textContent)); })()`));
      await $js(`(() => { const q = document.querySelector('#ir-q'); q.value = ''; q.dispatchEvent(new Event('input', { bubbles: true })); const k = document.querySelector('#ir-kind'); k.value = 'wood'; k.dispatchEvent(new Event('change', { bubbles: true })); })()`);
      note('ideas: Type filter', await $js(`[...document.querySelectorAll('.ir2-row')].filter((r) => !r.hidden).every((r) => r.dataset.kind === 'wood')`));
      await $js(`(() => { const k = document.querySelector('#ir-kind'); k.value = ''; k.dispatchEvent(new Event('change', { bubbles: true })); const s = document.querySelector('#ir-sort'); s.value = 'recent'; s.dispatchEvent(new Event('change', { bubbles: true })); })()`);
      const lasts = await $js(`[...document.querySelectorAll('.ir2-row')].filter((r) => !r.hidden).map((r) => +r.dataset.last)`);
      note('ideas: Most Recent First sorts by last typed', lasts.every((v, i) => !i || lasts[i - 1] >= v));
      await $js(`(() => { const s = document.querySelector('#ir-sort'); s.value = 'most'; s.dispatchEvent(new Event('change', { bubbles: true })); })()`);
      await click('[data-tab="new"]');

      // View on the long idea: one modal, mounted on the body, above the sidebar and top bar
      await $js(`(() => { const r = [...document.querySelectorAll('.ir2-row')].find((x) => x.querySelector('.ir2-topic').title.startsWith('I want my dining table')); r.querySelector('[data-view]').click(); })()`);
      await c.sleep(300);
      const v = await $js(`(() => { const m = document.querySelector('#ir-view'); const box = m.querySelector('.lp-modal__box').getBoundingClientRect(); const top = document.elementFromPoint(box.left + 20, 10), side = document.elementFromPoint(40, 300); return { open: !m.hidden, onBody: m.parentNode === document.body, z: +getComputedStyle(m).zIndex, zSide: +getComputedStyle(document.querySelector('#admin-sidebar')).zIndex, topBehind: !m.querySelector('.lp-modal__box').contains(top), sideBehind: !m.querySelector('.lp-modal__box').contains(side), inside: m.contains(document.activeElement), bodyLocked: document.body.classList.contains('lp-modal-open'), quote: m.querySelector('.ir2-quote').textContent.length, requests: m.querySelectorAll('.ir2-rq').length, where: m.textContent.includes('Where It Came From'), links: [...m.querySelectorAll('.ir2-rq a')].map((a) => a.getAttribute('href')) }; })()`);
      note('ideas View: opens once, on the body, above the sidebar and top bar, focus inside, page locked', v.open && v.onBody && v.z > v.zSide && v.inside && v.bodyLocked && v.quote > 100 && v.where, v);
      note('ideas View: lists the real requests with View Request links', v.requests >= 2 && v.links.every((h) => /\/admin\/design-requests\/\d+$/.test(h)), v.links);
      await shot('ideas-view-modal-1440');
      // the three starter replies, a chip, the bracket rule, the preview
      await click('#ir-can [data-o="more"]');
      let reply = await $js(`document.querySelector('#ir-answer').value`);
      note('ideas View: "Yes, with a catch" fills a starter reply from the real idea', /cost a little more/.test(reply) && /molave/.test(reply), reply.slice(0, 80));
      await click('#ir-chips [data-c="2"]');
      reply = await $js(`document.querySelector('#ir-answer').value`);
      note('ideas View: a chip adds a sentence with [brackets]', /\[number\]/.test(reply));
      await click('#ir-view [type="submit"]');
      note('ideas View: leftover [brackets] block saving', await $js(`document.querySelector('#ir-reply-field').classList.contains('is-invalid') && /brackets/.test(document.querySelector('#ir-answer-err').textContent) && !document.querySelector('#ir-view').hidden`));
      await $js(`(() => { const t = document.querySelector('#ir-answer'); t.value = 'Yes, we can make a table from your molave wood. We will confirm the price and build time first.'; t.dispatchEvent(new Event('input', { bubbles: true })); })()`);
      note('ideas View: the preview shows what the customer will see', await $js(`document.querySelector('#ir-preview').textContent.startsWith('Yes, we can make a table')`));
      // Escape with typed text asks first
      await press('Escape', 'Escape', 27); await c.sleep(250);
      note('ideas View: Escape with typed text asks before discarding', await $js(`!document.querySelector('#ir-view').hidden && document.querySelector('#ir-view .am-discard').classList.contains('is-on')`));
      await $js(`document.querySelector('#ir-view [data-keep]').click()`);
      await click('#ir-view [type="submit"]');
      await settle();
      note('ideas Send Reply: saved, the idea now shows as Answered', await $js(`(() => { document.querySelector('[data-tab="answered"]').click(); return [...document.querySelectorAll('.ir2-row')].some((r) => !r.hidden && r.querySelector('.ir2-topic').title.startsWith('I want my dining table')); })()`));

      // More menu → Add as Option (a furniture type), shared modal, live preview, then submit
      await click('[data-tab="new"]');
      await $js(`(() => { const r = [...document.querySelectorAll('.ir2-row')].find((x) => x.dataset.kind === 'furniture_type' && !x.hidden); r.querySelector('[data-more]').click(); })()`);
      await c.sleep(200);
      const menu = await $js(`[...document.querySelectorAll('#ir-menu button')].map((b) => b.textContent.trim().replace(/\\s+/g, ' '))`);
      note('ideas More: Add as Option, Dismiss, then Delete', JSON.stringify(menu) === JSON.stringify(['Add as Option', 'Dismiss', 'Delete']), menu);
      await $js(`document.querySelector('#ir-menu [data-a="opt"]').click()`);
      await c.sleep(300);
      await $js(`(() => { const n = document.querySelector('#ir-opt-name'); n.value = ''; n.dispatchEvent(new Event('input', { bubbles: true })); })()`);
      await click('#ir-option [type="submit"]');
      note('ideas Add as Option: a name is required', await $js(`document.querySelector('#ir-opt-name-field').classList.contains('is-invalid') && !document.querySelector('#ir-option').hidden`));
      await $js(`(() => { const n = document.querySelector('#ir-opt-name'); n.value = 'Garden Pergola'; n.dispatchEvent(new Event('input', { bubbles: true })); })()`);
      note('ideas Add as Option: live preview highlights the new choice', await $js(`!!document.querySelector('#ir-opt-pills .is-new') && document.querySelector('#ir-opt-pills .is-new').textContent.includes('Garden Pergola')`));
      await shot('ideas-add-option-1440');
      await click('#ir-option [type="submit"]');
      await settle();
      note('ideas Add as Option: saved (tab count moved to Added as Option)', await $js(`document.querySelector('[data-tab="added_as_option"] .ir2-count').textContent.trim() !== '1'`));

      // Dismiss from More, then Delete through the confirmation
      await $js(`(() => { document.querySelector('[data-tab="new"]').click(); const r = [...document.querySelectorAll('.ir2-row')].find((x) => !x.hidden); r.querySelector('[data-more]').click(); })()`);
      await c.sleep(200);
      await $js(`document.querySelector('#ir-menu [data-a="dis"]').click()`);
      await settle();
      note('ideas Dismiss: done at once with an Undo notice', await $js(`!!document.querySelector('.lp-notice') && /Undo/.test(document.querySelector('.lp-notice').textContent)`));
      await $js(`(() => { document.querySelector('[data-tab="dismissed"]').click(); const r = [...document.querySelectorAll('.ir2-row')].find((x) => !x.hidden); r.querySelector('[data-more]').click(); })()`);
      await c.sleep(200);
      const dm = await $js(`[...document.querySelectorAll('#ir-menu button')].map((b) => b.textContent.trim())`);
      note('ideas More on a dismissed idea: only Delete', JSON.stringify(dm) === JSON.stringify(['Delete']), dm);
      await $js(`document.querySelector('#ir-menu [data-a="del"]').click()`);
      await c.sleep(300);
      note('ideas Delete: a confirmation window opens', await $js(`!document.querySelector('#ir-delete').hidden`));
      await press('Escape', 'Escape', 27); await c.sleep(250);
      note('ideas Delete: Escape closes it and focus returns to More', await $js(`document.querySelector('#ir-delete').hidden && document.activeElement.hasAttribute('data-more')`));
      await click('[data-lp-modal-open="ir-help"]');
      note('ideas Help: opens with the example', await $js(`!document.querySelector('#ir-help').hidden && document.querySelector('#ir-help').textContent.includes('Puno ng Saging')`));
      await shot('ideas-help-1440');
      await $js(`document.querySelector('#ir-help [data-lp-modal-close]').click()`);

      // ───── Reports ─────
      await c.go('/admin/reports');
      note('reports: six cards', await $js(`document.querySelectorAll('.rp-kpi').length === 6`));
      note('reports: page title', await $js(`document.querySelector('.admin-topbar-title').textContent.trim() === 'Reports and Analytics'`));
      for (const t of ['prod', 'svc', 'luma', 'cust', 'sales']) {
        await click(`[data-tab="${t}"]`);
        const visible = await $js(`[...document.querySelectorAll('.rp-pane')].filter((p) => p.classList.contains('is-on')).map((p) => p.id)`);
        note(`reports: ${t} tab shows`, JSON.stringify(visible) === JSON.stringify(['rp-' + t]), visible);
      }
      await click('[data-tab="cust"]');
      await $js(`document.querySelector('[data-period="q"]').click()`);
      await ready('.rp-tab.is-on');
      const per = await $js(`({ url: location.search, tab: document.querySelector('.rp-tab.is-on').dataset.tab, on: document.querySelector('.rp-seg a.is-on').textContent.trim() })`);
      note('reports: the period switch keeps the tab', /period=q/.test(per.url) && per.tab === 'cust' && per.on === 'Last 3 Months vs Before', per);
      await click('[data-tab="prod"]');
      await $js(`document.querySelector('#rp-prod .rp-small').click()`);
      await ready('.admin-topbar-title');
      note('reports: Manage goes to Products', await $js(`location.pathname === '/admin/products'`));
    }

    /* ───────────────────────── sizes ───────────────────────── */
    if (MODE === 'sizes' || MODE === 'all') {
      for (const w of WIDTHS) {
        await c.viewport(w, 900);
        const tag = `${w}px`;
        // the menu: closed page and (on a phone) the open drawer
        await c.go('/admin/ideas-to-review');
        note(`${tag} menu page: no horizontal overflow`, (await overflow()) <= 0);
        if (w <= 1000) {
          await click('#admin-burger');
          const d = await $js(`(() => { const s = document.querySelector('#admin-sidebar'); const r = s.getBoundingClientRect(); return { open: s.classList.contains('open'), inView: r.left >= -1 && r.right <= innerWidth + 1, focusInside: s.contains(document.activeElement), noScroll: getComputedStyle(document.body).overflow === 'hidden', tap: Math.min(...[...s.querySelectorAll('.mnu-i')].filter((a) => a.offsetWidth).map((a) => a.getBoundingClientRect().height)), clipped: ${CLIPPED} }; })()`);
          note(`${tag} drawer: opens in view, focus inside, page locked, 40px rows, nothing clipped`, d.open && d.inView && d.focusInside && d.noScroll && d.tap >= 40 && d.clipped.length === 0, d);
          await press('Escape', 'Escape', 27); await c.sleep(300);
          note(`${tag} drawer: Escape closes it and focus returns to the burger`, await $js(`!document.querySelector('#admin-sidebar').classList.contains('open') && document.activeElement.id === 'admin-burger'`));
        } else {
          note(`${tag} menu: nothing clipped`, (await $js(CLIPPED)).length === 0, await $js(CLIPPED));
        }

        // Ideas: list
        await c.go('/admin/ideas-to-review');
        await click('[data-tab="all"]');
        note(`${tag} ideas list: no overflow`, (await overflow()) <= 0);
        const clipped = await $js(CLIPPED);
        note(`${tag} ideas list: nothing clipped`, clipped.length === 0, clipped);
        const mode = await $js(`getComputedStyle(document.querySelector('.ir2-table thead')).display === 'none' ? 'cards' : 'table'`);
        note(`${tag} ideas list: ${w <= 1380 ? 'cards' : 'table'}`, mode === (w <= 1380 ? 'cards' : 'table'), mode);
        const hs = await $js(HEIGHTS);
        const smallOk = (hs.small || []).every((h) => h === (w <= 700 ? 40 : 36)) , regOk = (hs.regular || []).every((h) => h >= 42);
        note(`${tag} ideas buttons: small ${w <= 700 ? 40 : 36}px, regular at least 42px`, smallOk && regOk, hs);
        const tabH = await $js(`[...new Set([...document.querySelectorAll('.ir2-tab')].map((t) => Math.round(t.getBoundingClientRect().height)))]`);
        note(`${tag} ideas tabs: one height`, tabH.length === 1, tabH);
        if (w === 1440 || w === 390) { const b = await audit(w); note(`${tag} ideas: button contrast`, b.failures.length === 0, b.failures.slice(0, 4)); }

        // every modal at this width
        for (const [sel, open] of [['#ir-help', `document.querySelector('[data-lp-modal-open="ir-help"]').click()`],
          ['#ir-view', `[...document.querySelectorAll('.ir2-row')].find((r) => r.querySelector('.ir2-topic').title.startsWith('I want my dining')).querySelector('[data-view]').click()`],
          ['#ir-option', `(() => { const r = [...document.querySelectorAll('.ir2-row')].find((x) => x.dataset.kind === 'furniture_type' && x.dataset.status === 'new'); r.querySelector('[data-more]').click(); document.querySelector('#ir-menu [data-a="opt"]').click(); })()`]]) {
          await $js(open); await c.sleep(350);
          const m = await $js(`(() => { const m = document.querySelector(${JSON.stringify(sel)}); const box = m.querySelector('.lp-modal__box'); const r = box.getBoundingClientRect(); const foot = m.querySelector('.lp-modal__foot'); const btns = [...foot.querySelectorAll('.lp-btn')]; return { open: !m.hidden, w: Math.round(r.width), inView: r.left >= -1 && r.right <= innerWidth + 1 && r.bottom <= innerHeight + 1, above: document.elementFromPoint(Math.max(2, r.left + 8), Math.min(innerHeight - 4, r.top + 8)) && box.contains(document.elementFromPoint(r.left + r.width / 2, r.top + 30)), sheet: innerWidth <= 700 ? Math.abs(r.bottom - innerHeight) < 3 : true, noWrapCut: btns.every((b) => b.getBoundingClientRect().right <= innerWidth + 1 && b.scrollWidth <= b.clientWidth + 1), heights: [...new Set(btns.map((b) => Math.round(b.getBoundingClientRect().height)))], primaryFull: innerWidth <= 700 ? (() => { const p = foot.querySelector('.ir2-primary, [data-lp-confirm], .lp-btn--primary'); return !p || p.getBoundingClientRect().width > r.width * 0.8; })() : true, bodyOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()`);
          note(`${tag} ${sel}: visible above the page, fits, buttons whole`, m.open && m.inView && m.above && m.sheet && m.noWrapCut && m.primaryFull && m.bodyOverflow <= 0, m);
          await press('Escape', 'Escape', 27); await c.sleep(250);
          if (await $js(`!!document.querySelector('.lp-modal:not([hidden])')`)) { await $js(`document.querySelector('.lp-modal:not([hidden]) [data-lp-modal-close]').click()`); await c.sleep(200); }
        }

        // Reports: every tab
        for (const t of ['sales', 'prod', 'svc', 'luma', 'cust']) {
          await c.go('/admin/reports?tab=' + t);
          const o = await overflow(), cl = await $js(CLIPPED);
          note(`${tag} reports ${t}: no overflow, nothing clipped`, o <= 0 && cl.length === 0, { o, cl });
          if (w <= 700 && (t === 'svc' || t === 'cust')) note(`${tag} reports ${t}: tables stack`, await $js(`[...document.querySelectorAll('#rp-${t} .rp-stack')].every((tb) => getComputedStyle(tb.querySelector('thead')).display === 'none')`));
          if (t === 'svc') note(`${tag} reports svc: Done column ${w <= 1380 && w > 700 ? 'hidden' : 'shown'}`, await $js(`(() => { const e = document.querySelector('#rp-svc .rp-show-phone'); return !!e && (getComputedStyle(e).display !== 'none') === ${!(w <= 1380 && w > 700)}; })()`));
        }
        const kp = await $js(`getComputedStyle(document.querySelector('.rp-kpis')).gridTemplateColumns.split(' ').length`);
        note(`${tag} reports cards per row`, kp === (w > 1380 ? 6 : w > 1000 ? 3 : 2), kp);
        const rt = await $js(`[...new Set([...document.querySelectorAll('.rp-tab')].map((t) => Math.round(t.getBoundingClientRect().height)))]`);
        note(`${tag} reports tabs: one height (48px)`, rt.length === 1 && rt[0] === 48, rt);
        if (w === 1440 || w === 390) { const b = await audit(w); note(`${tag} reports: button contrast`, b.failures.length === 0, b.failures.slice(0, 4)); }
      }

      // fonts
      await c.viewport(1440, 900);
      await c.go('/admin/reports');
      const f = await $js(`(() => { const ff = (s) => { const e = document.querySelector(s); return e ? getComputedStyle(e).fontFamily.split(',')[0].replace(/["']/g, '') : null; }; return { body: ff('body'), title: ff('.admin-topbar-title'), menu: ff('.mnu-i'), group: ff('.mnu-h'), button: ff('.rp-small'), number: ff('.rp-kpi__v'), brand: ff('.admin-sidebar-brand span') }; })()`);
      note('fonts: Montserrat everywhere, Cormorant only for the page title', f.body === 'Montserrat' && f.menu === 'Montserrat' && f.group === 'Montserrat' && f.button === 'Montserrat' && f.number === 'Montserrat' && f.brand === 'Montserrat' && /Cormorant/.test(f.title), f);
      const lining = await $js(`getComputedStyle(document.querySelector('.rp')).fontVariantNumeric`);
      note('reports: lining + tabular numbers', /lining-nums/.test(lining) && /tabular-nums/.test(lining), lining);
    }

    /* ───────────────────────── screenshots ───────────────────────── */
    if (MODE === 'shots' || MODE === 'all') {
      for (const w of [1440, 1001, 390]) {
        await c.viewport(w, 900);
        await c.go('/admin/ideas-to-review');
        await $js(`localStorage.setItem('lpAdminMenuShut', JSON.stringify({ orders: true }))`);
        await c.go('/admin/ideas-to-review');
        if (w <= 1000) await click('#admin-burger');
        await c.sleep(400);
        await shot(`menu-open-collapsed-${w}`);
        if (w <= 1000) { await press('Escape', 'Escape', 27); await c.sleep(300); }
        await $js(`localStorage.removeItem('lpAdminMenuShut')`);
        await c.go('/admin/ideas-to-review');
        await $js(`document.querySelector('[data-tab="all"]').click()`);
        await shot(`ideas-list-${w}`);
        await $js(`[...document.querySelectorAll('.ir2-row')].find((r) => r.querySelector('.ir2-topic').title.startsWith('I want my dining')).querySelector('[data-view]').click()`);
        await c.sleep(400); await shot(`ideas-view-long-${w}`);
        await $js(`document.querySelector('#ir-view [data-lp-modal-close]').click()`); await c.sleep(250);
        await $js(`(() => { const r = [...document.querySelectorAll('.ir2-row')].find((x) => x.dataset.kind === 'furniture_type' && x.dataset.status === 'new'); r.querySelector('[data-more]').click(); document.querySelector('#ir-menu [data-a="opt"]').click(); })()`);
        await c.sleep(400); await shot(`ideas-add-option-${w}`);
        await press('Escape', 'Escape', 27); await c.sleep(250);
        await $js(`document.querySelector('[data-lp-modal-open="ir-help"]').click()`); await c.sleep(400); await shot(`ideas-help-${w}`);
        await press('Escape', 'Escape', 27); await c.sleep(250);
        for (const t of ['sales', 'prod', 'svc', 'luma', 'cust']) { await c.go('/admin/reports?tab=' + t); await c.sleep(300); await shot(`reports-${t}-${w}`); }
      }
    }
  } finally {
    c.close();
  }
  const failed = results.filter((r) => !r.ok);
  console.log(`\n${results.length - failed.length} ok, ${failed.length} to check`);
  fs.writeFileSync(path.join(OUT, 'walk-report.json'), JSON.stringify(results, null, 1));
  process.exit(failed.length ? 1 : 0);
})();
