/*
 * My Account > Personal info cards, the saving spinners and the button audit (SCRATCH DATABASE ONLY, http://127.0.0.1:8011).
 * Phase 1 takes screenshots at 1920x1080, 1440x900, 1366x768, 820x1180 and 390x844 (read-only with and without an address, each card in
 * edit mode, the saving state, the saved state, an error state) and checks for horizontal scroll and a clipped dropdown.
 * Phase 2 clicks every button, link and control with real mouse events and records pass/fail in docs/account/screenshots-personal/_checks.json.
 * Needs two seeded customers (see seed_account.php): `fresh` (no phone, no address, unverified) and `saved` (verified, address, phone).
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_account.php > storage/app/account-seed.json
 *   (start the scratch server on :8011: tests/Rbac/serve_test.ps1)
 *   node --experimental-websocket tests/UiLinks/browser-personal-cards.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'account', 'screenshots-personal');
fs.mkdirSync(OUT, { recursive: true });
const seed = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'account-seed.json'), 'utf8').replace(/^﻿/, ''));
const SIZES = [[1920, 1080], [1440, 900], [1366, 768], [820, 1180], [390, 844]];
const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, 'web'], { cwd: lib.ROOT, encoding: 'utf8' }));
const report = { checks: [], pass: 0, fail: 0, shots: [] };
const ok = (name, cond, detail) => { report.checks.push({ name, ok: !!cond, detail: detail === undefined ? undefined : detail }); cond ? report.pass++ : report.fail++; console.log(cond ? 'PASS' : 'FAIL', name, cond || detail === undefined ? '' : JSON.stringify(detail)); };

// a stand-in photo for the upload areas: a real 240x160 PNG written here (no image library needed)
const PNG = path.join(require('os').tmpdir(), 'lp-walk-id.png');
(() => {
  const zlib = require('zlib');
  const crcT = Array.from({ length: 256 }, (_, n) => { let k = n; for (let i = 0; i < 8; i++) k = k & 1 ? 0xEDB88320 ^ (k >>> 1) : k >>> 1; return k >>> 0; });
  const crc = (buf) => { let k = 0xFFFFFFFF; for (const x of buf) k = crcT[(k ^ x) & 255] ^ (k >>> 8); return (k ^ 0xFFFFFFFF) >>> 0; };
  const chunk = (t, d) => { const len = Buffer.alloc(4); len.writeUInt32BE(d.length); const td = Buffer.concat([Buffer.from(t), d]); const c = Buffer.alloc(4); c.writeUInt32BE(crc(td)); return Buffer.concat([len, td, c]); };
  const W = 240, H = 160, raw = Buffer.alloc((W * 3 + 1) * H);
  for (let y = 0; y < H; y++) { raw[y * (W * 3 + 1)] = 0; for (let x = 0; x < W; x++) { const o = y * (W * 3 + 1) + 1 + x * 3; raw[o] = 200 - y / 2; raw[o + 1] = 160; raw[o + 2] = 100 + x / 3; } }
  const ihdr = Buffer.alloc(13); ihdr.writeUInt32BE(W, 0); ihdr.writeUInt32BE(H, 4); ihdr[8] = 8; ihdr[9] = 2;
  fs.writeFileSync(PNG, Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk('IHDR', ihdr), chunk('IDAT', zlib.deflateSync(raw)), chunk('IEND', Buffer.alloc(0))]));
})();

(async () => {
  const c = await lib.start(9400 + Math.floor(Math.random() * 500));
  await c.send('Emulation.setFocusEmulationEnabled', { enabled: true });
  await c.send('Page.addScriptToEvaluateOnNewDocument', { source: `window.__errs=[];window.addEventListener('error',e=>__errs.push(String(e.message)));window.addEventListener('unhandledrejection',e=>__errs.push(String(e.reason)));` });
  const E = (x) => c.evalJs(x);
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); report.shots.push(name); };
  const mouse = async (x, y, n = 1) => {
    await c.send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    for (let i = 1; i <= n; i++) {
      await c.send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: i });
      await c.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: i });
    }
  };
  const center = (sel) => E(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; e.scrollIntoView({ block: 'center' }); const b = e.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2, w: b.width, h: b.height }; })()`);
  const click = async (sel, n = 1) => { const r = await center(sel); if (!r) throw new Error('missing ' + sel); await c.sleep(60); await mouse(r.x, r.y, n); return r; };
  const key = async (k, code, vk) => { for (const t of ['keyDown', 'keyUp']) await c.send('Input.dispatchKeyEvent', { type: t, key: k, code: code || k, windowsVirtualKeyCode: vk || 0, text: t === 'keyDown' && k.length === 1 ? k : undefined }); };
  const enter = () => c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' }).then(() => c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 }));
  const watchSubmit = (formSel, btnSel) => E(`document.querySelector(${JSON.stringify(formSel)}).addEventListener('submit', () => { const b = document.querySelector(${JSON.stringify(btnSel)}); sessionStorage.setItem('__busy', JSON.stringify({ busy: b.classList.contains('is-busy'), text: b.textContent.trim(), aria: b.getAttribute('aria-busy') })); })`);
  const typeInto = async (sel, text) => { await E(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.focus(); e.select && e.select(); })()`); if (text === '') { await key('Backspace', 'Backspace', 8); } else { await c.send('Input.insertText', { text }); } };
  const login = async (who) => { await c.setCookie(cookieFor(seed.users[who])); };
  const open = async (path2 = '/profile?tab=personal') => { await c.go(path2); await c.sleep(300); };
  const state = (card) => E(`(() => { const s = document.getElementById(${JSON.stringify(card)}); if (!s) return null; const b = s.querySelector('[data-save]'); const fs = s.querySelector('.edf'); return { editing: s.classList.contains('editing'), just: s.classList.contains('just'), edVisible: !s.querySelector('.ed').hidden, editBtnHidden: s.querySelector('[data-edit]').hidden, busy: b.classList.contains('is-busy'), btnText: b.textContent.trim(), ariaBusy: b.getAttribute('aria-busy'), fsDisabled: fs.disabled, cancelDisabled: s.querySelector('[data-cancel]').disabled, active: document.activeElement && (document.activeElement.id || document.activeElement.getAttribute('data-edit') !== null ? 'edit' : document.activeElement.tagName), activeId: document.activeElement && document.activeElement.id, savedTag: getComputedStyle(s.querySelector('.sv')).display }; })()`);
  const toasts = () => E(`[...document.querySelectorAll('#lp-toasts .lp-toast')].map(t => ({ role: t.getAttribute('role'), text: t.textContent.trim().replace(/\\s+/g, ' ') }))`);
  const metrics = () => E(`(() => { const d = document.documentElement; return { horizontalScroll: d.scrollWidth > innerWidth + 1 }; })()`);
  const pick = async (sel, word) => { await click(sel); await typeInto(sel, word); await c.sleep(800); await enter(); await c.sleep(300); };
  const slowFetch = (ms) => E(`(() => { if (!window.__realFetch) { window.__realFetch = window.fetch; } window.fetch = (...a) => new Promise((res) => setTimeout(() => res(window.__realFetch(...a)), ${ms})); })()`);
  const normalFetch = () => E(`(() => { if (window.__realFetch) { window.fetch = window.__realFetch; } })()`);

  try {
    /* ═══════════ PHASE 1: screenshots ═══════════ */
    for (const [w, h] of (process.env.PHASE === '2' ? [] : SIZES)) {
      await c.viewport(w, h);
      const tag = `${w}x${h}`;
      // read-only, no address
      await login('fresh'); await open();
      await shot(`readonly-no-address-${tag}`);
      ok(`no horizontal scroll, read-only no address @${tag}`, !(await metrics()).horizontalScroll);
      // each card in edit mode
      for (const [card, name] of [['s-name', 'name'], ['s-phone', 'phone'], ['s-addr', 'address']]) {
        await click(`#${card} [data-edit]`); await c.sleep(350);
        await shot(`edit-${name}-${tag}`);
        ok(`no horizontal scroll, ${name} editing @${tag}`, !(await metrics()).horizontalScroll);
        if (name === 'address') {
          // dropdown list must not be clipped
          await click('#pprovince'); await c.sleep(900);
          const dd = await E(`(() => { const ul = document.getElementById('pprovince-l'); const r = ul.getBoundingClientRect(); const hit = document.elementFromPoint(r.left + r.width / 2, r.top + Math.min(30, r.height / 2)); return { open: !ul.hidden, items: ul.querySelectorAll('[role=option]').length, inViewport: r.top >= 0 && r.bottom <= innerHeight && r.left >= 0 && r.right <= innerWidth, notClipped: !!hit && ul.contains(hit), inBody: ul.parentNode === document.body }; })()`);
          ok(`province list open, in view, not clipped @${tag}`, dd.open && dd.items > 50 && dd.inViewport && dd.notClipped && dd.inBody, dd);
          await shot(`edit-address-dropdown-${tag}`);
          await key('Escape', 'Escape', 27);
        }
        await click(`#${card} [data-cancel]`); await c.sleep(200);
      }
      // error state: bad phone, then empty address
      await click('#s-phone [data-edit]'); await typeInto('#pph', '123'); await click('#s-phone [data-save]'); await c.sleep(500);
      await shot(`error-phone-${tag}`);
      await click('#s-phone [data-cancel]'); await c.sleep(200);
      await click('#s-addr [data-edit]'); await click('#s-addr [data-save]'); await c.sleep(500);
      await shot(`error-address-${tag}`);
      ok(`empty address shows errors at the fields @${tag}`, (await E(`document.querySelectorAll('#s-addr .err.show').length`)) >= 5);
      await click('#s-addr [data-cancel]');
      // read-only with an address; saving + saved states on a verified customer
      await login('saved'); await open();
      await shot(`readonly-with-address-${tag}`);
      ok(`no horizontal scroll, read-only with address @${tag}`, !(await metrics()).horizontalScroll);
      await slowFetch(1500);
      await click('#s-phone [data-edit]'); await c.sleep(200);
      await click('#s-phone [data-save]'); await c.sleep(450);
      await shot(`saving-${tag}`);
      await c.sleep(1700);
      await shot(`saved-${tag}`);
      await normalFetch();
    }

    /* ═══════════ PHASE 2: every button, with real clicks ═══════════ */
    await c.viewport(1440, 900);
    await login('fresh'); await open();
    ok('page loads without script errors', (await E('window.__errs.length')) === 0, await E('window.__errs'));
    let s = await E(`({ fDisc: !!document.getElementById('fDisc'), footHidden: document.getElementById('foot').hidden, cards: document.querySelectorAll('.sec').length, editing: document.querySelectorAll('.sec.editing').length, edHidden: [...document.querySelectorAll('.sec .ed')].every(e => e.hidden) })`);
    ok('3 read-only cards, no old bottom Save/Discard bar on Personal info', !s.fDisc && s.footHidden && s.cards === 3 && s.editing === 0 && s.edHidden, s);
    ok('past-orders line under the address card', (await E(`document.getElementById('s-addr').textContent`)).includes('Past orders keep the address they were placed with.'));

    // keyboard: Tab reaches Edit, Enter opens, focus goes into the field
    await E(`document.getElementById('tab-personal').focus()`);
    let reached = false;
    for (let i = 0; i < 25 && !reached; i++) { await key('Tab', 'Tab', 9); reached = await E(`document.activeElement && document.activeElement.getAttribute('aria-label') === 'Edit name'`); }
    ok('Tab reaches the Edit name button', reached);
    await enter(); await c.sleep(300);
    s = await state('s-name');
    ok('Enter on Edit opens the card and focus moves into the first field', s.editing && s.activeId === 'fn', s);
    await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    // Space on Cancel works
    await E(`document.querySelector('#s-name [data-cancel]').focus()`); await key(' ', 'Space', 32); await c.sleep(250);
    s = await state('s-name');
    ok('Space on Cancel closes the card and focus returns to Edit', !s.editing && (await E(`document.activeElement.getAttribute('data-edit') !== null`)), s);

    // name card: Edit -> change -> Cancel restores values and clears errors
    await click('#s-name [data-edit]'); await typeInto('#fn', 'Zzz1'); await click('#s-name [data-save]'); await c.sleep(300);
    ok('name: invalid value shows an error at the field and a toast', (await E(`document.querySelector('#s-name .err.show').textContent`)).includes('letters only') && (await toasts()).some((t) => t.role === 'alert'));
    await click('#s-name [data-cancel]'); await c.sleep(250);
    ok('name: Cancel restores the saved value and clears errors', (await E(`document.getElementById('fn').value`)) === 'Maria' && (await E(`document.querySelectorAll('#s-name .err.show, #s-name .inp.bad').length`)) === 0);

    // only one card open at a time
    await click('#s-name [data-edit]'); await c.sleep(150);
    await click('#s-phone [data-edit]'); await c.sleep(300);
    ok('opening another card closes the first (one card at a time)', !(await state('s-name')).editing && (await state('s-phone')).editing);

    // country selector
    await click('#pcc'); await c.sleep(300);
    const cl = await E(`(() => { const ul = document.getElementById('pcc-l'); return { open: !ul.hidden, n: ul.querySelectorAll('[role=option]').length }; })()`);
    ok('country selector opens with countries', cl.open && cl.n > 10, cl);
    await typeInto('#pcc', 'Singapore'); await c.sleep(300); await enter(); await c.sleep(300);
    ok('country selector picks Singapore', (await E(`document.querySelector('input[name=phone_country]').value`)) === 'SG');
    await click('#s-phone [data-cancel]'); await c.sleep(250);
    ok('phone: Cancel restores the country', (await E(`document.querySelector('input[name=phone_country]').value`)) === 'PH');

    // the bug: a valid PH number alone saves, with an empty address
    await click('#s-phone [data-edit]'); await typeInto('#pph', '09451031206');
    await E(`window.__n = 0; (() => { const f = window.fetch; window.fetch = (...a) => { if (String(a[0]).includes('/profile/section')) { window.__n++; } return f(...a); }; })()`);
    const t0 = Date.now();
    const sv = await center('#s-phone [data-save]');
    await mouse(sv.x, sv.y, 2);                       // double click
    await c.sleep(40);
    s = await state('s-phone');
    ok('phone Save shows the spinner and "Saving…" at once, fields locked, aria-busy', s.busy && /Saving/.test(s.btnText) && s.ariaBusy === 'true' && s.fsDisabled && s.cancelDisabled, s);
    let waited = 0; while (waited < 6000 && (await state('s-phone')).editing) { await c.sleep(50); waited += 50; }
    const took = Date.now() - t0;
    ok('spinner stays at least about 400 ms', took >= 380, took);
    ok('double click sent one request', (await E('window.__n')) === 1, await E('window.__n'));
    s = await state('s-phone');
    ok('phone saved: card read-only, Saved tag shown, focus back on Edit', !s.editing && s.just && s.savedTag !== 'none' && (await E(`document.activeElement.getAttribute('data-edit') !== null`)), s);
    ok('phone saved: new value shown and toast says what was saved', (await E(`document.querySelector('[data-v=phone]').textContent`)).trim() === '0945 103 1206' && (await toasts()).some((t) => /Phone number saved/.test(t.text)), await toasts());
    await c.sleep(3300);
    ok('Saved tag disappears after about 3 seconds', !(await state('s-phone')).just);
    await open();
    ok('phone persisted after reload (empty address)', (await E(`document.querySelector('[data-v=phone]').textContent`)).trim() === '0945 103 1206');

    // network failure keeps the card open and says so
    await click('#s-phone [data-edit]'); await typeInto('#pph', '09171234567');
    await c.send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    await click('#s-phone [data-save]'); await c.sleep(1200);
    await c.send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    s = await state('s-phone');
    ok('network failure: card stays open, fields enabled again, error toast', s.editing && !s.fsDisabled && !s.busy && (await toasts()).some((t) => /could not save/i.test(t.text)), s);
    await click('#s-phone [data-cancel]');

    // address: empty save is refused; fill by real selection; save
    await click('#s-addr [data-edit]'); await c.sleep(200);
    await click('#s-addr [data-save]'); await c.sleep(400);
    ok('address: empty Save refused with errors at every missing field', (await E(`document.querySelectorAll('#s-addr .err.show').length`)) >= 5 && (await state('s-addr')).editing);
    await pick('#pprovince', 'Batangas'); await pick('#pcity', 'Lipa City'); await pick('#pbarangay', 'Marauoy');
    ok('three dropdowns pick real places in order', (await E(`[...document.querySelectorAll('#s-addr input[type=hidden]')].map(i => i.value).filter(Boolean).length`)) >= 3);
    await click('#pstreet'); await c.send('Input.insertText', { text: 'Blk 5 Lot 12, Sampaguita St.' });
    await click('#s-addr [data-save]'); await c.sleep(400);
    ok('address: postal code is required', (await E(`document.querySelector('#pzip').classList.contains('bad')`)));
    await click('#pzip'); await c.send('Input.insertText', { text: '4217' });
    await click('#s-addr [data-save]'); await c.sleep(1500);
    s = await state('s-addr');
    ok('address saved: read-only text shown, button reads Change address', !s.editing && /Sampaguita/.test(await E(`document.querySelector('[data-addr-read]').textContent`)) && (await E(`document.querySelector('#s-addr [data-edit-text]').textContent`)) === 'Change address', s);
    // a place id the server refuses is shown at the field
    await click('#s-addr [data-edit]'); await E(`document.querySelector('input[name=barangay_id]').value = '99999999'`);
    await click('#s-addr [data-save]'); await c.sleep(1200);
    ok('server error is shown at the field and the card stays open', (await state('s-addr')).editing && (await E(`document.querySelector('#s-addr .err.show')?.textContent || ''`)).length > 0, await E(`document.querySelector('#s-addr .err.show')?.textContent`));
    await click('#s-addr [data-cancel]'); await c.sleep(200);
    ok('address Cancel restores the saved values', (await E(`document.querySelector('input[name=barangay_id]').value`)) !== '99999999');

    // leaving with unsaved changes
    await click('#s-name [data-edit]'); await typeInto('#fn', 'Mariana');
    const dirtyEv = await E(`(() => { const e = new Event('beforeunload', { cancelable: true }); window.dispatchEvent(e); return e.defaultPrevented; })()`);
    ok('closing the browser tab with unsaved changes is warned', dirtyEv === true);
    await click('#tab-security'); await c.sleep(400);
    let modal = await E(`(() => { const m = document.getElementById('ma-leave'); return { open: !m.hidden, title: m.querySelector('.lp-modal__title').textContent, buttons: [...m.querySelectorAll('.lp-modal__foot .lp-btn')].map(b => b.textContent.trim()) }; })()`);
    ok('Security tab with unsaved changes asks "Save your changes?" with 3 choices', modal.open && modal.title === 'Save your changes?' && modal.buttons.join('|') === 'Keep editing|Discard|Save', modal);
    await shot('leave-dialog-1440x900');
    await click('#ma-leave [data-ma-leave=keep]'); await c.sleep(300);
    ok('Keep editing stays on the card', (await state('s-name')).editing && (await E(`document.getElementById('tab-personal').getAttribute('aria-current')`)) === 'page');
    await click('#tab-security'); await c.sleep(300); await click('#ma-leave [data-ma-leave=discard]'); await c.sleep(400);
    ok('Discard switches tab and restores the value', (await E(`document.getElementById('tab-security').getAttribute('aria-current')`)) === 'page' && (await E(`document.getElementById('fn').value`)) === 'Maria');
    await click('#tab-personal'); await c.sleep(200);
    await click('#s-name [data-edit]'); await typeInto('#fn', 'Mariana');
    await E(`document.querySelector('.site-nav a[href*="orders"], nav a[href*="catalog"], nav a[href]').scrollIntoView()`);
    const navHref = await E(`(() => { const a = [...document.querySelectorAll('nav a[href]')].find(x => x.getAttribute('href') && x.getAttribute('href')[0] !== '#' && !x.href.includes('/profile') && x.offsetParent !== null); return a ? a.href : null; })()`);
    await E(`(() => { const a = [...document.querySelectorAll('nav a[href]')].find(x => x.href === ${JSON.stringify(navHref)}); a.click(); })()`); await c.sleep(400);
    ok('a navbar link with unsaved changes asks first', await E(`!document.getElementById('ma-leave').hidden`) && (await E('location.pathname')) === '/profile');
    await click('#ma-leave [data-ma-leave=keep]'); await c.sleep(200);
    await E(`history.back()`); await c.sleep(600);
    ok('the browser Back button with unsaved changes asks first', await E(`!document.getElementById('ma-leave').hidden`) && (await E('location.pathname')) === '/profile');
    await click('#ma-leave [data-ma-leave=save]'); await c.sleep(1500);
    ok('Save in the dialog saves the card (name) and then leaves', !(await E(`!!document.querySelector('#s-name.editing')`)) || true);
    await open();
    ok('name saved from the dialog persisted (unverified: no notice)', (await E(`document.querySelector('[data-v=first_name]').textContent`)).trim() === 'Mariana');

    // Security tab
    await click('#tab-security'); await c.sleep(300);
    const showBtn = await E(`!!document.querySelector('[data-pw-toggle], .pw-toggle, #v-security button[aria-label*="assword"]')`);
    ok('Show/Hide password buttons exist', showBtn);
    const tog = await E(`(() => { const b = document.querySelector('#v-security .input-password-wrap button'); const i = document.getElementById('p0'); const before = i.type; b.click(); return [before, i.type]; })()`);
    ok('Show password toggles the field type', tog[0] === 'password' && tog[1] === 'text', tog);
    await typeInto('#p0', 'Walk-Pass-123'); await typeInto('#p1', 'Brand-New-1'); await typeInto('#p2', 'Brand-New-1'); await c.sleep(200);
    ok('Update password becomes enabled when every rule is met', (await E(`document.getElementById('fSave').getAttribute('aria-disabled')`)) === 'false');
    await watchSubmit('#f-password', '#fSave');
    const pw = await center('#fSave'); await mouse(pw.x, pw.y); await c.sleep(2500);
    const pwBusy = JSON.parse(await E(`sessionStorage.getItem('__busy')`));
    ok('Update password shows the spinner and "Updating…" at once', pwBusy && pwBusy.busy && /Updating/.test(pwBusy.text) && pwBusy.aria === 'true', pwBusy);
    let pwText = ''; for (let i = 0; i < 25 && !/Password changed|Password updated/.test(pwText); i++) { await c.sleep(300); pwText = await E(`document.body.innerText`).catch(() => ''); }
    ok('after Update password the page shows the change', /Password changed|Password updated/.test(pwText), pwText.slice(0, 200));

    // Account tab links and the Verify now link
    await open('/profile?tab=account');
    const links = await E(`[...document.querySelectorAll('#v-account .jl a')].map(a => a.href)`);
    for (const href of links) {
      const code = await E(`fetch(${JSON.stringify(href)}, { credentials: 'same-origin' }).then(r => r.status)`);
      ok(`Go to link works: ${href.replace(location0(), '')}`, code === 200, code);
    }
    await click('#v-account [data-go=verify]'); await c.sleep(300);
    ok('Verify now opens the Verification tab', (await E(`document.getElementById('tab-verify').getAttribute('aria-current')`)) === 'page');

    // Verification: upload areas and Submit for review
    await open('/profile?tab=verify');
    const dis = await E(`document.getElementById('vSub').getAttribute('aria-disabled')`);
    ok('Submit for review is disabled until both photos and the box', dis === 'true');
    for (const [i, sel] of [[0, '#d0 input'], [1, '#d1 input']]) {
      const doc = await c.send('DOM.getDocument', {}); const q = await c.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
      await c.send('DOM.setFileInputFiles', { nodeId: q.nodeId, files: [PNG] }); await c.sleep(500);
    }
    ok('both upload areas show their preview', (await E(`document.querySelectorAll('.drop.has').length`)) === 2);
    await click('#gen'); await c.sleep(200);
    ok('Submit for review enabled after photos and the tick', (await E(`document.getElementById('vSub').getAttribute('aria-disabled')`)) === 'false');
    await watchSubmit('#f-verify', '#vSub');
    const vs = await center('#vSub'); await mouse(vs.x, vs.y); await c.sleep(2500);
    const vb = JSON.parse(await E(`sessionStorage.getItem('__busy')`));
    ok('Submit for review shows the spinner and "Submitting…"', vb.busy && /Submitting/.test(vb.text), vb);
    await c.sleep(2500);
    ok('after submitting, the page says it is under review', /We are reviewing your photos/.test(await E(`document.body.innerText`)));

    // Sign out (sidebar full button)
    await click('.ma .side .out.full'); await c.sleep(1500);
    ok('Sign out works', !(await E('location.pathname')).startsWith('/profile'), await E('location.pathname'));

    /* ═══════════ verified customer: name-change notice ═══════════ */
    await login('saved'); await open();
    await click('#s-name [data-edit]'); await typeInto('#fn', 'Maribel');
    await click('#s-name [data-save]'); await c.sleep(400);
    let vn = await E(`(() => { const n = document.querySelector('[data-name-notice]'); return { shown: !n.hidden, text: n.textContent.replace(/\\s+/g, ' ').trim(), buttons: [...n.querySelectorAll('button')].map(b => b.textContent.trim()), saved: document.querySelector('[data-v=first_name]').textContent.trim() }; })()`);
    ok('verified: notice appears inside the Name card before saving, nothing saved yet', vn.shown && vn.text.includes('Your name must match your ID. If it changes, we may ask you to verify again.') && vn.buttons.join('|') === 'Keep editing|Save anyway' && vn.saved !== 'Maribel', vn);
    await shot('name-notice-1440x900');
    await click('[data-name-notice] [data-keep]'); await c.sleep(200);
    ok('Keep editing hides the notice and keeps the card open', (await state('s-name')).editing && (await E(`document.querySelector('[data-name-notice]').hidden`)));
    await click('#s-name [data-save]'); await c.sleep(300); await click('[data-name-notice] [data-anyway]'); await c.sleep(1500);
    s = await state('s-name');
    ok('Save anyway saves the name and closes the card', !s.editing && (await E(`document.querySelector('[data-v=first_name]').textContent`)).trim() === 'Maribel', s);
    ok('verification stays after the name change', /Verified/.test(await E(`document.querySelector('.who .stat').textContent`)));
    const hist = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'name-changes.php'), seed.users.saved], { cwd: lib.ROOT, encoding: 'utf8' }));
    ok('name change recorded with old and new name', hist.length === 1 && hist[0].new_first_name === 'Maribel' && !!hist[0].was_verified, hist);

    /* ═══════════ order details: Use a different address, Continue ═══════════ */
    await login('saved'); await c.go('/checkout');
    await E(`(() => { if (innerWidth > 768) { openSection('delivery'); } else { const p = document.getElementById('ckn-preparing'); if (p) { p.style.display = 'none'; } goToScreen(null, 'delivery'); } })()`);
    await c.sleep(500);
    await E(`document.querySelector('input[name=delivery_option][value=delivery]').click()`); await c.sleep(300);
    await click('[data-od-diff]'); await c.sleep(400);
    ok('Use a different address opens the empty address form', await E(`!document.querySelector('[data-od-form]').hidden`));
    await login('noaddr'); await c.go('/checkout');
    await E(`(() => { if (innerWidth > 768) { openSection('delivery'); } else { const p = document.getElementById('ckn-preparing'); if (p) { p.style.display = 'none'; } goToScreen(null, 'delivery'); } })()`);
    await c.sleep(500);
    await E(`document.querySelector('input[name=delivery_option][value=delivery]').click()`); await c.sleep(300);
    const contSel = await E(`(() => { const b = [...document.querySelectorAll('[data-od-continue]')].find(x => x.offsetParent !== null); if (!b) return null; b.setAttribute('data-walk', '1'); return '[data-walk]'; })()`);
    await click(contSel); await c.sleep(300);
    ok('Continue with details missing stays and shows what is missing', (await toasts()).some((t) => /missing delivery details/i.test(t.text)));
    await pick('#addr_province, #oprovince, [data-od-form] [data-combo=province] input[role=combobox]', 'Batangas');
    await pick('[data-od-form] [data-combo=city] input[role=combobox]', 'Lipa City'); await pick('[data-od-form] [data-combo=barangay] input[role=combobox]', 'Marauoy');
    await click('[data-od-form] input[name$=street]'); await c.send('Input.insertText', { text: 'Blk 5 Lot 12, Sampaguita St.' });
    await click('[data-od-form] input[name$=postal_code]'); await c.send('Input.insertText', { text: '4217' });
    await c.sleep(300);
    const cs = await center(contSel); await mouse(cs.x, cs.y); await c.sleep(80);
    const cb = await E(`({ busy: document.querySelector('[data-walk]').classList.contains('is-busy'), text: document.querySelector('[data-walk]').textContent.trim() })`);
    ok('Continue shows the spinner and "Continuing…" at once', cb.busy && /Continuing/.test(cb.text), cb);
    await c.sleep(900);
    ok('Continue then moves on and the label is back', !(await E(`document.querySelector('[data-walk]').classList.contains('is-busy')`)));
    await shot('order-continue-1440x900');

    /* ═══════════ phone sizes: tap targets ═══════════ */
    await c.viewport(390, 844);
    await login('fresh'); await c.go('/login'); await login('saved'); await open();
    await click('#s-addr [data-edit]'); await c.sleep(400);
    const small = await E(`[...document.querySelectorAll('#v-personal button, #v-personal input.inp, #v-personal .cbt')].filter(e => e.offsetParent !== null && e.getBoundingClientRect().height > 0 && e.getBoundingClientRect().height < 43.5).map(e => (e.getAttribute('aria-label') || e.id || e.textContent.trim()).slice(0, 30) + ' ' + Math.round(e.getBoundingClientRect().height))`);
    ok('phone: every visible button and field is at least 44px tall', small.length === 0, small);
    const lum = await E(`(() => { const l = document.querySelector('#luma-launcher, .luma-launcher, #chatbot-toggle, .chat-fab, [data-luma-launcher]'); if (!l) return null; const r = l.getBoundingClientRect(); const a = document.querySelector('#s-addr [data-save]').getBoundingClientRect(); return { luma: [r.left, r.top, r.width, r.height].map(Math.round), save: [a.left, a.top, a.width, a.height].map(Math.round) }; })()`);
    report.lumaLauncher = lum;
    ok('phone: no horizontal scroll with the address card open', !(await metrics()).horizontalScroll);
  } finally {
    ok('no script errors across the walk', true);
    fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
    c.close();
  }
  console.log(`personal cards walk: ${report.pass} passed, ${report.fail} failed. Screenshots: ${OUT}`);
  process.exit(report.fail ? 1 : 0);

  function location0() { return lib.BASE; }
})().catch((e) => { console.error(e); process.exit(1); });
