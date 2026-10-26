/*
 * Identity validation walk (SCRATCH DATABASE ONLY): admin list (three tabs), review (pending, approved, rejected, past attempts, viewer,
 * required checks, reject reason), and the customer page in every state, at 1366x768 and 390x844 (plus 820 overflow check).
 * Checks: no horizontal scroll, review fits one screen at 1366x768, button contrast (tests/ButtonContrast/measure.js), text contrast of
 * the new pages, keyboard focus ring. Screenshots go to docs/admin/screenshots/identity/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force ; php tests/UiLinks/seed_identity.php > storage/app/identity-seed.json
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-identity.cjs
 *   php tests/UiLinks/seed_identity.php --clean      (removes the stand-in photos afterwards)
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'admin', 'screenshots', 'identity');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const seed = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'identity-seed.json'), 'utf8').replace(/^﻿/, ''));

const TEXT_CONTRAST = `(() => {
  const parse = (s) => { const m = s.match(/rgba?\\(([^)]+)\\)/); if (!m) return { r: 255, g: 255, b: 255, a: 0 }; const p = m[1].split(',').map(Number); return { r: p[0], g: p[1], b: p[2], a: p[3] === undefined ? 1 : p[3] }; };
  const over = (f, b) => ({ r: f.r * f.a + b.r * (1 - f.a), g: f.g * f.a + b.g * (1 - f.a), b: f.b * f.a + b.b * (1 - f.a), a: 1 });
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const ratio = (a, b) => { const x = lum(a), y = lum(b); return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05); };
  const bgOf = (el) => { const chain = []; for (let e = el; e; e = e.parentElement) { const bg = parse(getComputedStyle(e).backgroundColor); const img = getComputedStyle(e).backgroundImage; chain.push(img !== 'none' && /gradient/.test(img) ? { r: 255, g: 255, b: 255, a: 0.7 } : bg); if (bg.a >= 0.99) break; } let c = { r: 247, g: 242, b: 234, a: 1 }; for (const l of chain.reverse()) c = over(l, c); return c; };
  const bad = [];
  for (const el of document.querySelectorAll('.idr *, .vfy *')) {
    if (!el.childNodes.length || ![...el.childNodes].some((n) => n.nodeType === 3 && n.textContent.trim())) continue;
    const cs = getComputedStyle(el); if (cs.display === 'none' || cs.visibility === 'hidden' || el.offsetParent === null) continue;
    if (el.closest('.idr-stage, .vfy-drop img, .lp-btn')) continue;   // photos on a dark stage and shared buttons (measured by measure.js)
    const fg = over(parse(cs.color), bgOf(el)); const r = ratio(fg, bgOf(el));
    if (r < 4.5) bad.push({ text: el.innerText.trim().slice(0, 40), cls: el.className.toString().slice(0, 40), ratio: +r.toFixed(2) });
  }
  return bad;
})()`;

(async () => {
  const c = await lib.start(9393);
  const fail = [];
  const note = (name, ok, detail) => { if (!ok) fail.push(name); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const cookie = (email, guard) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, guard], { cwd: lib.ROOT, encoding: 'utf8' }));
  const overflow = () => c.evalJs('document.documentElement.scrollWidth - document.documentElement.clientWidth');
  const audit = async (tag) => {
    note(`${tag}: no horizontal scroll`, (await overflow()) <= 0);
    const b = await c.evalJs(BUTTONS);
    const mine = b.failures.filter((f) => !/admin-(btn|burger|sidebar|nav)|burger|sidebar/.test(f.cls));
    note(`${tag}: button contrast`, mine.length === 0, mine.slice(0, 4));
    const t = await c.evalJs(TEXT_CONTRAST);
    note(`${tag}: text contrast >= 4.5`, t.length === 0, t.slice(0, 5));
  };

  try {
    /* ───────── admin ───────── */
    await c.setCookie(cookie(seed.admin, 'admin'));
    for (const [w, h, tag] of [[1366, 768, 'desktop'], [390, 844, '390'], [820, 1100, '820']]) {
      await c.viewport(w, h);
      for (const tab of ['pending', 'approved', 'rejected']) {
        await c.go(`/admin/validations?status=${tab}`);
        if (tag !== '820') await shot(`list-${tab}-${tag}`);
        const m = await c.evalJs(`({ rows: document.querySelectorAll('.idr-row:not(.idr-row--head)').length, tabs: [...document.querySelectorAll('.idr-tab')].map((t) => t.innerText.replace(/\\s+/g, ' ')), btn: (document.querySelector('.idr-open') || {}).innerText })`);
        note(`list ${tab} ${tag}: rows and counts`, m.rows > 0 && m.tabs.length === 3, m);
        await audit(`list ${tab} ${tag}`);
      }
      await c.go('/admin/validations?status=rejected&q=customer%20d');
      note(`search ${tag}`, (await c.evalJs(`document.querySelectorAll('.idr-row:not(.idr-row--head)').length`)) === 1);
    }

    /* review: pending, attempt 1, at 1366x768 */
    await c.viewport(1366, 768);
    await c.go(`/admin/validations/${seed.ids.pending_att1}`);
    await c.sleep(600);
    const fit = await c.evalJs(`({ sh: document.scrollingElement.scrollHeight, ih: innerHeight, main: (document.querySelector('.admin-main') || {}).scrollHeight, doc: [...document.querySelectorAll('.idr-stage')].map((s) => Math.round(s.getBoundingClientRect().height)) })`);
    note('review 1366x768 fits one screen (no page scroll)', fit.sh <= fit.ih + 1, fit);
    await shot('review-pending-1366x768');
    await audit('review pending 1366x768');

    // approve before ticking: a message, nothing opens
    await c.evalJs(`document.getElementById('idr-approve-btn').click()`);
    note('approve with no checks shows the message and opens no dialog', await c.evalJs(`!document.getElementById('idr-check-msg').hidden && document.getElementById('idr-approve-modal').hidden`));
    await shot('review-pending-checks-message-1366x768');
    // reject with no reason
    await c.evalJs(`document.getElementById('idr-reject-btn').click()`);
    note('reject with no reason shows the message', await c.evalJs(`!document.getElementById('idr-why-msg').hidden && document.getElementById('idr-reject-modal').hidden`));
    // reason chip + reject dialog
    await c.evalJs(`document.querySelector('[data-reason-chip]').click(); document.getElementById('idr-reject-btn').click()`);
    await c.sleep(300);
    note('reject dialog shows the reason', await c.evalJs(`!document.getElementById('idr-reject-modal').hidden && document.getElementById('idr-reject-quote').textContent === 'Photo is blurry'`));
    await shot('review-reject-dialog-1366x768');
    await c.evalJs(`LpModal.close()`);
    // tick all three, approve dialog
    await c.evalJs(`document.querySelectorAll('[data-check]').forEach((b) => b.click()); document.getElementById('idr-approve-btn').click()`);
    await c.sleep(300);
    note('three ticks open the approve dialog', await c.evalJs(`!document.getElementById('idr-approve-modal').hidden && document.getElementById('idr-cnt').textContent === '3 of 3'`));
    await shot('review-approve-dialog-1366x768');
    await c.evalJs(`LpModal.close()`);
    // viewer
    await c.evalJs(`document.querySelector('[data-enlarge="0"]').click()`);
    await c.sleep(500);
    note('Enlarge opens the glass viewer', await c.evalJs(`document.getElementById('idr-viewer').open`));
    await c.evalJs(`document.getElementById('idr-viewer-rotate').click()`);
    await c.sleep(400);
    await shot('review-viewer-rotated-1366x768');
    await c.evalJs(`document.getElementById('idr-viewer-close').click()`);
    // keyboard focus ring on the checks: real Tab presses from the photo tools
    await c.evalJs(`document.querySelector('[data-enlarge="1"]').focus()`);
    let onCheck = false;
    for (let i = 0; i < 8 && !onCheck; i++) {
      await c.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      await c.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      onCheck = await c.evalJs(`document.activeElement.matches('[data-check]')`);
    }
    note('Tab reaches the checks and a focus ring shows', onCheck && (await c.evalJs(`getComputedStyle(document.activeElement.closest('.idr-chk')).outlineStyle !== 'none'`)));
    await shot('review-focus-ring-1366x768');

    // phone and tablet
    for (const [w, h, tag] of [[390, 844, '390'], [820, 1100, '820']]) {
      await c.viewport(w, h);
      await c.go(`/admin/validations/${seed.ids.pending_att1}`);
      await shot(`review-pending-${tag}`);
      await audit(`review pending ${tag}`);
    }

    // attempt 2 with past attempts
    await c.viewport(1366, 768);
    await c.go(`/admin/validations/${seed.ids.pending_att2}`);
    note('attempt 2 shows the tag and Past attempts', await c.evalJs(`/Attempt 2/.test(document.body.innerText) && [...document.querySelectorAll('button')].some((b) => b.innerText.trim() === 'Past attempts')`));
    await c.evalJs(`[...document.querySelectorAll('button')].find((b) => b.innerText.trim() === 'Past attempts').click()`);
    await c.sleep(300);
    await shot('review-past-attempts-1366x768');
    note('past attempts lists the reason', await c.evalJs(`/ID is expired/.test(document.getElementById('idr-past').innerText)`));
    await c.evalJs(`LpModal.close()`);

    // decided records
    for (const [key, name] of [['approved', 'approved'], ['approved_old', 'approved-older-decision'], ['rejected_latest', 'rejected-followup-button'], ['rejected_followup', 'rejected-waiting']]) {
      for (const [w, h, tag] of [[1366, 768, '1366x768'], [390, 844, '390']]) {
        await c.viewport(w, h);
        await c.go(`/admin/validations/${seed.ids[key]}`);
        if (w === 1366) {
          const f = await c.evalJs(`({ sh: document.scrollingElement.scrollHeight, ih: innerHeight })`);
          note(`record ${name} fits one screen`, f.sh <= f.ih + 1, f);
        }
        await shot(`record-${name}-${tag}`);
        await audit(`record ${name} ${tag}`);
      }
    }
    await c.viewport(1366, 768);
    await c.go(`/admin/validations/${seed.ids.rejected_latest}`);
    await c.evalJs(`document.querySelector('[data-lp-modal-open="idr-follow-modal"]').click()`);
    await c.sleep(300);
    await shot('record-rejected-followup-dialog-1366x768');
    await c.evalJs(`LpModal.close()`);

    /* ───────── customer ───────── */
    const states = [['not-submitted', seed.fresh], ['pending', seed.pending], ['rejected', seed.rejected], ['rejected-asked-again', seed.rejected_followup], ['approved', seed.approved]];
    for (const [name, email] of states) {
      await c.setCookie(cookie(email, 'web'));
      for (const [w, h, tag] of [[1366, 768, '1366x768'], [390, 844, '390'], [820, 1100, '820']]) {
        await c.viewport(w, h);
        await c.go('/validate-account');
        if (tag !== '820') await shot(`customer-${name}-${tag}`);
        await audit(`customer ${name} ${tag}`);
      }
    }
    // client-side pre-check messages on the form
    await c.setCookie(cookie(seed.fresh, 'web'));
    await c.viewport(1366, 768);
    await c.go('/validate-account');
    const pre = await c.evalJs(`(async () => {
      const set = (input, file) => { const dt = new DataTransfer(); dt.items.add(file); input.files = dt.files; input.dispatchEvent(new Event('change', { bubbles: true })); };
      const ins = document.querySelectorAll('#vfy-form input[type=file]');
      set(ins[0], new File(['GIF89a'], 'a.gif', { type: 'image/gif' }));
      await new Promise((r) => setTimeout(r, 200));
      const gif = document.getElementById('vfy-msg-0').textContent;
      set(ins[1], new File([new Uint8Array(5 * 1024 * 1024 + 1)], 'big.jpg', { type: 'image/jpeg' }));
      await new Promise((r) => setTimeout(r, 200));
      const big = document.getElementById('vfy-msg-1').textContent;
      set(ins[0], new File([new Uint8Array([0xFF, 0xD8, 0xFF, 0xE0, 0, 0, 0, 0])], 'real.jpg', { type: 'image/jpeg' }));
      await new Promise((r) => setTimeout(r, 200));
      return { gif, big, submitDisabled: document.getElementById('vfy-submit').disabled };
    })()`);
    note('client pre-check messages', /JPEG or PNG/.test(pre.gif) && /over 5 MB/.test(pre.big) && pre.submitDisabled, pre);
    await shot('customer-precheck-errors-1366x768');
  } finally {
    c.close();
  }
  console.log(fail.length ? `\n${fail.length} CHECK(S) FAILED:\n - ` + fail.join('\n - ') : '\nall checks ok');
  process.exit(fail.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
