/*
 * Navbar + notifications walk (SCRATCH DATABASE ONLY, http://127.0.0.1:8011). Screenshots go to docs/navbar/screenshots/<mode>/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_notif_nav.php
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-notif-nav.cjs main            (customer walk)
 *   node --experimental-websocket tests/UiLinks/browser-notif-nav.cjs admin-before    (admin pages, run on the old code)
 *   node --experimental-websocket tests/UiLinks/browser-notif-nav.cjs admin-after
 *   node --experimental-websocket tests/UiLinks/image-diff.cjs docs/navbar/screenshots/admin-before docs/navbar/screenshots/admin-after
 *
 * Sessions come from tests/UiLinks/session-cookie-for.php (the real sign-in has reCAPTCHA, which is not bypassed).
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { start, ROOT } = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'main';
const OUT = path.join(ROOT, 'docs', 'navbar', 'screenshots', MODE);
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const logins = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
const cookieFor = (guard) => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), guard === 'admin' ? logins.admin_email : logins.customer_email, guard], { cwd: ROOT, encoding: 'utf8' }));

const bad = [];
const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

(async () => {
  const c = await start(9352);
  const { send, evalJs, go, viewport, setCookie, sleep } = c;
  const shot = async (name, clip) => {
    const params = { format: 'png' };
    if (clip) params.clip = { ...clip, scale: 1 };
    const r = await send('Page.captureScreenshot', params);
    fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64'));
  };
  const click = async (sel, wait = 1100) => {
    const p = await evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; e.scrollIntoView({block:'center'}); const b = e.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
    if (!p) throw new Error('no element ' + sel);
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: p.x, y: p.y });
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: p.x, y: p.y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: p.x, y: p.y, button: 'left', clickCount: 1 });
    await sleep(wait);
  };
  const key = async (k, code, vk) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: vk }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk }); await sleep(250); };
  const setDate = async (sel, v) => { await evalJs(`(() => { const i = document.querySelector(${JSON.stringify(sel)}); i.value = ${JSON.stringify(v)}; i.dispatchEvent(new Event('change', { bubbles: true })); })()`); await sleep(1100); };

  // ───────────────────────── admin pages (before / after the change) ─────────────────────────
  if (MODE.startsWith('admin')) {
    await setCookie(cookieFor('admin'));
    await viewport(1440, 900);
    for (const [name, url] of [['dashboard', '/admin/dashboard'], ['orders', '/admin/orders'], ['custom-orders', '/admin/custom-orders'], ['notifications', '/admin/notifications']]) {
      await go(url);
      await evalJs(`(() => { const s = document.createElement('style'); s.textContent = '*{animation:none!important;transition:none!important;caret-color:transparent!important}'; document.head.appendChild(s); })()`);
      await shot('admin-' + name + '-1440');
    }
    await viewport(390, 844);
    await go('/admin/dashboard');
    await shot('admin-dashboard-390');
    console.log('admin shots saved to', OUT);
    c.close();
    return;
  }

  // ───────────────────────── customer ─────────────────────────
  await setCookie(cookieFor('web'));
  await viewport(1440, 900);
  await go('/catalog');

  // 1) navbar: icons are the height of the words and sit on the text line
  const icons = await evalJs(`(() => [...document.querySelectorAll('#sn-links .sn-icon')].map(a => {
    const s = a.querySelector('svg').getBoundingClientRect(), l = (a.querySelector('.sn-label') || a.querySelector('span')).getBoundingClientRect(), fs = parseFloat(getComputedStyle(a).fontSize);
    return { text: a.textContent.trim().replace(/\\s+/g, ' ').slice(0, 20), h: s.height, fs, ratio: +(s.height / fs).toFixed(2), dy: +Math.abs((s.top + s.height / 2) - (l.top + l.height / 2)).toFixed(1), stroke: getComputedStyle(a.querySelector('svg')).strokeWidth };
  }))()`);
  console.log(JSON.stringify(icons));
  icons.forEach((i) => note(i.ratio > 0.95 && i.ratio <= 1.2 && i.dy <= 2, `icon "${i.text}" ${i.h.toFixed(1)}px vs text ${i.fs}px (ratio ${i.ratio}), ${i.dy}px off the text centre, stroke ${i.stroke}`));
  note(icons.some((i) => /My Orders/.test(i.text)), 'My Orders item is in the bar');
  const order = await evalJs(`[...document.querySelectorAll('#sn-links > a, #sn-links > button')].map(a => a.textContent.trim().replace(/\\s+/g, ' ').replace(/ \\(.*$/, '')).join(' | ')`);
  console.log('nav order:', order);
  const navFit = await evalJs(`(() => { const n = document.getElementById('site-nav'); const l = document.getElementById('sn-links'); return { navW: n.clientWidth, scroll: n.scrollWidth, links: l.getBoundingClientRect().right, nav: n.getBoundingClientRect().right }; })()`);
  note(navFit.scroll <= navFit.navW + 1, `nav does not overflow at 1440 (${JSON.stringify(navFit)})`);
  await shot('01-navbar-1440', { x: 0, y: 0, width: 1440, height: 110 });
  await shot('01b-navbar-icons-zoom', { x: 560, y: 10, width: 880, height: 80 });

  // narrowest width where the inline bar shows (1001) must still fit
  await viewport(1001, 800); await go('/catalog');
  const fit1001 = await evalJs(`(() => { const n = document.getElementById('site-nav'); return { navW: n.clientWidth, scroll: n.scrollWidth, wraps: getComputedStyle(document.getElementById('sn-links')).flexWrap, h: document.getElementById('site-nav').offsetHeight }; })()`);
  note(fit1001.scroll <= fit1001.navW + 1 && fit1001.h <= 66, `inline nav fits at 1001px (${JSON.stringify(fit1001)})`);
  await shot('01c-navbar-1001', { x: 0, y: 0, width: 1001, height: 110 });
  await viewport(1440, 900); await go('/catalog');

  // 2) Luma: type a message, close the chat, open the dropdown
  await evalJs(`toggleChatbot()`); await sleep(600);
  await evalJs(`(() => { const i = document.getElementById('chat-input'); i.value = 'how long does a dining table take'; sendChat(); })()`);
  await sleep(2500);
  const lumaBefore = await evalJs(`(() => ({ msgs: document.querySelectorAll('#chat-body .chat-msg').length, user: [...document.querySelectorAll('#chat-body .chat-msg.user')].map(x => x.textContent.trim()) }))()`);
  note(lumaBefore.user.length === 1, 'typed a message in Luma (' + JSON.stringify(lumaBefore.user) + ', ' + lumaBefore.msgs + ' messages)');
  await shot('02-luma-open-before-1440');
  await evalJs(`closeChatbot()`); await sleep(500);
  const vis = (sel) => evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); return !!e && getComputedStyle(e).display !== 'none' && getComputedStyle(e).visibility !== 'hidden' && e.getBoundingClientRect().width > 0; })()`);
  note(await vis('#chatbot-fab-btn'), 'Luma launcher visible before the dropdown opens');

  // 3) open the dropdown by clicking the bell
  await click('#sn-bell');
  note(await evalJs(`!document.getElementById('notif-dd').hidden`), 'bell click opens the dropdown');
  note((await evalJs(`document.getElementById('sn-bell').getAttribute('aria-expanded')`)) === 'true', 'aria-expanded=true on the bell');
  note(!(await vis('#chatbot-fab-btn')) && !(await vis('#chatbot-window')), 'Luma launcher and chat window are hidden while the dropdown is open');
  const geo = await evalJs(`(() => { const d = document.getElementById('notif-dd').getBoundingClientRect(), b = document.getElementById('sn-bell').getBoundingClientRect(), n = document.getElementById('site-nav').getBoundingClientRect(); return { w: Math.round(d.width), top: Math.round(d.top), navBottom: Math.round(n.bottom), right: Math.round(d.right), bellRight: Math.round(b.right), vw: innerWidth }; })()`);
  note(Math.abs(geo.w - 440) <= 2 && geo.top >= geo.navBottom, 'panel is 440px wide, under the bar: ' + JSON.stringify(geo));
  const rows = await evalJs(`(() => { const r = [...document.querySelectorAll('#notif-dd .nf-ctl > *')].map(e => Math.round(e.getBoundingClientRect().top)); return { tops: r, scrollW: document.getElementById('notif-dd').scrollWidth, clientW: document.getElementById('notif-dd').clientWidth }; })()`);
  note(Math.max(...rows.tops) - Math.min(...rows.tops) <= 4 && rows.scrollW <= rows.clientW + 1, 'three controls in one row, no sideways scroll: ' + JSON.stringify(rows));
  const feed = await evalJs(`(() => ({ n: document.querySelectorAll('#notif-dd .nf-item').length, count: document.querySelector('#notif-dd [data-nf-count]').textContent, views: [...document.querySelectorAll('#notif-dd .nf-item')].filter(i => i.querySelector('.nf-view')).length, titles: [...document.querySelectorAll('#notif-dd .nf-item-title')].slice(0, 8).map(t => t.textContent.trim()), xss: window.__xss || null, script: document.querySelectorAll('#notif-dd script').length }))()`);
  console.log(JSON.stringify(feed));
  note(feed.n === 20 && feed.count === '4 unread', 'first 20 rows, count "3 unread" (' + feed.n + ', ' + feed.count + ')');
  note(!feed.xss && feed.script === 0, 'notification text is not executed (no script in the list)');
  const idItem = await evalJs(`(() => { const t = [...document.querySelectorAll('#notif-dd .nf-item')].find(i => /Identity Verified/.test(i.textContent)); return t ? { view: !!t.querySelector('.nf-view'), mark: !!t.querySelector('[data-nf-read]'), buttonText: [...t.querySelectorAll('a,button')].map(x => x.textContent.trim()) } : null; })()`);
  note(idItem && !idItem.view && idItem.mark, 'Identity Verified: Mark read only, no View details ' + JSON.stringify(idItem));
  const ordItem = await evalJs(`(() => { const t = [...document.querySelectorAll('#notif-dd .nf-item')].find(i => /In Production/.test(i.textContent)); return t ? { href: t.querySelector('.nf-view')?.getAttribute('href'), mark: !!t.querySelector('[data-nf-read]') } : null; })()`);
  note(ordItem && /^\/orders\/\d+$/.test(ordItem.href || ''), 'order item View details opens its order ' + JSON.stringify(ordItem));
  const arrows = await evalJs(`[...document.querySelectorAll('#notif-dd .lp-btn, #notif-dd .lp-link')].filter(b => b.querySelector('svg') || /[→←>]/.test(b.textContent)).length`);
  note(arrows === 0, 'no arrows or icons inside the buttons');
  await shot('03-dropdown-open-1440');

  // 4) Only show unread
  await click('#notif-dd .lp-switch');
  const unreadOnly = await evalJs(`[...document.querySelectorAll('#notif-dd .nf-item')].map(i => i.classList.contains('nf-item--unread'))`);
  note(unreadOnly.length === 4 && unreadOnly.every(Boolean), 'Only show unread: 4 unread rows (' + unreadOnly.length + ')');
  await shot('04-dropdown-unread-1440');

  // 5) Mark read from the unread-only list: row leaves, count and bell dot update, no reload
  await evalJs(`window.__marker = 'same-page'`);
  await click('#notif-dd [data-nf-read]');
  const afterMark = await evalJs(`(() => ({ marker: window.__marker, n: document.querySelectorAll('#notif-dd .nf-item').length, count: document.querySelector('#notif-dd [data-nf-count]').textContent, dotHidden: document.getElementById('sn-bell-dot').hidden }))()`);
  note(afterMark.marker === 'same-page' && afterMark.n === 3 && afterMark.count === '3 unread' && !afterMark.dotHidden, 'Mark read updates in place, no reload: ' + JSON.stringify(afterMark));

  // switch off, mark one read inside the full list and see the row change in place
  await click('#notif-dd .lp-switch');
  await click('#notif-dd [data-nf-read]');
  const inPlace = await evalJs(`(() => ({ n: document.querySelectorAll('#notif-dd .nf-item').length, unread: document.querySelectorAll('#notif-dd .nf-item--unread').length, count: document.querySelector('#notif-dd [data-nf-count]').textContent }))()`);
  note(inPlace.n === 20 && inPlace.unread === 2 && inPlace.count === '2 unread', 'Mark read in the full list changes only that row: ' + JSON.stringify(inPlace));

  // 6) Dates
  await click('#notif-dd [data-nf-act="dates"]');
  note(await evalJs(`!document.getElementById('nfd-dates').hidden && document.querySelector('#notif-dd [data-nf-act="dates"]').getAttribute('aria-expanded') === 'true'`), 'Dates reveals From, To and Clear');
  const today = new Date(); const iso = (d) => d.toISOString().slice(0, 10);
  const d = (n) => iso(new Date(today.getTime() - n * 86400000));
  await setDate('#notif-dd [data-nf-from]', d(30));
  await setDate('#notif-dd [data-nf-to]', d(5));
  const ranged = await evalJs(`[...document.querySelectorAll('#notif-dd .nf-item-title')].map(t => t.textContent.trim())`);
  note(ranged.length === 2 && ranged.some((t) => /Password/.test(t)) && ranged.some((t) => /Design/.test(t)), 'date range shows the rows inside it: ' + JSON.stringify(ranged));
  await shot('05-dropdown-dates-1440');
  await setDate('#notif-dd [data-nf-from]', '2030-01-01'); await setDate('#notif-dd [data-nf-to]', '');
  const emptyTxt = await evalJs(`document.querySelector('#notif-dd .nf-empty-inline')?.textContent.trim()`);
  note(/No notifications match\./.test(emptyTxt || ''), 'empty state: ' + emptyTxt);
  await shot('06-dropdown-empty-1440');
  await click('#notif-dd .nf-empty-inline [data-nf-act="clear"]');
  note((await evalJs(`document.querySelectorAll('#notif-dd .nf-item').length`)) === 20, 'Clear filters brings the list back');

  // 7) Mark all as read
  await click('#notif-dd [data-nf-act="markall"]', 2600);
  const all = await evalJs(`(() => ({ unread: document.querySelectorAll('#notif-dd .nf-item--unread').length, count: document.querySelector('#notif-dd [data-nf-count]').textContent, dotHidden: document.getElementById('sn-bell-dot').hidden }))()`);
  note(all.unread === 0 && all.count === 'All caught up' && all.dotHidden, 'Mark all as read: ' + JSON.stringify(all));
  await shot('07-dropdown-all-read-1440');

  // 8) Escape: closes, focus back on the bell, Luma returns with the SAME conversation (chat was closed, stays closed)
  await key('Escape', 'Escape', 27);
  note(await evalJs(`document.getElementById('notif-dd').hidden`), 'Escape closes the dropdown');
  note(await evalJs(`document.activeElement.id === 'sn-bell'`), 'focus returns to the bell');
  note((await evalJs(`document.getElementById('sn-bell').getAttribute('aria-expanded')`)) === 'false', 'aria-expanded=false again');
  note(await vis('#chatbot-fab-btn'), 'Luma launcher is back');
  note(!(await evalJs(`document.getElementById('chatbot-window').classList.contains('open')`)), 'chat was closed before, stays closed');
  await evalJs(`openChatbot()`); await sleep(500);
  const lumaAfter = await evalJs(`(() => ({ msgs: document.querySelectorAll('#chat-body .chat-msg').length, user: [...document.querySelectorAll('#chat-body .chat-msg.user')].map(x => x.textContent.trim()) }))()`);
  note(JSON.stringify(lumaAfter) === JSON.stringify(lumaBefore), 'same Luma conversation after the dropdown closed: ' + JSON.stringify(lumaAfter));
  await shot('08-luma-restored-1440');

  // 9) chat OPEN while the dropdown opens: hidden, then reopens as it was. (The bar now stays visible while the chat is open, so the bell is clicked for real.)
  await click('#sn-bell');
  note(await evalJs(`!document.getElementById('notif-dd').hidden`), 'dropdown opens even with the chat open');
  note(!(await vis('#chatbot-window')) && !(await vis('#chatbot-fab-btn')), 'open chat window is hidden while the dropdown is open');
  await shot('09-luma-hidden-chat-was-open-1440');
  await key('Escape', 'Escape', 27);
  note(await evalJs(`document.getElementById('chatbot-window').classList.contains('open')`) && await vis('#chatbot-window'), 'chat reopens by itself because it was open (Escape closed only the dropdown)');
  const lumaAfter2 = await evalJs(`document.querySelectorAll('#chat-body .chat-msg.user').length`);
  note(lumaAfter2 === 1, 'conversation still there (' + lumaAfter2 + ' of your messages)');
  await shot('10-luma-reopened-1440');
  await evalJs(`closeChatbot()`);

  // 10) outside click closes; Tab order
  await click('#sn-bell'); await click('main');
  note(await evalJs(`document.getElementById('notif-dd').hidden`), 'clicking outside closes the dropdown');
  await click('#sn-bell');
  note(await evalJs(`document.activeElement.closest('#notif-dd') !== null`), 'focus moves into the panel on open');
  await evalJs(`document.getElementById('notif-dd').querySelector('button, input, a').focus()`);
  await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9, modifiers: 8 }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 }); await sleep(200);
  note(await evalJs(`document.activeElement.id === 'sn-bell'`), 'Shift+Tab from the first control goes back to the bell');
  await key('Escape', 'Escape', 27);

  // 11) contrast (buttons audit) with the dropdown open
  await click('#sn-bell');
  const btnAudit = await evalJs(`(${BUTTONS.trim().replace(/;\s*$/, '')})`).catch(async () => evalJs(BUTTONS));
  const failures = (btnAudit && btnAudit.failures) || [];
  note(failures.length === 0, `button contrast audit with the dropdown open: ${btnAudit ? btnAudit.count : '?'} buttons, ${failures.length} failures ${failures.length ? JSON.stringify(failures).slice(0, 400) : ''}`);
  await key('Escape', 'Escape', 27);

  // 12) full page at 1440 (+ filters via the query string)
  await go('/notifications');
  note(!(await evalJs(`/Order History/.test(document.body.innerText)`)), 'no Order History button on the page');
  await shot('11-page-1440');
  await go('/notifications?unread=1&from=2026-01-01&to=2026-02-28');
  await shot('12-page-filtered-1440');
  await go('/notifications?from=2030-01-01');
  note(await evalJs(`/No notifications match\\./.test(document.body.innerText)`), 'page empty state');
  await shot('13-page-empty-1440');

  // ───────────────── phone, 390 ─────────────────
  await viewport(390, 844);
  await go('/catalog');
  const ph = await evalJs(`(() => ({ burger: getComputedStyle(document.getElementById('sn-burger')).display, dd: getComputedStyle(document.getElementById('notif-dd')).display, bellHref: document.getElementById('sn-bell').getAttribute('href') }))()`);
  note(ph.burger !== 'none' && ph.dd === 'none', 'phone: burger menu, no dropdown ' + JSON.stringify(ph));
  await click('#sn-burger');
  const menu = await evalJs(`[...document.querySelectorAll('#sn-links > a, #sn-links > button')].map(a => a.textContent.trim().replace(/\\s+/g, ' ').replace(/ \\(.*$/, '')).join(' | ')`);
  note(/Assistant \| My Orders \| Cart/.test(menu), 'phone menu: ' + menu);
  const menuIcons = await evalJs(`[...document.querySelectorAll('#sn-links .sn-icon svg')].map(s => +(s.getBoundingClientRect().height / parseFloat(getComputedStyle(s.parentElement).fontSize)).toFixed(2))`);
  note(menuIcons.every((r) => r > 0.95 && r <= 1.2), 'phone menu icons are text height: ' + JSON.stringify(menuIcons));
  await shot('14-phone-menu-390');
  await click('#sn-bell');
  await sleep(700);
  note((await evalJs(`location.pathname`)) === '/notifications', 'phone: the bell opens the full page');
  const pc = await evalJs(`(() => { const r = [...document.querySelectorAll('.nf-filter .nf-ctl > *')].map(e => { const b = e.getBoundingClientRect(); return { top: Math.round(b.top), h: Math.round(b.height), right: Math.round(b.right) }; }); return { items: r, docW: document.documentElement.scrollWidth, winW: innerWidth, lastRight: Math.max(...r.map(x => x.right)) }; })()`);
  note(Math.max(...pc.items.map((x) => x.top)) - Math.min(...pc.items.map((x) => x.top)) <= 6 && pc.docW <= pc.winW && pc.lastRight <= pc.winW - 8, 'phone controls in one row, no sideways scroll: ' + JSON.stringify(pc));
  note(pc.items.every((x) => x.h >= 44), 'phone controls are at least 44px tall: ' + pc.items.map((x) => x.h).join(','));
  const tap = await evalJs(`[...document.querySelectorAll('.nf-item .lp-btn, .nf-item .lp-link')].map(b => Math.round(b.getBoundingClientRect().height))`);
  note(tap.length > 0 && tap.every((h) => h >= 44), 'phone card buttons are at least 44px tall: ' + [...new Set(tap)].join(','));
  await shot('15-phone-page-390');
  await click('.nf-filter [data-nf-act="dates"]');
  await shot('16-phone-page-dates-390');
  // the form really filters (query string), also from the phone
  await evalJs(`document.getElementById('nfp-unread').click()`); await sleep(900);
  note(/unread=1/.test(await evalJs(`location.search`)), 'phone switch navigates to ?unread=1: ' + (await evalJs(`location.search`)));
  note(await evalJs(`document.getElementById('nfp-unread').checked`), 'switch stays on after the reload');
  await shot('17-phone-page-unread-390');
  await go('/notifications?from=2030-01-01');
  await shot('18-phone-page-empty-390');
  await go('/notifications');
  const phBtn = await evalJs(`(${BUTTONS.trim().replace(/;\s*$/, '')})`).catch(async () => evalJs(BUTTONS));
  note(((phBtn && phBtn.failures) || []).length === 0, `phone page button audit: ${phBtn ? phBtn.count : '?'} buttons, ${((phBtn && phBtn.failures) || []).length} failures`);
  await go('/notifications?page=2');
  await shot('19-phone-page-2-390');

  // text contrast on the dropdown rows and page rows (computed colours, composited over the card/cream background)
  await viewport(1440, 900);
  await go('/notifications');
  const ratios = await evalJs(`(() => {
    const lin = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
    const lum = ([r, g, b]) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    const parse = s => { const m = s.match(/[\\d.]+/g).map(Number); return { c: m.slice(0, 3), a: m.length > 3 ? m[3] : 1 }; };
    const bgOf = el => { let layers = []; for (let e = el; e; e = e.parentElement) { const p = parse(getComputedStyle(e).backgroundColor); if (p.a > 0) { layers.push(p); if (p.a >= 1) break; } } let base = [255, 255, 255]; for (const l of layers.reverse()) base = base.map((v, i) => v * (1 - l.a) + l.c[i] * l.a); return base; };
    const out = [];
    for (const sel of ['.nf-item-title', '.nf-text', '.nf-when', '.nf-meta .lp-link', '.nf-count', '.nf-title', '.nf-ctl .lp-switch-label']) {
      const el = document.querySelector(sel); if (!el) continue;
      const fg = parse(getComputedStyle(el).color).c, bg = bgOf(el);
      const L1 = lum(fg), L2 = lum(bg); out.push({ sel, ratio: +((Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05)).toFixed(2) });
    }
    const unread = document.querySelector('.nf-item--unread .nf-text'); if (unread) { const fg = parse(getComputedStyle(unread).color).c, bg = bgOf(unread); const L1 = lum(fg), L2 = lum(bg); out.push({ sel: 'unread .nf-text', ratio: +((Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05)).toFixed(2) }); }
    return out; })()`);
  console.log(JSON.stringify(ratios));
  note(ratios.length > 5 && ratios.every((r) => r.ratio >= 4.5), 'text contrast on the page >= 4.5: ' + ratios.map((r) => r.sel + ' ' + r.ratio).join(', '));

  const missing = [];
  console.log(bad.length ? `\n${bad.length} CHECK(S) FAILED` : '\nall checks passed');
  c.close();
  process.exitCode = bad.length ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
