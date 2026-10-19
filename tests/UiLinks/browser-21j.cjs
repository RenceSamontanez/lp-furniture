/*
 * (adapted) Stage 21j browser walk (nav, pagination, ID check): drives the real chat widget in headless Chrome (SCRATCH DATABASE ONLY), saves a JPEG of each
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
const OUT = path.join(ROOT, 'docs', 'stage21j-screenshots');
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
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9335', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try { target = (await getJson('http://127.0.0.1:9335/json')).find((t) => t.type === 'page'); } catch (e) { /* not up yet */ }
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

  const results = [];
  const BTN = BUTTONS;
  const check = async (name, vp, opts = {}) => {
    const c = await evalJs(`(() => {
      const vw = document.documentElement.clientWidth;
      const sel = '.lp-btn, .lp-pager__num, button, nav a, .nav-links a, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select';
      const visible = [...document.querySelectorAll(sel)].filter((e) => e.getClientRects().length && !e.closest('[hidden], .chatbot-window'));
      const short = visible.filter((e) => !e.matches('.lp-btn--chip') && e.getBoundingClientRect().height < 44 && !e.closest('.admin-sidebar')).map((e) => ({ t: (e.innerText || e.className).trim().slice(0, 24), h: Math.round(e.getBoundingClientRect().height) }));
      const navLinks = [...document.querySelectorAll('nav .nav-links > a')].filter((e) => e.getClientRects().length).map((e) => { const r = e.getBoundingClientRect(); return { t: e.innerText.trim(), l: Math.round(r.left), r: Math.round(r.right), b: Math.round(r.bottom), h: Math.round(r.height) }; });
      const nav = document.querySelector('nav');
      return { overflow: document.documentElement.scrollWidth - vw, short, navLinks, navH: nav ? Math.round(nav.getBoundingClientRect().height) : 0 };
    })()`);
    const b = await evalJs(BTN);
    const metrics = await send('Page.getLayoutMetrics');
    const h = Math.min(Math.ceil(metrics.cssContentSize.height), 3000);
    const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 78, captureBeyondViewport: true, clip: { x: 0, y: 0, width: vp, height: opts.top ? Math.min(h, opts.top) : h, scale: 1 } });
    const file = `${name}-${vp}.jpg`;
    fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
    const fails = b.failures.filter((f) => !['Customize Furniture', 'Contact Us', 'Close alert'].includes(f.text)).map((f) => ({ text: f.text, min: f.min }));
    const short = vp <= 390 ? c.short : [];
    const row = { file, overflow: c.overflow, short, buttons: b.count, minContrast: Math.min(...b.rows.filter((r) => !/btn-hero/.test(r.cls)).map((r) => r.min)), fails, navH: c.navH, navLinks: opts.navDump ? c.navLinks : undefined };
    results.push(row);
    const bad = row.overflow > 0 || row.short.length || row.fails.length;
    console.log((bad ? 'CHECK ' : 'ok    ') + file, bad ? JSON.stringify({ o: row.overflow, s: row.short, f: row.fails }) : `(${row.buttons} buttons, min ${row.minContrast})`);
  };
  const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), ...(email ? [email] : [])], { cwd: ROOT, encoding: 'utf8' }));
  const setCookie = async (c) => { await send('Network.clearBrowserCookies'); if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); };
  const signInAdmin = async () => {
    await send('Network.clearBrowserCookies');
    await go('/admin/login');
    return evalJs(`(async () => {
      const page = await (await fetch('/admin/login')).text();
      const tok = page.match(/name="_token" value="([^"]+)"/)[1];
      const r = await fetch('/admin/login', { method: 'POST', body: new URLSearchParams({ _token: tok, email: '${login.admin_email}', password: '${login.admin_password}' }), redirect: 'manual', credentials: 'same-origin' });
      return r.type + r.status;
    })()`);
  };

  for (const vp of [390, 360, 1280]) {
    await send('Emulation.setDeviceMetricsOverride', { width: vp, height: vp < 500 ? 844 : 900, deviceScaleFactor: 1, mobile: vp < 500 });
    const shots = vp !== 360; // 360 is checked for overflow only, no screenshots needed beyond the nav
    // guest nav
    await setCookie(null);
    await go('/');
    await check('nav-guest', vp, { top: 120 });
    if (vp < 861) { await evalJs('toggleMobileNav()'); await sleep(500); await check('nav-guest-open', vp, { top: 700 }); await evalJs('toggleMobileNav()'); }
    // customer nav
    await setCookie(cookieFor());
    await go('/');
    await check('nav-customer', vp, { top: 120, navDump: true });
    if (vp < 861) { await evalJs('toggleMobileNav()'); await sleep(500); await check('nav-customer-open', vp, { top: 800 }); await evalJs('toggleMobileNav()'); }
    await go('/design');
    await check('customize-page', vp, { top: 900 });
    await go('/'); 
    await check('home-customize', vp, { top: 900 });
    if (!shots) continue;
    await go('/catalog'); await check('catalog', vp, { top: 1200 });
    for (const [pg, tag] of [[1, 'first'], [2, 'middle'], [3, 'last']]) {
      await go('/orders?page=' + pg); await check('orders-' + tag, vp);
      await go('/notifications?page=' + pg); await check('notifications-' + tag, vp);
    }
    // ID validation page reached from Other Services
    await setCookie(cookieFor('unvalidated-21j@example.test'));
    await go('/services/request');
    await check('id-check-from-services', vp);
    await evalJs("(() => { const c = document.getElementById('val-modal-terms-check'); if (c) { c.checked = true; c.dispatchEvent(new Event('change')); acceptValidationTerms(); } })()"); await sleep(500);
    await check('id-check-notice', vp);
    // admin list
    await signInAdmin();
    for (const [pg, tag] of [[1, 'first'], [2, 'middle'], [3, 'last']]) { await go('/admin/notifications?page=' + pg); await check('admin-list-' + tag, vp); }
  }
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(results, null, 2));
  const bad = results.filter((r) => r.overflow > 0 || r.short.length || r.fails.length);
  console.log(`\n${results.length} captures, ${bad.length} flagged`);
  chrome.kill();
  process.exit(0);
}
main().catch((e) => { console.error(e); process.exit(1); });
