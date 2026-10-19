/*
 * Stage 21i browser walk for Luma: drives the real chat widget in headless Chrome (SCRATCH DATABASE ONLY), saves a JPEG of each
 * state at 390px and 1280px into docs/stage21i-screenshots/ and checks each one for horizontal overflow, tap targets (44px, chips
 * excepted) and button contrast (tests/ButtonContrast/measure.js). Writes docs/stage21i-screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   DB_DATABASE=furniture_db_test php artisan chatbot:sync-defaults
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011, refuses non-_test databases)
 *   node --experimental-websocket tests/Luma/browser-checks.cjs
 *
 * The customer part uses a session minted by tests/Luma/session-cookie.php (the real sign-in form has reCAPTCHA, which is not bypassed).
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage21i-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
const ids = login.ids;
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

async function main() {
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9334', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try { target = (await getJson('http://127.0.0.1:9334/json')).find((t) => t.type === 'page'); } catch (e) { /* not up yet */ }
  }
  if (!target) throw new Error('Chrome did not start');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0;
  const pending = new Map();
  const events = [];
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { const { res, rej } = pending.get(d.id); pending.delete(d.id); d.error ? rej(new Error(JSON.stringify(d.error))) : res(d.result); } else if (d.method) events.push(d.method);
  };
  const send = (method, params = {}) => new Promise((res, rej) => { const id = ++n; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('JS error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  };
  await send('Page.enable');
  await send('Runtime.enable');
  await send('Network.enable');

  const go = async (url) => {
    events.length = 0;
    await send('Page.navigate', { url: BASE + url });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(500);
  };
  // each chat message: open the widget, type, send, wait for the reply
  const say = async (msg) => {
    await evalJs(`(async () => { if (!document.getElementById('chatbot-window').classList.contains('open')) { openChatbot(); } const i = document.getElementById('chat-input'); i.value = ${JSON.stringify(msg)}; await sendChat(); })()`);
    await sleep(700);
  };
  const results = [];
  const snap = async (name, vp) => {
    const check = await evalJs(`(() => {
      const w = document.getElementById('chatbot-window'); const b = document.getElementById('chat-body');
      const r = w.getBoundingClientRect(); const vw = document.documentElement.clientWidth;
      const targets = [...w.querySelectorAll('button, a.chat-btn, a.lp-btn, select, textarea, input[type=text], input[type=tel], input[type=email]')]
        .filter((e) => e.getClientRects().length && !e.closest('[hidden]'))
        .map((e) => ({ t: (e.innerText || e.getAttribute('name') || e.getAttribute('aria-label') || '').trim().slice(0, 28), h: Math.round(e.getBoundingClientRect().height), chip: e.classList.contains('chat-chip') }))
        .filter((x) => x.h < 44 && !x.chip);
      return { pageOverflow: document.documentElement.scrollWidth - vw, chatOverflow: b.scrollWidth - b.clientWidth, windowInside: r.left >= 0 && r.right <= vw + 0.5, short: targets };
    })()`);
    const buttons = await evalJs(BUTTONS);
    const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 80 });
    const file = `${name}-${vp}.jpg`;
    fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
    const row = { file, pageOverflow: check.pageOverflow, chatOverflow: check.chatOverflow, windowInside: check.windowInside, short: check.short, buttons: buttons.count, minContrast: Math.min(...buttons.rows.filter((r) => !/btn-hero/.test(r.cls)).map((r) => r.min)), buttonFailures: buttons.failures.filter((f) => !/Customize Furniture|Contact Us/.test(f.text)).map((f) => ({ text: f.text, min: f.min })) };
    results.push(row);
    const bad = row.pageOverflow > 0 || row.chatOverflow > 0 || !row.windowInside || row.short.length || row.buttonFailures.length;
    console.log((bad ? 'CHECK ' : 'ok    ') + file, bad ? JSON.stringify({ po: row.pageOverflow, co: row.chatOverflow, in: row.windowInside, s: row.short, b: row.buttonFailures }) : `(${row.buttons} buttons)`);
  };
  const snapPage = async (name, vp, pre) => {
    if (pre) { try { await evalJs(pre); } catch (e) { console.log('  pre failed for ' + name + ': ' + String(e.message).slice(0, 100)); } await sleep(400); }
    const check = await evalJs(`(() => {
      const small = [...document.querySelectorAll('.lp-btn, button, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select, textarea')]
        .filter((e) => e.getClientRects().length && !e.closest('[hidden]') && !e.closest('nav, header, footer, .chatbot-window, .admin-sidebar, .si-hp') && !e.matches('.lp-btn--chip'))
        .map((e) => ({ t: (e.innerText || e.getAttribute('name') || '').trim().slice(0, 28) || (e.tagName + '.' + e.className).slice(0, 50), h: Math.round(e.getBoundingClientRect().height) })).filter((x) => x.h < 44);
      return { pageOverflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, short: small };
    })()`);
    const buttons = await evalJs(BUTTONS);
    const metrics = await send('Page.getLayoutMetrics');
    const h = Math.min(Math.ceil(metrics.cssContentSize.height), 4000);
    const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 78, captureBeyondViewport: true, clip: { x: 0, y: 0, width: vp, height: h, scale: 1 } });
    const file = `${name}-${vp}.jpg`;
    fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
    const row = { file, pageOverflow: check.pageOverflow, short: vp === 390 ? check.short : [], buttons: buttons.count, minContrast: Math.min(...buttons.rows.filter((r) => !/btn-hero/.test(r.cls)).map((r) => r.min)), buttonFailures: buttons.failures.filter((f) => !['Customize Furniture', 'Contact Us', 'Close alert'].includes(f.text)).map((f) => ({ text: f.text, min: f.min })) };
    results.push(row);
    const bad = row.pageOverflow > 0 || row.short.length || row.buttonFailures.length;
    console.log((bad ? 'CHECK ' : 'ok    ') + file, bad ? JSON.stringify({ po: row.pageOverflow, s: row.short, b: row.buttonFailures }) : `(${row.buttons} buttons)`);
  };
  const signInAdmin = async () => {
    await go('/admin/login');
    return evalJs(`(async () => {
      const page = await (await fetch('/admin/login')).text();
      const tok = page.match(/name="_token" value="([^"]+)"/)[1];
      const r = await fetch('/admin/login', { method: 'POST', body: new URLSearchParams({ _token: tok, email: '${login.admin_email}', password: '${login.admin_password}' }), redirect: 'manual', credentials: 'same-origin' });
      return r.type + r.status;
    })()`);
  };
  const part6 = JSON.parse(execFileSync('php', [path.join(__dirname, 'seed_part6.php')], { cwd: ROOT, encoding: 'utf8' }));
  // A new session every time: signing in as the admin in the same browser ends the customer's session.
  const newCookie = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'session-cookie.php')], { cwd: ROOT, encoding: 'utf8' }));
  let cookie = newCookie();
  const fillForm = (card) => `(() => {
    const f = document.querySelector('.chat-form');
    f.elements.reason_code.value = 'change_of_mind'; f.elements.reason_text.value = 'My plans changed.';
    ${card === 'paid' ? "f.querySelector('input[name=refund_method][value=gcash]').click(); f.elements.gcash_number.value = '0918 111 2222'; f.elements.gcash_account_name.value = 'Maria S.';" : ''}
    f.elements.terms.checked = true;
  })()`;

  for (const vp of [390, 1280]) {
    await send('Emulation.setDeviceMetricsOverride', { width: vp, height: vp === 390 ? 844 : 900, deviceScaleFactor: 1, mobile: vp < 500 });

    // ── guest ──
    await send('Network.clearBrowserCookies');
    await go('/');
    await say('other service');                    await snap('chat-other-service-answer', vp);
    await go('/'); await say('qwerty zzz asdf');   await snap('chat-fallback-chips', vp);
    await say('zxcv bnm lkj');                     await snap('chat-fallback-second-miss-contact-card', vp);
    await send('Network.clearBrowserCookies');   // a new visitor: the earlier answers set the side (Other Services), so she would not ask
    await go('/'); await say('refund');            await snap('chat-ambiguity-question', vp);
    await say('Other Services job');               await snap('chat-ambiguity-other-services-guest', vp);
    await send('Network.clearBrowserCookies');
    await go('/'); await say('gaano katagal ang pagpapaayos po'); await say('sige po'); await snap('chat-tagalog-exchange', vp);

    // ── signed-in customer ──
    await send('Network.clearBrowserCookies');
    cookie = newCookie();
    await send('Network.setCookie', { name: cookie.name, value: cookie.value, domain: '127.0.0.1', path: '/', httpOnly: true });
    await go('/');
    await say('where is my ticket');               await snap('chat-status-answer-with-cards', vp);
    await say('where is ' + 'REF-2026-0001');       await snap('chat-reference-number-lookup', vp);
    await say('INV-2026-0001');                    await snap('chat-invoice-number-lookup', vp);
    await go('/'); await say('I want a refund for my repair'); await snap('chat-refund-pick-a-request', vp);
    await say(vp === 390 ? 'SI-2026-9001' : 'SI-2026-9013'); await snap('chat-refund-form-card', vp);
    await evalJs(`document.querySelector('.chat-form button[type=submit]').click()`); await sleep(500);
    await snap('chat-refund-form-errors', vp);
    await evalJs(fillForm('paid')); await evalJs(`document.querySelector('.chat-form button[type=submit]').click()`); await sleep(900);
    await snap('chat-refund-confirm-summary', vp);
    await evalJs(`document.querySelector('.chat-summary .chat-check input').click(); document.querySelector('.chat-summary .lp-btn--primary').click()`); await sleep(1200);
    await snap('chat-refund-success-reply', vp);

    // ── notification page with the Ask Luma button ──
    await go('/notifications/' + ids.notifications.approved);
    await snap('notification-details-ask-luma-button', vp).catch(() => {});
    await evalJs(`document.getElementById('nd-ask-luma').click()`); await sleep(1200);
    await snap('notification-ask-luma-answer', vp);
  }
  // ── Stage 21i part 6: the customer ticket form and the admin pages (customer first, then admin) ──
  for (const vp of [390, 1280]) {
    await send('Emulation.setDeviceMetricsOverride', { width: vp, height: vp === 390 ? 844 : 900, deviceScaleFactor: 1, mobile: vp < 500 });
    await send('Network.clearBrowserCookies');
    cookie = newCookie();
    await send('Network.setCookie', { name: cookie.name, value: cookie.value, domain: '127.0.0.1', path: '/', httpOnly: true });
    await go('/services/request');
    await snapPage('ticket-form-coaching-photos-location', vp);
    await go('/services/request');
    await evalJs(`(() => { document.getElementById('concern').value = 'My table is broken a bit.'; document.querySelector('input[name=service_type]').click(); document.getElementById('name').value = 'Maria Santos'; document.getElementById('phone').value = '09171234567'; document.getElementById('email').value = 'maria@example.test'; document.querySelector('input[name=terms]').checked = true; document.getElementById('si-form').submit(); })()`);
    await sleep(1500);
    await snapPage('ticket-form-vague-error-shown', vp);

    console.log('admin sign-in:', await signInAdmin());
    await go('/admin/service-inquiries/' + part6.vague);
    await snapPage('admin-ticket-vague-warning', vp);
    await go('/admin/service-inquiries/' + part6.photo);
    await snapPage('admin-ticket-photo-and-place', vp);
    await go('/admin/service-invoices/' + part6.draft);
    await snapPage('admin-invoice-send-modal-needs-tick', vp, `document.querySelector('[data-open-send]').click()`);
  }
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(results, null, 2));
  ws.close();
  chrome.kill();
}

main().catch((e) => { console.error(e); process.exit(1); });
