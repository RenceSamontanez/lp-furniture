/*
 * Admin modals walk (SCRATCH DATABASE ONLY, server on 127.0.0.1:8011): Chatbot Training, Contact Settings and System Health.
 *   1. real clicks: add / edit / deactivate / delete an intent, search and filter, Unanswered Questions tab and Dismiss,
 *      both Contact Settings cards (valid save, invalid save with the modal reopened and values kept), Clear Cache with its
 *      confirmation, and the footer / checkout Need Help / chatbot reply showing the saved contact details
 *   2. proof at 1440, 1001 and 390 px: screenshots of every page and modal state, overflow, clipped text, every modal fully visible
 *      and above the sidebar and top bar with the page scrolled, computed fonts, text contrast.
 *
 *   php artisan chatbot:sync-defaults           (scratch DB, so the list has rows and pages)
 *   node --experimental-websocket tests/UiLinks/browser-admin-modals.cjs [functional|proof|all]
 * Screenshots: docs/admin-modals-screenshots/. It changes scratch rows (an intent, contact settings).
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(ROOT, 'docs', 'admin-modals-screenshots');
const sql = (q) => JSON.parse(execSync(`php tests/UiLinks/sql-scratch.php "${q}"`, { cwd: ROOT }).toString());
const cookie = JSON.parse(execSync('php tests/UiLinks/session-cookie-for.php ot-admin@example.test admin', { cwd: ROOT }).toString());
const results = [];
const note = (ok, msg) => { results.push(ok); console.log((ok ? 'ok    ' : 'FAIL  ') + msg); };
const report = { pages: {} };

const LAYOUT = `(() => {
  const o = { overflowX: document.documentElement.scrollWidth - innerWidth, clipped: [], lowContrast: [] };
  const vis = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const lab = (e) => e.tagName.toLowerCase() + (typeof e.className === 'string' && e.className ? '.' + e.className.trim().split(/\\s+/)[0] : '') + ' "' + (e.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 36) + '"';
  document.querySelectorAll('button, a.admin-btn, .admin-btn, .lp-btn, .admin-pill, .admin-filter-tab, .admin-nav-link, th, td, label, h2, .admin-panel-title, .admin-stat-label, .ct-kv dt, .ct-kv dd, .am-help, .am-err').forEach((e) => {
    if (!vis(e) || e.closest('svg')) return;
    const s = getComputedStyle(e);
    if (e.scrollWidth > e.clientWidth + 1 && (s.overflowX === 'hidden' || s.textOverflow === 'ellipsis' || s.overflowX === 'clip') && !e.matches('.cb-rs, .admin-topbar-title')) o.clipped.push(lab(e));
  });
  const parse = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(',').map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const over = (t, b) => ({ r: t.r * t.a + b.r * (1 - t.a), g: t.g * t.a + b.g * (1 - t.a), b: t.b * t.a + b.b * (1 - t.a), a: 1 });
  const bgOf = (e) => { const st = []; for (let p = e; p; p = p.parentElement) { const s = getComputedStyle(p); if (s.backgroundImage !== 'none') return null; const c = parse(s.backgroundColor); if (c && c.a > 0) { st.push(c); if (c.a >= 1) break; } } let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = st.length - 1; i >= 0; i--) base = over(st[i], base); return base; };
  const seen = new Set();
  document.querySelectorAll('body *').forEach((e) => {
    if (!vis(e) || ['SCRIPT', 'STYLE'].includes(e.tagName) || e.closest('svg') || e.disabled) return;
    const own = [...e.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim()).length; if (!own) return;
    const s = getComputedStyle(e); const fg = parse(s.color), bg = bgOf(e); if (!fg || !bg) return;
    const pulse = s.animationName && s.animationName !== 'none';
    const f = over({ ...fg, a: fg.a * (pulse ? 1 : (parseFloat(s.opacity) || 1)) }, bg);
    const l1 = lum(f), l2 = lum(bg), ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const px = parseFloat(s.fontSize), bold = parseInt(s.fontWeight, 10) >= 700, need = (px >= 24 || (px >= 18.66 && bold)) ? 3 : 4.5;
    if (ratio < need) { const k = lab(e) + s.color; if (!seen.has(k)) { seen.add(k); o.lowContrast.push({ el: lab(e), ratio: Math.round(ratio * 100) / 100 }); } }
  });
  return o;
})()`;

// is the open modal fully visible, and does it sit above the sidebar and the top bar?
const MODAL_CHECK = `(() => {
  const m = [...document.querySelectorAll('.lp-modal')].find((x) => !x.hidden); if (!m) return { open: false };
  const box = m.querySelector('.lp-modal__box').getBoundingClientRect();
  const vw = innerWidth, vh = innerHeight;
  const top = (x, y) => document.elementFromPoint(x, y);
  const inside = (el) => !!el && (el === m || m.contains(el));
  const side = document.querySelector('.admin-sidebar'), bar = document.querySelector('.admin-topbar');
  const probe = (el) => { if (!el) return true; const r = el.getBoundingClientRect(); if (r.width === 0) return true; return inside(top(Math.min(vw - 2, Math.max(2, r.left + r.width / 2)), Math.min(vh - 2, Math.max(2, r.top + Math.min(r.height / 2, 40))))); };
  const foot = m.querySelector('.lp-modal__foot').getBoundingClientRect();
  const btns = [...m.querySelectorAll('.lp-modal__foot .lp-btn')].map((b) => { const r = b.getBoundingClientRect(); return r.top >= -1 && r.bottom <= vh + 1 && r.left >= -1 && r.right <= vw + 1; });
  const body = m.querySelector('.lp-modal__body');
  const z = parseInt(getComputedStyle(m).zIndex, 10);
  const toast = document.querySelector('.admin-toast, .lp-toast'); const tz = toast ? parseInt(getComputedStyle(toast).zIndex, 10) || 0 : 0;
  return { open: true, boxInViewport: box.top >= -1 && box.left >= -1 && box.right <= vw + 1 && box.bottom <= vh + 1, aboveSidebar: probe(side), aboveTopbar: probe(bar), footerVisible: btns.length > 0 && btns.every(Boolean), footBottomInViewport: foot.bottom <= vh + 1,
           bodyScrolls: body.scrollHeight > body.clientHeight, pageLocked: getComputedStyle(document.body).overflow === 'hidden' || getComputedStyle(document.documentElement).overflow === 'hidden', z, aboveToasts: z > tz,
           mountedOnBody: m.parentElement === document.body, sheet: Math.abs(box.bottom - vh) < 2 && Math.abs(box.width - vw) < 2 };
})()`;

const STYLES = `(() => { const pick = (q) => { const e = document.querySelector(q); if (!e) return null; const s = getComputedStyle(e); return { family: s.fontFamily.split(',')[0].replace(/["']/g, ''), size: s.fontSize, weight: s.fontWeight, text: (e.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 28) }; };
  return { pageTitle: pick('.admin-topbar-title'), tableHeader: pick('.admin-table th'), tableCell: pick('.admin-table td'), button: pick('.admin-btn'), label: pick('.form-label, .ct-kv dt'), modalTitle: pick('.lp-modal:not([hidden]) .lp-modal__title'), modalInput: pick('.lp-modal:not([hidden]) .form-input'), sidebar: pick('.admin-nav-link') }; })()`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await start(9400);
  await b.setCookie(cookie);
  const $ = (s) => `document.querySelector(${JSON.stringify(s)})`;
  const settle = async (ms = 1700) => { await sleep(ms); for (let i = 0; i < 30 && (await b.evalJs('document.readyState')) !== 'complete'; i++) await sleep(150); await sleep(350); };
  const click = (s) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.click(); return true;})()`);
  const clickText = (sel, t) => b.evalJs(`(()=>{const e=[...document.querySelectorAll(${JSON.stringify(sel)})].find(x=>x.textContent.trim().replace(/\\s+/g,' ')===${JSON.stringify(t)}); if(!e) return false; e.click(); return true;})()`);
  const setVal = (s, v) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true})); return true;})()`);
  const where = () => b.evalJs('location.pathname + location.search');
  const body = () => b.evalJs('document.body.innerText');
  const openModalId = () => b.evalJs(`(()=>{const m=[...document.querySelectorAll('.lp-modal')].find(x=>!x.hidden); return m ? m.id : null})()`);

  if (MODE === 'functional' || MODE === 'all') {
    // ── Chatbot Training ────────────────────────────────────────────────────────────────────────
    await b.viewport(1440, 900);
    await b.go('/admin/chatbot');
    const rows0 = await b.evalJs(`document.querySelectorAll('.admin-table tbody tr').length`);
    note(rows0 > 0, `intents list shows ${rows0} rows with Category, Keywords, Response Preview, Status, Actions`);
    note((await b.evalJs(`[...document.querySelectorAll('.admin-table th')].map(t=>t.textContent.trim()).join('|')`)) === 'Category|Keywords|Response Preview|Status|Actions', 'table headings match the spec');
    // add
    await clickText('button', 'Add Intent'); await sleep(500);
    note((await openModalId()) === 'intent-add', 'Add Intent opens the modal');
    note((await b.evalJs(`document.activeElement && document.activeElement.closest('.lp-modal') !== null`)), 'focus moved into the modal');
    await click('#intent-add-form button[type=submit], [form=intent-add-form]'); await sleep(400);
    note(await b.evalJs(`!!${$('#intent-add .am-field.is-invalid .am-err')} && getComputedStyle(${$('#intent-add .am-field.is-invalid .am-err')}).display !== 'none'`), 'empty Save shows the red message and keeps the modal open (client check)');
    await setVal('#intent-add-form-keywords', 'walk intent, walk test');
    await setVal('#intent-add-form-response', 'Call us at {{phone}} or write to {{email}}.');
    await setVal('#intent-add-form-category', 'WalkCat');
    // dirty guard: Escape asks first
    await b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }); await sleep(300);
    note((await openModalId()) === 'intent-add' && (await b.evalJs(`!!${$('#intent-add .am-discard.is-on')}`)), 'Escape with typed text asks "Discard what you typed?" and keeps the modal');
    await click('#intent-add [data-keep]'); await sleep(200);
    note((await b.evalJs(`${$('#intent-add-form-keywords')}.value`)) === 'walk intent, walk test', 'Keep Editing keeps the typed values');
    await click('[form=intent-add-form]'); await settle();
    const created = sql("select id,is_active,category from chatbot_intents where keywords='walk intent, walk test'")[0];
    note(!!created && created.is_active == 1, 'intent saved (active) through the existing store route');
    note((await body()).includes('Intent added successfully.'), 'normal success notification shown');
    note((await openModalId()) === null, 'the modal is closed after a successful save');
    // server-side error reopens the modal
    await b.go('/admin/chatbot'); await clickText('button', 'Add Intent'); await sleep(300);
    await setVal('#intent-add-form-keywords', 'x'); await setVal('#intent-add-form-response', 'y'); await setVal('#intent-add-form-category', 'C'.repeat(120));
    await click('[form=intent-add-form]'); await settle();
    note((await openModalId()) === 'intent-add', 'server rejection reloads with the Add modal open again');
    note((await b.evalJs(`${$('#intent-add-form-keywords')}.value`)) === 'x' && (await b.evalJs(`${$('#intent-add-form-category')}.value.length`)) === 120, 'typed values kept');
    note((await b.evalJs(`(${$('#intent-add .am-field.is-invalid .am-err')}||{}).textContent`) || '').includes('category'), 'server message shown under the field');
    await b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }); await sleep(300);
    await click('#intent-add [data-discard]'); await sleep(300);
    note((await openModalId()) === null, 'Discard closes the modal');
    // edit
    await b.go('/admin/chatbot?search=walk+intent');
    note((await b.evalJs(`document.querySelectorAll('.admin-table tbody tr').length`)) === 1, 'search finds the new intent');
    await clickText('button', 'Edit'); await sleep(500);
    note(String(await openModalId()) === `intent-edit-${created.id}`, 'Edit opens that intent in the modal');
    await setVal(`#intent-edit-${created.id}-form-response`, 'Edited walk response.');
    await click(`[form=intent-edit-${created.id}-form]`); await settle();
    note(sql(`select response from chatbot_intents where id=${created.id}`)[0].response === 'Edited walk response.', 'edit saved through the existing update route');
    // deactivate
    await b.go('/admin/chatbot?search=walk+intent');
    await b.evalJs(`document.querySelector('.admin-pill').click()`); await settle();
    note(sql(`select is_active from chatbot_intents where id=${created.id}`)[0].is_active == 0, 'Active pill deactivates it');
    // filter by category
    await b.go('/admin/chatbot?category=WalkCat');
    note((await b.evalJs(`document.querySelectorAll('.admin-table tbody tr').length`)) === 1, 'category filter shows only WalkCat');
    // old URLs
    await b.go(`/admin/chatbot/${created.id}/edit`); await settle(1200);
    note((await where()).includes(`edit=${created.id}`) && (await openModalId()) === `intent-edit-${created.id}`, 'old edit URL redirects to the list with that modal open');
    await b.go('/admin/chatbot/create'); await settle(1200);
    note((await openModalId()) === 'intent-add', 'old add URL redirects to the list with the Add modal open');
    // delete
    await b.go('/admin/chatbot?search=walk+intent');
    await clickText('button', 'Delete'); await sleep(500);
    note((await openModalId()) === 'intent-delete' && (await b.evalJs(`document.querySelector('#intent-delete-form').action`)).endsWith(`/admin/chatbot/${created.id}`), 'Delete opens the small confirmation for that intent');
    await click('[form=intent-delete-form]'); await settle();
    note(sql(`select count(*) c from chatbot_intents where id=${created.id}`)[0].c == 0, 'intent deleted');
    // unanswered
    await b.go('/admin/chatbot?tab=unanswered');
    const un = await b.evalJs(`document.querySelectorAll('form[action*="unanswered"]').length`);
    note(un >= 1, `Unanswered Questions tab lists ${un} questions with Dismiss`);
    await clickText('button', 'Dismiss'); await settle();
    note(sql('select count(*) c from luma_unanswered_questions where dismissed_at is not null')[0].c >= 1, 'Dismiss works');

    // ── Contact Settings ────────────────────────────────────────────────────────────────────────
    await b.go('/admin/settings/contact');
    note((await body()).includes('Business Contact Info') && (await body()).includes('Shop Location') && (await body()).includes('Where This Shows'), 'two cards plus Where This Shows');
    await clickText('button', 'Edit'); await sleep(400);
    note((await openModalId()) === 'contact-biz', 'first Edit opens the Business Contact modal');
    await b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }); await sleep(250);
    note((await openModalId()) === null, 'Escape closes an untouched modal straight away');
    await b.evalJs(`document.querySelectorAll('.ct-grid .admin-btn')[0].click()`); await sleep(300);
    await setVal('#phone', 'not a phone'); await click('[form=contact-biz-form]'); await settle();
    note((await openModalId()) === 'contact-biz', 'invalid phone: the page reloads with the Business Contact modal open');
    note((await b.evalJs(`${$('#phone')}.value`)) === 'not a phone' && (await b.evalJs(`getComputedStyle(${$('#contact-biz .am-field.is-invalid .am-err')}).display`)) === 'block', 'typed value kept, red message under the field');
    await setVal('#phone', '0917 555 0188'); await setVal('#tiktok_url', 'https://www.tiktok.com/@lpwalk');
    await click('[form=contact-biz-form]'); await settle();
    const cs = sql('select phone,tiktok_url,shop_address from business_settings limit 1')[0];
    note(cs.phone === '0917 555 0188' && cs.tiktok_url === 'https://www.tiktok.com/@lpwalk', 'business card saved');
    note((await body()).includes('Contact settings saved.') && (await body()).includes('0917 555 0188') && (await body()).includes('https://www.tiktok.com/@lpwalk'), 'success toast and the read-only card shows the new values');
    // shop card must not wipe the business fields
    await b.evalJs(`document.querySelectorAll('.ct-grid .admin-btn')[1].click()`); await sleep(300);
    await setVal('#map_lat', '95'); await setVal('#map_lng', '121.4');
    await click('[form=contact-shop-form]'); await settle();
    note((await openModalId()) === 'contact-shop' && (await b.evalJs(`${$('#map_lat')}.value`)) === '95', 'invalid latitude: Shop Location modal reopened, value kept');
    await setVal('#shop_address', 'Walk Street, San Juan, Batangas'); await setVal('#map_lat', '13.8294'); await setVal('#map_lng', '121.4099'); await setVal('#shop_landmarks', 'Walk Hospital');
    await click('[form=contact-shop-form]'); await settle();
    const cs2 = sql('select phone,tiktok_url,shop_address,map_lat,map_lng from business_settings limit 1')[0];
    note(cs2.shop_address === 'Walk Street, San Juan, Batangas' && cs2.phone === '0917 555 0188' && cs2.tiktok_url === 'https://www.tiktok.com/@lpwalk', 'shop card saved without touching the business fields');
    // customer-facing places
    const cust = JSON.parse(execSync('php tests/UiLinks/session-cookie-for.php ot-customer@example.test web', { cwd: ROOT }).toString());
    await b.setCookie(cust);
    await b.go('/');
    const foot = await b.evalJs(`document.querySelector('footer') ? document.querySelector('footer').innerText : ''`);
    note(foot.includes('555 0188') || foot.replace(/\D/g, '').includes('09175550188'), 'website footer shows the new phone');
    note(/tiktok\.com\/@lpwalk/.test(await b.evalJs(`document.documentElement.innerHTML`)), 'website footer shows the TikTok button');
    await b.go('/checkout'); const co = await body();
    note(co.includes('555 0188') || co.replace(/\D/g, '').includes('09175550188') || co.includes('Need Help'), 'checkout Need Help panel loads (phone shown when the cart has items)');
    const reply = await b.evalJs(`fetch('/chatbot',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':(document.querySelector('meta[name=csrf-token]')||{}).content||''},body:JSON.stringify({message:'what is your contact number'})}).then(r=>r.text())`);
    note(/555.?0188/.test(reply), 'a chatbot reply that uses the phone shows the new value');
    await b.setCookie(cookie);

    // ── System Health ───────────────────────────────────────────────────────────────────────────
    await b.go('/admin/system-health');
    note(!!(await b.evalJs(`!!${$('.sh-grid')}`)) && (await b.evalJs(`document.querySelectorAll('.admin-stat-card').length`)) === 3, 'three stat cards above the two-column layout');
    note((await body()).includes('Maintenance') && (await body()).includes('About Orphan Files'), 'Maintenance and About Orphan Files cards');
    await clickText('button', 'Clear Cache'); await sleep(400);
    note((await openModalId()) === 'clear-cache', 'Clear Cache asks first in a modal');
    await clickText('.lp-modal:not([hidden]) button', 'Go Back'); await sleep(300);
    note((await openModalId()) === null, 'Go Back closes without clearing');
    await clickText('button', 'Clear Cache'); await sleep(400);
    await click('[form=clear-cache-form]'); await settle();
    note((await body()).includes('Cache, config and compiled views were cleared.'), 'confirming clears the cache and shows the success notification');
  }

  if (MODE === 'proof' || MODE === 'all') {
    const states = [
      ['chatbot', '/admin/chatbot', null],
      ['chatbot-add', '/admin/chatbot', 'intent-add'],
      ['chatbot-edit', '/admin/chatbot', 'EDIT'],
      ['chatbot-error', '/admin/chatbot', 'ERR-INTENT'],
      ['chatbot-delete', '/admin/chatbot', 'intent-delete'],
      ['chatbot-unanswered', '/admin/chatbot?tab=unanswered', null],
      ['contact', '/admin/settings/contact', null],
      ['contact-business', '/admin/settings/contact', 'contact-biz'],
      ['contact-shop', '/admin/settings/contact', 'contact-shop'],
      ['contact-error', '/admin/settings/contact', 'ERR-CONTACT'],
      ['health', '/admin/system-health', null],
      ['health-clear-cache', '/admin/system-health', 'clear-cache'],
    ];
    for (const w of [1440, 1001, 390]) {
      await b.viewport(w, w === 390 ? 844 : 900);
      for (const [name, url, modal] of states) {
        await b.go(url);
        await b.evalJs('window.scrollTo(0, 300)'); await sleep(150);
        let id = modal;
        if (modal === 'EDIT') { id = await b.evalJs(`(()=>{const m=document.querySelector('.lp-modal[id^=intent-edit-]'); return m ? m.id : null})()`); }
        if (modal === 'ERR-INTENT') { await b.evalJs(`window.scrollTo(0,0)`); await clickText('button', 'Add Intent'); await sleep(250); await setVal('#intent-add-form-keywords', 'x'); await setVal('#intent-add-form-response', 'y'); await setVal('#intent-add-form-category', 'C'.repeat(120)); await click('[form=intent-add-form]'); await settle(); id = await openModalId(); }
        if (modal === 'ERR-CONTACT') { await b.evalJs(`document.querySelectorAll('.ct-grid .admin-btn')[0].click()`); await sleep(250); await setVal('#phone', 'not a phone'); await click('[form=contact-biz-form]'); await settle(); id = await openModalId(); }
        if (id && !modal.startsWith('ERR')) { await b.evalJs(`document.querySelector('[data-lp-modal-open="${id}"]') ? document.querySelector('[data-lp-modal-open="${id}"]').click() : window.LpModal.open(document.getElementById(${JSON.stringify(id)}))`); await sleep(500); }
        const key = `${name}@${w}`;
        const r = { layout: await b.evalJs(LAYOUT) };
        if (id) { r.modal = await b.evalJs(MODAL_CHECK); }
        if (w === 1440 && !id) { r.styles = await b.evalJs(STYLES); }
        if (w === 1440 && id && ['chatbot-add', 'contact-business'].includes(name)) { r.styles = await b.evalJs(STYLES); }
        report.pages[key] = r;
        const shot = await b.send('Page.captureScreenshot', { format: 'png' });
        fs.writeFileSync(path.join(OUT, `${name}-${w}.png`), Buffer.from(shot.data, 'base64'));
        const m = r.modal;
        if (id) {
          note(!!m && m.open && m.boxInViewport && m.aboveSidebar && m.aboveTopbar && m.footerVisible && m.mountedOnBody && m.aboveToasts, `${key}: modal fully visible, buttons visible, above sidebar and top bar and toasts`);
          if (w === 390) { note(m.sheet, `${key}: bottom sheet (full width, anchored at the bottom)`); }
        }
        note(r.layout.overflowX <= 0, `${key}: no horizontal overflow`);
        note(r.layout.clipped.length === 0, `${key}: no clipped text${r.layout.clipped.length ? ' ' + JSON.stringify(r.layout.clipped.slice(0, 3)) : ''}`);
        note(r.layout.lowContrast.length === 0, `${key}: contrast ok${r.layout.lowContrast.length ? ' ' + JSON.stringify(r.layout.lowContrast.slice(0, 3)) : ''}`);
        if (id) { await b.evalJs('window.LpModal && window.LpModal.close()'); }
      }
    }
    fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  }

  const bad = results.filter((x) => !x).length;
  console.log(`\n${results.length - bad}/${results.length} checks passed`);
  b.close(); process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
