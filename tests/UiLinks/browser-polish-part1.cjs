/*
 * Polish sweep part 1 browser walk (SCRATCH DATABASE ONLY): Order Now contrast (measure.js), the shared password Show/Hide,
 * the admin service photo link, 44px "Mark read", chip heights / no-wrap at 390 and 1440, and Quick view opened by REAL touch taps.
 * Screenshots + _checks-part1.json go to docs/stage-polish-screenshots/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   php tests/Rbac/seed_screenshots.php ; php tests/UiLinks/seed_catalog.php ; php tests/UiLinks/seed_photo.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-polish-part1.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { start, ROOT, OUT } = require('./cdp-lib.cjs');

const MEASURE = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const bad = [];
const report = { orderNow: [], password: [], photo: {}, markRead: [], chips: [], quickView: [] };
const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

(async () => {
  const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookieFor = (email, guard) => JSON.parse(execFileSync('php', ['tests/UiLinks/session-cookie-for.php', email, guard], { cwd: ROOT }).toString());
  const adminCookie = cookieFor(login.admin_email, 'admin');
  const customerCookie = cookieFor(login.customer_email, 'web');
  const c = await start(9371);
  const { evalJs, go, shot, tap, sleep } = c;

  // ---------- 1. Order Now contrast ----------
  await c.setCookie(null);
  for (const w of [1440, 390]) {
    await c.viewport(w);
    for (const p of ['/', '/product/2']) {
      await go(p);
      const r = await evalJs(MEASURE);
      const od = r.rows.filter((x) => x.cls === 'btn-view-details');
      const worst = od.length ? Math.min(...od.map((x) => x.min)) : null;
      const states = od[0] ? { default: od[0].default, hover: od[0].hover, focus: od[0].focus, active: od[0].active, h: od[0].height } : null;
      report.orderNow.push({ w, p, buttons: r.count, failures: r.failures.length, orderNowLinks: od.length, worst, states });
      note(r.failures.length === 0, `${w}px ${p}: measure.js ${r.count} buttons, ${r.failures.length} failures; ${od.length} .btn-view-details links, worst ${worst} ${JSON.stringify(states)}`);
      if (w === 390 && od[0]) note(od.every((x) => x.height >= 44), `390px ${p}: .btn-view-details links are at least 44px high`);
    }
  }

  // ---------- 2. Password Show/Hide ----------
  const pwPages = [['/login', null], ['/register', null], ['/reset-password/sometoken?email=a%40example.test', null], ['/profile', customerCookie], ['/admin/login', null]];
  for (const w of [1440, 390]) {
    await c.viewport(w);
    for (const [p, ck] of pwPages) {
      await c.setCookie(ck); await go(p);
      if (p === '/profile') { await evalJs(`document.querySelector('[data-profile-tab="security"]').click()`); await sleep(300); }
      const here = await evalJs('location.pathname');
      const info = await evalJs(`[...document.querySelectorAll('[data-password-toggle]')].map((b) => { const i = document.getElementById(b.dataset.passwordToggle); const r = b.getBoundingClientRect(); return { id: b.dataset.passwordToggle, tag: b.tagName, type: b.type, pressed: b.getAttribute('aria-pressed'), label: b.getAttribute('aria-label'), w: Math.round(r.width), h: Math.round(r.height), inputIsPassword: !!i && i.type === 'password', inputW: i && Math.round(i.getBoundingClientRect().width), inputH: i && Math.round(i.getBoundingClientRect().height) }; })`);
      const noOld = await evalJs(`document.querySelectorAll('[onclick*="togglePassword"]').length === 0`);
      const wanted = { '/login': 1, '/register': 2, '/profile': 2, '/admin/login': 1 }[p] || (p.startsWith('/reset') ? 2 : 1);
      note(info.length === wanted && noOld, `${w}px ${p} (${here}): ${info.length} toggle(s), expected ${wanted}, no old inline handler`);
      for (const t of info) {
        note(t.tag === 'BUTTON' && t.type === 'button' && t.pressed === 'false' && t.label === 'Show password' && t.inputIsPassword, `${w}px ${p} #${t.id}: real <button type=button>, aria-pressed=false, name "Show password"`);
        note(t.h >= 44 && t.w >= 44, `${w}px ${p} #${t.id}: touch target ${t.w}x${t.h}`);
        await evalJs(`document.querySelector('[data-password-toggle="${t.id}"]').scrollIntoView({block:'center'})`);
        const after = await evalJs(`(() => { const b = document.querySelector('[data-password-toggle="${t.id}"]'); const i = document.getElementById('${t.id}'); i.value = 'Zz-not-a-real-password-1'; b.focus(); b.click(); const r = i.getBoundingClientRect(); const on = { type: i.type, pressed: b.getAttribute('aria-pressed'), label: b.getAttribute('aria-label'), text: b.textContent, w: Math.round(r.width), h: Math.round(r.height) }; const kept = i.value; b.click(); i.value = ''; return { on, off: { type: i.type, pressed: b.getAttribute('aria-pressed'), label: b.getAttribute('aria-label'), text: b.textContent }, kept, scrollW: document.documentElement.scrollWidth, innerW: innerWidth, focusOutline: getComputedStyle(b).outlineStyle + ' ' + getComputedStyle(b).outlineWidth }; })()`);
        note(after.on.type === 'text' && after.on.pressed === 'true' && after.on.label === 'Hide password' && after.on.text === 'Hide', `${w}px ${p} #${t.id}: click shows the text, aria-pressed=true, name "Hide password"`);
        note(after.off.type === 'password' && after.off.pressed === 'false' && after.off.text === 'Show', `${w}px ${p} #${t.id}: second click hides it again`);
        note(after.on.w === t.inputW && after.on.h === t.inputH, `${w}px ${p} #${t.id}: input size unchanged (${t.inputW}x${t.inputH} -> ${after.on.w}x${after.on.h})`);
        note(after.scrollW <= after.innerW, `${w}px ${p}: no horizontal overflow (${after.scrollW} <= ${after.innerW})`);
        report.password.push({ w, p, id: t.id, size: `${t.w}x${t.h}` });
      }
      if (info.length && w === 390) await shot(`password-toggle-${p.replace(/[^a-z]/g, '') || 'home'}-390`);
    }
  }
  // keyboard focus ring + admin form still posts (type=button does not submit)
  await c.viewport(1440); await c.setCookie(null); await go('/login');
  await evalJs(`document.getElementById('login-password').focus()`);
  await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 }); await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
  const ring = await evalJs(`(() => { const a = document.activeElement; const s = getComputedStyle(a); return { isToggle: a.hasAttribute('data-password-toggle'), outline: s.outlineStyle + ' ' + s.outlineWidth }; })()`);
  note(ring.isToggle && /solid 2px/.test(ring.outline), `keyboard Tab reaches the Show button with a visible focus ring (${ring.outline})`);
  await shot('password-toggle-focus-login-1440');

  // ---------- 3. Admin service photo link ----------
  await c.setCookie(adminCookie);
  for (const w of [1440, 390]) {
    await c.viewport(w); await go('/admin/service-inquiries/3');
    const ph = await evalJs(`(async () => { const a = document.querySelector('a.si-photo'); if (!a) return null; const r = a.getBoundingClientRect(); const img = a.querySelector('img'); const res = await fetch(a.href, { credentials: 'same-origin' }); return { href: a.getAttribute('href'), target: a.target, rel: a.rel, w: Math.round(r.width), h: Math.round(r.height), alt: img.alt, aria: a.getAttribute('aria-label'), loaded: img.complete && img.naturalWidth > 0, status: res.status, ctype: res.headers.get('content-type'), cache: res.headers.get('cache-control') }; })()`);
    report.photo[w] = ph;
    note(!!ph && ph.target === '_blank' && /noopener/.test(ph.rel) && /noreferrer/.test(ph.rel), `${w}px photo link opens in a new tab with rel="${ph && ph.rel}"`);
    note(!!ph && ph.w >= 44 && ph.h >= 44, `${w}px photo link target ${ph && ph.w}x${ph && ph.h}`);
    note(!!ph && ph.status === 200 && /^image\//.test(ph.ctype || '') && ph.loaded, `${w}px photo route answers ${ph && ph.status} ${ph && ph.ctype}, thumbnail loaded=${ph && ph.loaded}`);
    note(!!ph && !!ph.aria && /in a new tab/i.test(ph.aria) && /Reference photo/.test(ph.alt), `${w}px photo link has a meaningful name: "${ph && ph.aria}"`);
    if (w === 1440) await shot('admin-ticket-photo-1440');
  }
  await c.setCookie(null);
  const anon = await evalJs(`(async () => { const r = await fetch('/admin/service-inquiries/3/photos/0', { redirect: 'manual' }); return { type: r.type, status: r.status }; })()`);
  note(anon.type === 'opaqueredirect' || anon.status >= 300, 'the photo route is not open without an admin session (' + JSON.stringify(anon) + ')');

  // ---------- 4. Mark read ----------
  for (const w of [1440, 390]) {
    await c.viewport(w);
    for (const [p, ck, label] of [['/admin/notifications', adminCookie, 'admin'], ['/notifications', customerCookie, 'customer']]) {
      await c.setCookie(ck); await go(p);
      const btns = await evalJs(`[...document.querySelectorAll('button')].filter((b) => /^mark (all as )?read$/i.test(b.textContent.trim())).map((b) => { const r = b.getBoundingClientRect(); return { text: b.textContent.trim(), w: Math.round(r.width), h: Math.round(r.height) }; })`);
      report.markRead.push({ w, label, btns });
      note(btns.length > 0 && btns.every((b) => b.h >= 44 && b.w >= 44), `${w}px ${label}: ${btns.length} Mark read button(s) ${JSON.stringify(btns.slice(0, 2))}`);
      if (w === 390) await shot(`mark-read-${label}-390`);
    }
  }

  // ---------- 5. Chips and pills ----------
  const CHIPS = '.filter-chip, .admin-pill, .order-status-pill, .co-chip, .si-chip, .co-quote-chip, .lp-btn--chip';
  const chipPages = [['/', null], ['/catalog', null], ['/orders', customerCookie], ['/admin/orders', adminCookie], ['/admin/custom-orders', adminCookie], ['/admin/service-inquiries', adminCookie], ['/admin/service-inquiries/3', adminCookie], ['/admin/reviews', adminCookie]];
  for (const w of [1440, 390]) {
    await c.viewport(w);
    for (const [p, ck] of chipPages) {
      await c.setCookie(ck); await go(p);
      const r = await evalJs(`(() => { const els = [...document.querySelectorAll(${JSON.stringify(CHIPS)})].filter((e) => e.offsetParent !== null); const rows = {}; for (const e of els) { const b = e.getBoundingClientRect(); const key = (e.parentElement && e.parentElement.className) + '|' + Math.round(b.top / 6); (rows[key] = rows[key] || []).push(Math.round(b.height * 10) / 10); }
        const uneven = Object.entries(rows).filter(([, hs]) => Math.max(...hs) - Math.min(...hs) > 1).map(([k, hs]) => k + ' ' + hs.join('/'));
        const wrapped = els.filter((e) => { const cs = getComputedStyle(e); return e.getBoundingClientRect().height > parseFloat(cs.lineHeight === 'normal' ? parseFloat(cs.fontSize) * 1.3 : cs.lineHeight) * 1.7 + parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) && !e.matches('.lp-btn'); }).map((e) => e.textContent.trim().slice(0, 30));
        const clipped = els.filter((e) => e.scrollWidth > e.clientWidth + 1).map((e) => e.textContent.trim().slice(0, 30));
        return { count: els.length, uneven, wrapped, clipped, overflow: document.documentElement.scrollWidth > innerWidth }; })()`);
      report.chips.push({ w, p, ...r });
      note(r.uneven.length === 0 && r.wrapped.length === 0 && r.clipped.length === 0 && !r.overflow, `${w}px ${p}: ${r.count} chips, uneven rows ${JSON.stringify(r.uneven)}, wrapped ${JSON.stringify(r.wrapped)}, clipped ${JSON.stringify(r.clipped)}, page overflow ${r.overflow}`);
      if (r.count && (p === '/admin/custom-orders' || p === '/catalog')) await shot(`chips-${p.replace(/[^a-z]/g, '')}-${w}`);
    }
  }

  // ---------- 6. Quick view: real touch taps on every card at 390 ----------
  await c.setCookie(null);
  await c.viewport(390, 844);
  for (const p of ['/', '/product/2', '/catalog']) {
    await go(p);
    await evalJs(`document.documentElement.style.scrollBehavior = 'auto'; document.querySelectorAll('.carousel-track, [class*="track"]').forEach((t) => t.style.scrollBehavior = 'auto')`);
    const ids = await evalJs(`[...new Set([...document.querySelectorAll('button[data-quick-view]')].map((b) => b.dataset.quickView))]`);
    let opened = 0; const fails = [];
    for (const id of ids) {
      if ((await evalJs('location.pathname')) !== (p === '/product/2' ? '/product/2' : p)) { fails.push({ id, navigatedAway: await evalJs('location.pathname') }); await go(p); }
      const pt = await evalJs(`(async () => { const b = document.querySelector('button[data-quick-view="${id}"]'); b.scrollIntoView({ block: 'center', inline: 'center' }); await new Promise((r) => setTimeout(r, 120)); const r = b.getBoundingClientRect(); const x = r.left + r.width / 2, y = r.top + r.height / 2; const e = document.elementFromPoint(x, y); return { x, y, h: Math.round(r.height), hit: e === b || b.contains(e), over: e && (e.tagName + '.' + e.className) }; })()`);
      await tap(pt.x, pt.y); await sleep(500);
      const open = await evalJs(`(() => { const m = document.getElementById('cxMod'); return !!m && m.classList.contains('on') && !m.hidden; })()`);
      if (open && pt.hit && pt.h >= 44) opened++; else fails.push({ id, open, ...pt });
      if (open && id === ids[0]) await shot(`quickview-real-tap-${p.replace(/[^a-z0-9]/g, '') || 'home'}-390`);
      await evalJs(`(() => { const x = document.getElementById('cxClose'); if (x && !document.getElementById('cxMod').hidden) x.click(); })()`); await sleep(250);
    }
    report.quickView.push({ p, cards: ids.length, openedByRealTap: opened, fails });
    note(ids.length > 0 && opened === ids.length, `390px ${p}: ${opened}/${ids.length} Quick view buttons opened by a real touch tap (no element on top, at least 44px high) ${JSON.stringify(fails)}`);
  }

  fs.writeFileSync(path.join(OUT, '_checks-part1.json'), JSON.stringify({ failures: bad, report }, null, 1));
  console.log(bad.length ? `\n${bad.length} CHECK(S) FAILED` : '\nall checks ok');
  c.close();
  process.exit(bad.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
