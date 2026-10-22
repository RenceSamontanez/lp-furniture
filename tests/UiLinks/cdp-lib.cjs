/*
 * Tiny Chrome DevTools helper shared by the polish browser checks (SCRATCH DATABASE ONLY, http://127.0.0.1:8011).
 * start(port) launches headless Chrome and returns { send, evalJs, go, shot, tap, viewport, setCookie, close, ... }.
 * tap() sends real touch events (Input.dispatchTouchEvent), the same path a finger takes, not element.click().
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const os = require('os');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-polish-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

async function start(port = 9351) {
  fs.mkdirSync(OUT, { recursive: true });
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${port}`, `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) { await sleep(200); try { target = (await getJson(`http://127.0.0.1:${port}/json`)).find((t) => t.type === 'page'); } catch (e) { /* wait */ } }
  if (!target) throw new Error('Chrome did not start');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0; const waiting = new Map();
  ws.onmessage = (m) => { const d = JSON.parse(m.data); if (d.id && waiting.has(d.id)) { waiting.get(d.id)(d.result || d); waiting.delete(d.id); } };
  const send = (method, params = {}) => new Promise((r) => { const id = ++n; waiting.set(id, r); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text) + ' <- ' + expression.slice(0, 120));
    return r.result.value;
  };
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  const viewport = async (w, h = 900) => {
    await send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 });
    // a phone has no hover and a coarse pointer: the page's (hover: none) rules must apply, as they do on a real phone
    await send('Emulation.setEmulatedMedia', { features: [{ name: 'hover', value: w < 700 ? 'none' : 'hover' }, { name: 'pointer', value: w < 700 ? 'coarse' : 'fine' }] });
    await send('Emulation.setTouchEmulationEnabled', { enabled: w < 700, maxTouchPoints: 5 });
  };
  const go = async (p) => { await send('Page.navigate', { url: BASE + p }); await sleep(900); for (let i = 0; i < 40; i++) { if ((await evalJs('document.readyState')) === 'complete') break; await sleep(150); } await sleep(400); };
  const shot = async (name) => { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const setCookie = async (c) => { await send('Network.clearBrowserCookies'); if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); };
  const tap = async (x, y) => {
    await send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y }] });
    await send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  };
  const close = () => { try { ws.close(); } catch (e) { /* done */ } chrome.kill(); };
  return { send, evalJs, go, shot, tap, viewport, setCookie, close, sleep, BASE, ROOT, OUT };
}

module.exports = { start, ROOT, OUT, BASE, sleep };
