/*
 * Guest vs signed-in nav scroll comparison (SCRATCH DATABASE ONLY, server on :8011 as in browser-nav.cjs).
 * Scrolls home and catalog in steps for both visitors and prints the nav's light class, collapsed state and computed background.
 *   node --experimental-websocket tests/UiLinks/nav-guest-vs-user.cjs
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');
const ROOT = path.resolve(__dirname, '..', '..');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

(async () => {
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9339', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) { await sleep(200); try { target = (await getJson('http://127.0.0.1:9339/json')).find((t) => t.type === 'page'); } catch (e) { /* */ } }
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0; const pending = new Map(); const events = [];
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && pending.has(d.id)) { const { res, rej } = pending.get(d.id); pending.delete(d.id); d.error ? rej(new Error(JSON.stringify(d.error))) : res(d.result); } else if (d.method) events.push(d.method); };
  const send = (method, params = {}) => new Promise((res, rej) => { const id = ++n; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
  const ev = async (e) => (await send('Runtime.evaluate', { expression: e, returnByValue: true, awaitPromise: true })).result.value;
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: +(process.env.W||1440), height: 900, deviceScaleFactor: 1, mobile: +(process.env.W||1440) < 500 });
  const logins = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookie = () => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.customer_email, 'web'], { cwd: ROOT, encoding: 'utf8' }));
  const go = async (url) => { events.length = 0; await send('Page.navigate', { url: BASE + url }); for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; } await sleep(700); };
  const out = {};
  for (const who of ['guest', 'user']) {
    await send('Network.clearBrowserCookies');
    if (who === 'user') { const c = cookie(); await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); }
    for (const url of (process.env.PAGES || '/,/catalog').split(',')) {
      await go(url);
      const rows = [];
      for (const y of (process.env.W ? [0,300,700,1200] : [0, 300, 700, 1200, 1800, 2500, 3200])) {
        await ev(`window.scrollTo(0, ${y + 12})`); await sleep(120); await ev(`window.scrollTo(0, ${y})`); await sleep(500);
        rows.push(await ev(`(() => { const n = document.getElementById('site-nav'); if (!n) return { nav: false }; const cs = getComputedStyle(n);
          const under = document.elementsFromPoint(innerWidth / 2, 46).filter((e) => !n.contains(e)).slice(0, 2).map((e) => e.tagName + '.' + String(e.className).slice(0, 30) + '[' + ((e.closest('[data-nav]') || {getAttribute: () => '-'}).getAttribute('data-nav')) + ']').join(' > ');
          return { y: ${y}, light: n.classList.contains('light'), collapsed: n.classList.contains('collapsed'), bg: cs.backgroundColor, color: getComputedStyle(n.querySelector('.sn-link')).color, under, hasJs: typeof window.lpNavTheme }; })()`));
      }
      out[who + ' ' + url] = rows;
    }
  }
  for (const k of Object.keys(out)) { console.log('\n== ' + k); for (const r of out[k]) console.log(JSON.stringify(r)); }
  chrome.kill(); process.exit(0);
})();
