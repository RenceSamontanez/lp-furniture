/*
 * Luma URL walk: loads pages at different depths at 390px and 1280px in headless Chrome against the SCRATCH app
 * (http://127.0.0.1:8011, tests/Rbac/serve_test.ps1, which refuses non-_test databases), opens Luma, checks that the stage pose loaded,
 * sends "labor only" and "How to order", and logs every Luma-related request (URL, status) from the real network log.
 *
 *   node --experimental-websocket tests/Luma/browser-urls.cjs [stale]
 *   OUT=<dir> overrides the screenshot folder (default docs/stage9a-fix-screenshots).
 *
 * "stale" also runs the expired-session scenario: the session cookie is removed after the page loads, then a message is sent.
 * Signed-in pages use a session minted by tests/Luma/session-cookie.php (scratch database only; the sign-in form is not bypassed).
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = process.env.OUT || path.join(ROOT, 'docs', 'stage9a-fix-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const mode = process.argv[2] || 'all';
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

const PAGES = [
  ['home', '/', false],
  ['product', null, false],   // first product on /catalog, found at run time
  ['terms', '/services/terms', false],
  ['cart', '/cart', true],
  ['notifications', '/notifications', true],
  ['service-request', '/services/request', true],
];

async function main() {
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9336', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try { target = (await getJson('http://127.0.0.1:9336/json')).find((t) => t.type === 'page'); } catch (e) { /* not up yet */ }
  }
  if (!target) throw new Error('Chrome did not start');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0;
  const pending = new Map();
  const events = [];
  let net = [];
  const reqs = new Map();
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { const { res, rej } = pending.get(d.id); pending.delete(d.id); d.error ? rej(new Error(JSON.stringify(d.error))) : res(d.result); return; }
    if (!d.method) return;
    events.push(d.method);
    const p = d.params || {};
    if (d.method === 'Network.requestWillBeSent') reqs.set(p.requestId, { url: p.request.url, method: p.request.method });
    if (d.method === 'Network.responseReceived') { const r = reqs.get(p.requestId) || { url: p.response.url, method: '?' }; net.push({ ...r, url: p.response.url, status: p.response.status }); }
    if (d.method === 'Network.loadingFailed') { const r = reqs.get(p.requestId); if (r) net.push({ ...r, status: 'FAILED ' + p.errorText }); }
  };
  const send = (method, params = {}) => new Promise((res, rej) => { const id = ++n; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('JS error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  };
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');
  await send('Network.setCacheDisabled', { cacheDisabled: true });
  const setVp = (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });
  const go = async (url) => {
    events.length = 0; net = [];
    await send('Page.navigate', { url: BASE + url });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(700);
  };
  const cookie = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'session-cookie.php')], { cwd: ROOT, encoding: 'utf8' }));
  const setSession = async (c) => send('Network.setCookie', { name: c.name, value: c.value, domain: '127.0.0.1', path: '/', httpOnly: true });
  const shot = async (file) => fs.writeFileSync(path.join(OUT, file), Buffer.from((await send('Page.captureScreenshot', { format: 'jpeg', quality: 80 })).data, 'base64'));
  const lastBot = () => evalJs(`(() => { const m = [...document.querySelectorAll('#chat-body .chat-msg.bot:not(.chat-thinking)')].pop(); return m ? m.innerText.trim().slice(0, 90) : null; })()`);
  const poseState = () => evalJs(`(() => { const i = document.querySelector('#luma-stage .luma-pic img.on'); return i ? { src: i.currentSrc || i.src, ok: i.complete && i.naturalWidth > 0, w: i.naturalWidth } : null; })()`);
  const say = async (msg) => {
    await evalJs(`(async () => { const i = document.getElementById('chat-input'); i.value = ${JSON.stringify(msg)}; await sendChat(); })()`);
    await sleep(1500);
  };

  const catalog = await new Promise((res, rej) => http.get(BASE + '/catalog', (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => res(d)); }).on('error', rej));
  const productUrl = (catalog.match(/\/product\/\d+/) || ['/product/1'])[0];
  let failures = 0;
  const report = (label, ok, extra) => { if (!ok) failures++; console.log((ok ? 'ok   ' : 'FAIL ') + label + (extra ? '  ' + extra : '')); };

  for (const vp of [390, 1280]) {
    await setVp(vp, vp === 390 ? 844 : 900);
    for (const [name, url0, auth] of PAGES) {
      const url = url0 || productUrl;
      await send('Network.clearBrowserCookies');
      if (auth) await setSession(cookie());
      await go(url);
      const where = `${name}@${vp} ${url}`;
      const landed = await evalJs('location.pathname');
      if (landed !== url) console.log(`note ${where} landed on ${landed}`);
      await evalJs(`(() => { try { localStorage.clear(); } catch (e) {} if (!document.getElementById('chatbot-window').classList.contains('open')) toggleChatbot(); })()`);
      await sleep(500);
      const pose = await poseState();
      report(`${where} pose`, !!(pose && pose.ok), JSON.stringify(pose));
      for (const msg of ['labor only', 'How to order']) {
        net = [];
        await say(msg);
        const reply = await lastBot();
        const post = net.filter((r) => r.method === 'POST');
        const bad = /having trouble/.test(reply || '') || post.some((r) => r.status !== 200);
        report(`${where} "${msg}"`, !bad, `${post.map((r) => r.status + ' ' + r.url).join(', ')} -> ${reply}`);
      }
      const luma = net.concat([]).filter((r) => /luma|chatbot/.test(r.url));
      const badNet = luma.filter((r) => r.status !== 200);
      if (badNet.length) console.log('  bad luma requests:', JSON.stringify(badNet));
      const bc = await evalJs(BUTTONS);
      // storefront buttons outside the widget that fail on their own (hero and product-card buttons) are known and out of scope here
      bc.failures = bc.failures.filter((f) => !/Customize Furniture|Contact Us|Shop Now|Order Now|View Details/.test(f.text) && !/btn-view-details/.test(f.cls));
      report(`${where} button contrast (${bc.count} buttons)`, bc.failures.length === 0, JSON.stringify(bc.failures.map((f) => f.text + ' ' + f.min)));
      await shot(`${name}-${vp}.jpg`);
    }
  }

  if (mode === 'stale') {
    await setVp(390, 844);
    await send('Network.clearBrowserCookies');
    await go('/');
    await evalJs(`toggleChatbot()`);
    await send('Network.clearBrowserCookies');   // the session is gone, as after the 30 minute idle expiry
    net = [];
    await say('labor only');
    const reply = await lastBot();
    const kept = await evalJs(`document.getElementById('chat-input').value`);
    const post = net.filter((r) => r.method === 'POST');
    console.log('STALE-SESSION:', JSON.stringify(post.map((r) => r.status + ' ' + r.url)), '->', reply, '| input kept:', JSON.stringify(kept));
  }

  if (mode === 'stale') {
    // The typed text must survive a failed request (resend without retyping), and the send button must work again.
    report('stale session: error shown and typed text kept', /having trouble/.test(await lastBot() || '') && (await evalJs(`document.getElementById('chat-input').value`)) === 'labor only');

    // A pose that fails to load falls back to the smiling pose; if that fails too the image is hidden (no broken-image icon).
    await go('/');
    await send('Network.setBlockedURLs', { urls: ['*/images/luma/luma-asked.png', '*/images/luma/luma-working.png', '*/images/luma/luma-wondering.png'] });
    await evalJs(`toggleChatbot()`);
    await say('labor only');
    await sleep(800);
    const fell = await evalJs(`(() => { const i = document.querySelector('#luma-stage .luma-pic img.on'); return { src: i.getAttribute('src'), ok: i.complete && i.naturalWidth > 0, vis: getComputedStyle(i).visibility, stage: document.getElementById('luma-stage').getBoundingClientRect().height }; })()`);
    report('pose fails -> smiling pose', /luma-smiling\.png$/.test(fell.src) && fell.ok, JSON.stringify(fell));
    await go('/');
    await send('Network.setBlockedURLs', { urls: ['*/images/luma/luma-greeting.png', '*/images/luma/luma-smiling.png'] });
    await go('/');
    await evalJs(`toggleChatbot()`);
    await sleep(500);
    const hid = await evalJs(`(() => { const i = document.querySelector('#luma-stage .luma-pic img.on'); return { vis: getComputedStyle(i).visibility, stage: document.getElementById('luma-stage').getBoundingClientRect().height, pic: document.querySelector('#luma-stage .luma-pic').getBoundingClientRect().height }; })()`);
    report('pose and smiling fail -> image hidden, area reserved', hid.vis === 'hidden' && hid.pic > 50, JSON.stringify(hid));
    await shot('pose-all-blocked-390.jpg');
    await send('Network.setBlockedURLs', { urls: [] });
  }

  console.log(failures ? `\n${failures} FAILURES` : '\nall ok');
  ws.close(); chrome.kill();
  process.exit(failures ? 1 : 0);
}
main().catch((e) => { console.error(e); process.exit(2); });
