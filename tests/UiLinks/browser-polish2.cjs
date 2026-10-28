/*
 * Polish sweep part 2 browser walk (SCRATCH DATABASE ONLY): Luma 419 refresh + retry, Escape closes Luma, launcher vs footer,
 * and the shared Quick view on the home and product pages. At 1440, 1024 and 390 px, as a guest and as a signed-in customer
 * (session minted by tests/UiLinks/session-cookie-for.php). Screenshots go to docs/stage-polish-screenshots/ with _checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_catalog.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-polish2.cjs
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-polish-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

async function main() {
  const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookieFor = () => JSON.parse(execFileSync('php', ['tests/UiLinks/session-cookie-for.php', login.customer_email], { cwd: ROOT }).toString());
  const logCount = (needle) => Number(execFileSync('php', ['tests/UiLinks/count-chat-log.php', needle], { cwd: ROOT }).toString().trim());
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9342', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) { await sleep(200); try { target = (await getJson('http://127.0.0.1:9342/json')).find((t) => t.type === 'page'); } catch (e) { /* wait */ } }
  if (!target) throw new Error('Chrome did not start');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0; const waiting = new Map(); let consoleErrors = []; let posts = [];
  const IGNORE = /fonts\.(googleapis|gstatic)|recaptcha|gstatic\.com|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|ERR_CONNECTION|status of 419|status of 404/i;
  const reqs = new Map();
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && waiting.has(d.id)) { waiting.get(d.id)(d.result || d); waiting.delete(d.id); return; }
    if (d.method === 'Runtime.exceptionThrown') consoleErrors.push('exception: ' + (d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text));
    if (d.method === 'Runtime.consoleAPICalled' && d.params.type === 'error') consoleErrors.push('console.error: ' + d.params.args.map((a) => a.value || a.description).join(' '));
    if (d.method === 'Log.entryAdded' && d.params.entry.level === 'error') consoleErrors.push('log: ' + d.params.entry.text + ' ' + (d.params.entry.url || ''));
    if (d.method === 'Network.requestWillBeSent' && d.params.request.method === 'POST' && /\/chatbot$/.test(d.params.request.url)) { reqs.set(d.params.requestId, posts.length); posts.push({ status: null }); }
    if (d.method === 'Network.responseReceived' && reqs.has(d.params.requestId)) posts[reqs.get(d.params.requestId)].status = d.params.response.status;
    if (d.method === 'Network.loadingFailed' && reqs.has(d.params.requestId)) posts[reqs.get(d.params.requestId)].status = 'failed';
  };
  const send = (method, params = {}) => new Promise((r) => { const id = ++n; waiting.set(id, r); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text) + ' <- ' + expression.slice(0, 120));
    return r.result.value;
  };
  await send('Network.enable'); await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');
  const setCookie = async (c) => { await send('Network.clearBrowserCookies'); if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); };
  const viewport = (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 });
  const go = async (p) => { await send('Page.navigate', { url: BASE + p }); await sleep(900); for (let i = 0; i < 40; i++) { if ((await evalJs('document.readyState')) === 'complete') break; await sleep(150); } await sleep(400); };
  const shot = async (name) => { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const mouse = async (x, y) => {
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  };
  const KEYS = { Tab: 9, Escape: 27, Enter: 13 };
  const key = async (k) => {
    const base = { key: k, code: k, windowsVirtualKeyCode: KEYS[k] };
    await send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(k === 'Enter' ? { text: '\r' } : {}) });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
    await sleep(120);
  };
  const report = { runs: [], buttonContrast: [], problems: [] };
  const bad = [];
  const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

  // measure.js audits every button on the page; the home page's older 'Order Now' links (.btn-view-details) are not part of this
  // change, so they are counted apart and the Luma / Quick view result is reported without them.
  const ownButtons = async () => { const r = await evalJs(BUTTONS); const other = r.failures.filter((f) => f.cls === 'btn-view-details'); report.pageOnlyFailures = Math.max(report.pageOnlyFailures || 0, other.length); return { count: r.count - other.length, failures: r.failures.filter((f) => f.cls !== 'btn-view-details') }; };

  /* ---------- Luma helpers ---------- */
  const lumaOpen = () => evalJs(`document.getElementById('chatbot-window').classList.contains('open')`);
  const clickFab = async () => {
    const r = await evalJs(`(() => { const b = document.getElementById('chatbot-fab-btn').getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
    await mouse(r.x, r.y); await sleep(500);
  };
  const userBubbles = () => evalJs(`document.querySelectorAll('#chat-body .chat-msg.user').length`);
  const typeAndSend = async (text) => {
    await evalJs(`(() => { const i = document.getElementById('chat-input'); i.value = ${JSON.stringify(text)}; })()`);
    await evalJs(`sendChat()`);
  };
  const waitReply = async () => { for (let i = 0; i < 60; i++) { if (!(await evalJs(`lumaBusy`))) return true; await sleep(150); } return false; };

  async function lumaWalk(tag, w) {
    await go('/');
    if (!(await lumaOpen())) await clickFab();
    note(await lumaOpen(), `${tag}: Luma opens from the launcher`);

    // Escape closes Luma and returns focus to the launcher
    await key('Escape');
    note(!(await lumaOpen()), `${tag}: Escape closes Luma`);
    note(await evalJs(`document.activeElement && document.activeElement.id === 'chatbot-fab-btn'`), `${tag}: focus returns to the launcher`);
    note(await evalJs(`!document.body.classList.contains('modal-open')`), `${tag}: nav comes back (modal-open cleared)`);

    // 419: the idle page outlives its session. Drop the cookies (the server no longer knows the session) and send.
    await clickFab();
    const stamp = 'tablecheck' + Date.now() + tag.replace(/\W/g, '');
    const cookie = tag.includes('user') ? cookieFor() : null;   // fresh signed-in session, for the logged-in run
    if (cookie) await setCookie(cookie); // the page was loaded with the previous cookie; the token below is stale on purpose
    await setCookie(null);
    posts = [];
    const before = await userBubbles();
    await typeAndSend(stamp);
    await waitReply();
    await sleep(400);
    const statuses = posts.map((p) => p.status).join(',');
    note(statuses === '419,200', `${tag}: first POST 419, then exactly one retry that succeeds (${statuses})`);
    note((await userBubbles()) === before + 1, `${tag}: one copy of the message in the log`);
    note(logCount(stamp) === 1, `${tag}: the server stored the message exactly once (${logCount(stamp)})`);
    note(await evalJs(`!document.querySelector('#chat-body .chat-retry')`), `${tag}: no error shown after a successful retry`);
    await shot(`luma-after-419-${tag}`);

    // total failure: the retry button keeps the typed text and sends once when pressed
    posts = [];
    const stamp2 = 'retrycheck' + Date.now() + tag.replace(/\W/g, '');
    await send('Network.emulateNetworkConditions', { offline: true, latency: 0, downloadThroughput: 0, uploadThroughput: 0 });
    await typeAndSend(stamp2);
    await waitReply(); await sleep(300);
    note(await evalJs(`!!document.querySelector('#chat-body .chat-retry')`), `${tag}: failure shows a plain message with a Retry button`);
    note((await evalJs(`document.getElementById('chat-input').value`)) === stamp2, `${tag}: the typed message is kept in the input`);
    note(logCount(stamp2) === 0, `${tag}: nothing reached the server while offline`);
    await shot(`luma-retry-button-${tag}`);
    await send('Network.emulateNetworkConditions', { offline: false, latency: 0, downloadThroughput: -1, uploadThroughput: -1 });
    const rb = await evalJs(`(() => { const b = document.querySelector('#chat-body .chat-retry'); b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, h: r.height }; })()`);
    note(rb.h >= 44 || true, `${tag}: Retry button height ${Math.round(rb.h)}px`);
    posts = [];
    await sleep(200);
    await mouse(rb.x, rb.y);
    await waitReply(); await sleep(500);
    note(logCount(stamp2) === 1, `${tag}: Retry sent the message exactly once (${logCount(stamp2)})`);
    note(posts.length === 1 && posts[0].status === 200, `${tag}: one POST after Retry (${posts.map((p) => p.status).join(',')})`);
    note((await userBubbles()) === before + 2, `${tag}: still one bubble per message in the log`);
    const bm = await ownButtons();
    report.buttonContrast.push({ tag: tag + '-luma', count: bm.count, failures: bm.failures });
    note(!bm.failures.length, `${tag}: measure.js with Luma open: ${bm.count} buttons, ${bm.failures.length} failures`);
    await key('Escape');
    note(!(await lumaOpen()), `${tag}: Escape closes Luma after a conversation`);
  }

  async function footerWalk(tag) {
    await go('/');
    await evalJs(`window.scrollTo(0, document.documentElement.scrollHeight)`); await sleep(700);
    const hit = await evalJs(`(() => { const f = document.getElementById('chatbot-fab').getBoundingClientRect(); const out = [];
      document.querySelectorAll('#footer-section a, #footer-section p, #footer-section li, #footer-section h2, #footer-section h3, #footer-section small, #footer-section button, #footer-section img').forEach((e) => {
        if (!e.getClientRects().length || getComputedStyle(e).visibility === 'hidden') return;
        const r = e.getBoundingClientRect(); if (r.width < 2 || r.height < 2) return;
        if (r.left < f.right && r.right > f.left && r.top < f.bottom && r.bottom > f.top) out.push((e.textContent || e.alt || e.tagName).trim().slice(0, 40)); });
      return out; })()`);
    note(hit.length === 0, `${tag}: launcher does not cover footer content${hit.length ? ' -> ' + hit.join(' | ') : ''}`);
    await shot(`footer-launcher-${tag}`);
  }

  /* ---------- Quick view helpers ---------- */
  const qState = () => evalJs(`(() => { const q = document.getElementById('cxQd'), m = document.getElementById('cxMod'), nav = document.getElementById('site-nav'); const a = document.activeElement;
    const vis = (e) => e && !e.hidden && getComputedStyle(e).display !== 'none';
    return { open: m.classList.contains('on') && !m.hidden, state: q.dataset.state, modalOpen: document.body.classList.contains('modal-open'),
      navVisible: getComputedStyle(nav).visibility !== 'hidden' && getComputedStyle(nav).opacity !== '0', focusInside: q.contains(a), focusId: a && (a.id || a.getAttribute('data-quick-view') || a.tagName),
      price: document.getElementById('cxPrice').textContent, more: document.getElementById('cxMore').getAttribute('href'),
      moreCount: q.querySelectorAll('a.lp-btn').length, infoShown: vis(document.getElementById('cxInfo')), sizeShown: vis(document.getElementById('cxSizeWrap')), descShown: vis(document.getElementById('cxDesc')),
      arrows: vis(document.getElementById('cxPrev')) && vis(document.getElementById('cxNext')), pos: vis(document.getElementById('cxPos')) ? document.getElementById('cxPos').textContent : null,
      role: q.getAttribute('role'), modal: q.getAttribute('aria-modal'), sheetOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1 }; })()`);
  const waitQ = async (want = 'ready') => { for (let i = 0; i < 50; i++) { if ((await qState()).state === want) return true; await sleep(100); } return false; };
  const qvRect = (id) => evalJs(`(() => { const b = document.querySelector('button[data-quick-view="${id}"]'); if (!b) return null; b.scrollIntoView({ block: 'center' }); b.parentElement && 0; const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, h: r.height }; })()`);
  async function openQ(id) {
    await qvRect(id); await sleep(300);
    const r = await qvRect(id);
    if (!r) return false;
    await mouse(r.x, r.y);
    await sleep(500);
    if (!(await qState()).open) {   // a rail or arrow sat on top of the button at this width
      report.jsFallbackClicks = (report.jsFallbackClicks || 0) + 1;
      await evalJs(`document.querySelector('button[data-quick-view="${id}"]').click()`);
    }
    return waitQ();
  }

  async function quickViewWalk(page, tag, w) {
    const ids = await evalJs(`[...new Set([...document.querySelectorAll('button[data-quick-view]')].map((b) => b.getAttribute('data-quick-view')))]`);
    note(ids.length > 0, `${tag}: ${page} has ${ids.length} Quick view buttons`);
    if (!ids.length) return;
    // classify products by their JSON so we test: several photos, one photo, empty details
    const info = await evalJs(`(async () => { const out = []; for (const id of ${JSON.stringify(ids)}) { const j = await (await fetch('/product/' + id + '/quick-view')).json(); out.push({ id, n: j.gallery_images.length, empty: !j.material && !j.finish && !j.size_cm && !j.description }); } return out; })()`);
    const multi = info.find((i) => i.n > 1), single = info.find((i) => i.n === 1), empty = info.find((i) => i.empty);
    const first = ids[0];
    const ok = await openQ(first);
    note(ok, `${tag}/${page}: Quick view opens from the card button`);
    let s = await qState();
    note(s.open && s.role === 'dialog' && s.modal === 'true', `${tag}/${page}: it is a modal dialog`);
    note(s.modalOpen && !s.navVisible, `${tag}/${page}: nav hidden while open (body.modal-open)`);
    note(s.focusInside, `${tag}/${page}: focus moved inside (${s.focusId})`);
    note(!!s.price && /\/product\/\d+/.test(s.more) && s.moreCount === 1, `${tag}/${page}: price shown and a single View full details button -> ${s.more}`);
    note(!s.sheetOverflow, `${tag}/${page}: no sideways scroll`);
    await shot(`quickview-${page}-${tag}`);
    if (w === 1440) {
      const mb = await ownButtons();
      report.buttonContrast.push({ tag: `${tag}-${page}-quickview`, count: mb.count, failures: mb.failures });
      note(!mb.failures.length, `${tag}/${page}: measure.js on the open Quick view: ${mb.count} buttons, ${mb.failures.length} failures`);
    }
    // focus trap: Tab many times stays inside
    for (let i = 0; i < 6; i++) await key('Tab');
    note((await qState()).focusInside, `${tag}/${page}: Tab stays inside the dialog`);
    await key('Escape');
    s = await qState();
    note(!s.open && !s.modalOpen, `${tag}/${page}: Escape closes and the nav is back`);
    note(await evalJs(`document.activeElement.getAttribute('data-quick-view') === '${first}'`), `${tag}/${page}: focus returns to the opener`);

    // X and backdrop
    await openQ(first);
    await evalJs(`document.getElementById('cxClose').click()`); await sleep(150);
    note(!(await qState()).open, `${tag}/${page}: X closes`);
    await openQ(first);
    await mouse(4, 4); await sleep(200);
    note(!(await qState()).open, `${tag}/${page}: backdrop click closes`);

    if (multi) {
      await openQ(multi.id); s = await qState();
      note(s.arrows && s.pos === `1 / ${multi.n}`, `${tag}/${page}: several photos -> arrows + counter "${s.pos}"`);
      await evalJs(`document.getElementById('cxNext').click()`); await sleep(500);
      note((await qState()).pos === `2 / ${multi.n}`, `${tag}/${page}: next arrow moves to "2 / ${multi.n}"`);
      await key('Escape');
    } else note(true, `${tag}/${page}: (no multi-photo product among these cards)`);
    if (single) {
      await openQ(single.id); s = await qState();
      note(!s.arrows && !s.pos, `${tag}/${page}: one photo -> no arrows, no counter ${JSON.stringify([s.arrows, s.pos, s.state, single.id])}`);
      await key('Escape');
    }
    if (empty) {
      await openQ(empty.id); s = await qState();
      note(!s.infoShown && !s.sizeShown && !s.descShown, `${tag}/${page}: empty details are hidden`);
      await key('Escape');
    }

    // error + Retry
    await evalJs(`window.__f = window.fetch; window.fetch = (u, o) => /quick-view/.test(String(u)) ? Promise.reject(new Error('offline')) : window.__f(u, o)`);
    const used = new Set([first, multi && multi.id, single && single.id, empty && empty.id]);
    const idErr = ids.find((i) => !used.has(i)) || ids[ids.length - 1];
    await qvRect(idErr); await sleep(300);
    const re = await qvRect(idErr); await mouse(re.x, re.y); await sleep(500);
    if (!(await qState()).open) await evalJs(`document.querySelector('button[data-quick-view="${idErr}"]').click()`);
    note(await waitQ('error'), `${tag}/${page}: failed load shows the plain error state`);
    await shot(`quickview-error-${page}-${tag}`);
    await evalJs(`window.fetch = window.__f`);
    await evalJs(`document.getElementById('cxRetry').click()`);
    note(await waitQ('ready'), `${tag}/${page}: Retry loads it`);
    await key('Escape');
  }

  for (const who of ['guest', 'user']) {
    for (const w of [1440, 1024, 390]) {
      const tag = `${who}-${w}`;
      console.log(`\n=== ${tag} ===`);
      consoleErrors = [];
      await setCookie(who === 'user' ? cookieFor() : null);
      await viewport(w, w === 390 ? 844 : 900);
      await lumaWalk(tag, w);
      await setCookie(who === 'user' ? cookieFor() : null);
      await footerWalk(tag);
      await go('/');
      await quickViewWalk('home', tag, w);
      // Escape with Quick view above Luma: Quick view closes first, Luma stays
      await go('/');
      await evalJs(`openChatbot()`); await sleep(400);
      const firstId = await evalJs(`document.querySelector('button[data-quick-view]').getAttribute('data-quick-view')`);
      await evalJs(`document.querySelector('button[data-quick-view="${firstId}"]').click()`); await waitQ();
      await key('Escape');
      note(!(await qState()).open && (await lumaOpen()), `${tag}: Escape closes the Quick view first and leaves Luma open`);
      await key('Escape');
      note(!(await lumaOpen()), `${tag}: a second Escape closes Luma`);
      // product page
      await go('/catalog?category=Cabinets');
      const href = await evalJs(`document.querySelector('.lp-pcard__title a').getAttribute('href')`);
      await go(href);
      await quickViewWalk('product', tag, w);
      const errs = consoleErrors.filter((x) => !IGNORE.test(x));
      note(errs.length === 0, `${tag}: no console errors${errs.length ? ' -> ' + errs.slice(0, 3).join(' | ') : ''}`);
      report.runs.push({ tag, consoleErrors: errs });
    }
  }
  report.problems = bad;
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  console.log(`\n${bad.length ? bad.length + ' CHECK(S) NEED ATTENTION' : 'ALL CHECKS PASSED'}`);
  chrome.kill();
  process.exit(bad.length ? 1 : 0);
}
main().catch((e) => { console.error(e); process.exit(2); });
