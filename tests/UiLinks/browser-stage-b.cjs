/*
 * Stage B admin product edit screenshots (SCRATCH DATABASE ONLY). Saves docs/stage-b-screenshots/*.png and checks that the page
 * does not scroll sideways at 390px. Needs: the scratch app on :8011 (tests/Rbac/serve_test.ps1), a product with extra photos,
 * and a session cookie minted by tests/UiLinks/session-cookie-for.php (admin guard).
 *
 *   node --experimental-websocket tests/UiLinks/browser-stage-b.cjs <productId>
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-b-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const ID = process.argv[2] || '1';
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

(async () => {
  const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookie = JSON.parse(execFileSync('php', ['tests/UiLinks/session-cookie-for.php', login.admin_email, 'admin'], { cwd: ROOT }).toString());
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9338', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) { await sleep(200); try { target = (await getJson('http://127.0.0.1:9338/json')).find((t) => t.type === 'page'); } catch (e) { /* wait */ } }
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0; const waiting = new Map();
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && waiting.has(d.id)) { waiting.get(d.id)(d.result || d); waiting.delete(d.id); } };
  const send = (method, params = {}) => new Promise((r) => { const id = ++n; waiting.set(id, r); ws.send(JSON.stringify({ id, method, params })); });
  await send('Network.enable');
  await send('Network.setCookie', { name: cookie.name, value: cookie.value, url: BASE });
  const results = {};
  for (const [w, h] of [[1280, 900], [390, 844]]) {
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 });
    await send('Page.navigate', { url: `${BASE}/admin/products/${ID}/edit` });
    await sleep(2500);
    const shot = async (name, y) => {
      await send('Runtime.evaluate', { expression: `window.scrollTo(0, ${y})` });
      await sleep(300);
      const r = await send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(OUT, `${name}-${w}.png`), Buffer.from(r.data, 'base64'));
    };
    await shot('product-edit-top', 0);
    const pos = await send('Runtime.evaluate', { expression: `(() => { const e = document.getElementById('extra-photos'); return e ? e.getBoundingClientRect().top + scrollY - 20 : -1; })()`, returnByValue: true });
    await shot('product-edit-photos', Math.max(0, pos.result.value));
    const o = await send('Runtime.evaluate', { expression: `({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth })`, returnByValue: true });
    results[w] = o.result.value;
  }
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(results, null, 2));
  console.log(JSON.stringify(results));
  chrome.kill();
  process.exit(0);
})();
