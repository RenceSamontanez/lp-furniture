/*
 * Luma new look (Prompt 9a): drives the real widget in headless Chrome against the SCRATCH app (http://127.0.0.1:8011, started by
 * tests/Rbac/serve_test.ps1, which refuses non-_test databases) and writes proof into docs/stage9a-screenshots/.
 *
 *   node --experimental-websocket tests/Luma/browser-9a.cjs            all scenes
 *   node --experimental-websocket tests/Luma/browser-9a.cjs faces      only the 2x face zooms
 *
 * Signed-in screens use a session minted by tests/Luma/session-cookie.php (scratch database only); the real sign-in form has
 * reCAPTCHA, which is not bypassed. The after-hours scenes fake the browser clock (Date) before the page loads: no server hook,
 * no database write. Every scene also runs the shared contrast audit (tests/ButtonContrast/measure.js) and a text-contrast sweep
 * of the whole widget (every visible text node against its real painted background).
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = process.env.OUT || path.join(ROOT, 'docs', 'stage9a-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const only = process.argv[2] || 'all';
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

// WCAG contrast of every visible text node in the widget against the colour actually painted behind it (alpha blended up the tree)
const TEXT_SWEEP = `(() => {
  const parse = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(/[ ,\\/]+/).filter(Boolean).map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const over = (top, bottom) => ({ r: top.r * top.a + bottom.r * (1 - top.a), g: top.g * top.a + bottom.g * (1 - top.a), b: top.b * top.a + bottom.b * (1 - top.a), a: 1 });
  const gradientWorst = (e, fg) => { const bi = getComputedStyle(e).backgroundImage; if (!bi || bi.indexOf('gradient') < 0) return null; const cols = (bi.match(/rgba?\\([^)]*\\)/g) || []).map(parse).filter((c) => c && c.a >= 1); if (!cols.length) return null; const cr = (c) => { const a = lum(fg) + 0.05, b = lum(c) + 0.05; return Math.max(a, b) / Math.min(a, b); }; return cols.sort((x, y) => cr(x) - cr(y))[0]; };   // worst case: the solid gradient stop with the least contrast against the text colour
  const bgOf = (el, fg) => { const stack = []; for (let e = el; e; e = e.parentElement) { const g = gradientWorst(e, fg); if (g) { stack.push(g); break; } const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; } } let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = stack.length - 1; i >= 0; i--) base = over(stack[i], base); return base; };
  const root = document.getElementById('chatbot-window');
  const out = []; const seen = new Set();
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
  while (walker.nextNode()) {
    const n = walker.currentNode; if (!n.textContent.trim()) continue;
    const el = n.parentElement; if (!el || seen.has(el) || !el.getClientRects().length) continue;
    const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.display === 'none' || parseFloat(cs.opacity) === 0) continue;
    seen.add(el);
    const fg = parse(cs.color); const bg = bgOf(el, fg); const fgb = over(fg, bg);
    const a = lum(fgb) + 0.05, b = lum(bg) + 0.05; const ratio = Math.max(a, b) / Math.min(a, b);
    const big = parseFloat(cs.fontSize) >= 24 || (parseFloat(cs.fontSize) >= 18.66 && parseInt(cs.fontWeight, 10) >= 700);
    out.push({ t: n.textContent.trim().slice(0, 40), cls: (el.className && el.className.baseVal === undefined ? el.className : '').toString().slice(0, 40), ratio: Math.round(ratio * 100) / 100, need: big ? 3 : 4.5 });
  }
  return { count: out.length, min: Math.min(...out.map((x) => x.ratio)), fails: out.filter((x) => x.ratio < x.need) };
})()`;

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
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable');

  let clockScript = null;
  const fakeClock = async (iso) => {   // iso like '2026-10-20T21:30:00+08:00'; null restores the real clock
    if (clockScript) { await send('Page.removeScriptToEvaluateOnNewDocument', { identifier: clockScript }); clockScript = null; }
    if (!iso) return;
    const src = `(() => { const RealDate = Date; const offset = new RealDate(${JSON.stringify(iso)}).getTime() - RealDate.now();
      class FakeDate extends RealDate { constructor(...a) { if (a.length === 0) super(RealDate.now() + offset); else super(...a); } static now() { return RealDate.now() + offset; } }
      window.Date = FakeDate; })();`;
    clockScript = (await send('Page.addScriptToEvaluateOnNewDocument', { source: src })).identifier;
  };
  const scheme = (s) => send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: s }] });
  const setVp = (w, h, dpr = 1) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: dpr, mobile: w < 500 });
  const go = async (url) => {
    events.length = 0;
    await send('Page.navigate', { url: BASE + url });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(700);
  };
  const open = () => evalJs(`(() => { if (!document.getElementById('chatbot-window').classList.contains('open')) toggleChatbot(); })()`);
  const say = async (msg, wait = 900) => {
    await evalJs(`(async () => { if (!document.getElementById('chatbot-window').classList.contains('open')) { openChatbot(); } const i = document.getElementById('chat-input'); i.value = ${JSON.stringify(msg)}; await sendChat(); })()`);
    await sleep(wait);
  };
  const newCookie = () => JSON.parse(execFileSync('php', [path.join(__dirname, 'session-cookie.php')], { cwd: ROOT, encoding: 'utf8' }));
  const signIn = async () => { const c = newCookie(); await send('Network.setCookie', { name: c.name, value: c.value, domain: '127.0.0.1', path: '/', httpOnly: true }); };
  const clip = async (selector, file, pad = 4) => {
    const r = await evalJs(`(() => { const b = document.querySelector(${JSON.stringify(selector)}).getBoundingClientRect(); return { x: b.x, y: b.y, w: b.width, h: b.height }; })()`);
    const shot = await send('Page.captureScreenshot', { format: 'png', clip: { x: Math.max(0, r.x - pad), y: Math.max(0, r.y - pad), width: r.w + pad * 2, height: r.h + pad * 2, scale: 1 } });
    fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
  };
  const results = [];
  const snap = async (name, vp, scheme_ = 'light') => {
    const buttons = await evalJs(BUTTONS);
    const text = await evalJs(TEXT_SWEEP);
    const layout = await evalJs(`(() => { const w = document.getElementById('chatbot-window'); const b = document.getElementById('chat-body'); const r = w.getBoundingClientRect(); const vw = document.documentElement.clientWidth;
      const short = [...w.querySelectorAll('button, a.lp-btn, a.chat-btn, select, textarea, input[type=text], input[type=tel], input[type=email]')].filter((e) => e.getClientRects().length && !e.closest('[hidden]')).map((e) => ({ t: (e.innerText || e.getAttribute('aria-label') || '').trim().slice(0, 28), h: Math.round(e.getBoundingClientRect().height), w: Math.round(e.getBoundingClientRect().width), chip: e.classList.contains('chat-chip') })).filter((x) => (x.h < 44 || x.w < 44) && !x.chip);
      return { pageOverflow: document.documentElement.scrollWidth - vw, chatOverflow: b.scrollWidth - b.clientWidth, inside: r.left >= 0 && r.right <= vw + 0.5, short }; })()`);
    const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82 });
    const file = `${name}-${vp}${scheme_ === 'dark' ? '-dark' : ''}.jpg`;
    fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
    const row = { file, buttons: buttons.count, buttonFailures: buttons.failures.filter((f) => !/Customize Furniture|Contact Us|Shop Now/.test(f.text) && !/btn-view-details/.test(f.cls)).map((f) => ({ text: f.text, cls: f.cls, min: f.min, def: f.default, hov: f.hover, foc: f.focus, act: f.active })), textNodes: text.count, minTextContrast: text.min, textFails: text.fails, ...layout };
    results.push(row);
    const bad = row.buttonFailures.length || row.textFails.length || row.pageOverflow > 0 || row.chatOverflow > 0 || !row.inside || row.short.length;
    console.log((bad ? 'CHECK ' : 'ok    ') + file, bad ? JSON.stringify({ b: row.buttonFailures, t: row.textFails, po: row.pageOverflow, co: row.chatOverflow, in: row.inside, s: row.short }) : `(text min ${row.minTextContrast}, ${row.buttons} buttons)`);
  };

  const guestStart = async (vp, h, sch) => { await send('Network.clearBrowserCookies'); await scheme(sch); await setVp(vp, h); await evalJs('0'); };
  for (const sch of ['light', 'dark']) {
    for (const vp of [390, 1280]) {
      if (only !== 'all' && only !== 'faces') continue;
      const h = vp === 390 ? 844 : 900;
      await scheme(sch); await setVp(vp, h, 2);
      await send('Network.clearBrowserCookies'); await go('/');
      await evalJs(`try { localStorage.clear(); } catch (e) {}`);
      await go('/');
      await evalJs(`document.getElementById('luma-fab-tip').classList.remove('show')`);
      await clip('#chatbot-fab .luma-ring', `zoom-launcher-face-${vp}-${sch}-2x.png`, 14);
      await open(); await sleep(400);
      await clip('.luma-ring--head', `zoom-header-face-${vp}-${sch}-2x.png`, 10);
      await clip('#luma-stage', `zoom-stage-platform-${vp}-${sch}-2x.png`, 0);
    }
  }
  if (only === 'faces') { fs.writeFileSync(path.join(OUT, '_faces-done'), ''); ws.close(); chrome.kill(); return; }

  for (const sch of (process.env.SCHEMES || 'light,dark').split(',')) {
    for (const vp of (process.env.VPS || '390,1280').split(',').map(Number)) {
      const h = vp === 390 ? 844 : 900;
      const tag = sch === 'dark' ? 'dark' : 'light';
      const full = sch === 'light';   // the full set is saved in light; dark gets the key screens
      await scheme(sch); await setVp(vp, h, 1);
      await send('Network.clearBrowserCookies');
      await fakeClock(null);

      // launcher with the first-visit label, then without
      await go('/'); await evalJs(`try { localStorage.clear(); } catch (e) {}`); await go('/');
      await sleep(1500);
      if (full) { const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82 }); fs.writeFileSync(path.join(OUT, `launcher-with-label-${vp}.jpg`), Buffer.from(shot.data, 'base64')); }
      await sleep(6500);
      if (full) { const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82 }); fs.writeFileSync(path.join(OUT, `launcher-without-label-${vp}.jpg`), Buffer.from(shot.data, 'base64')); }

      // greeting (inside her first 2 seconds), then normal answer, card reply, clarifying question, fallback, collapsed stage
      await fakeClock('2026-10-20T11:00:00+08:00');   // a Tuesday, 11:00 in Manila: the shop is open
      await go('/'); await open(); await sleep(300);
      await snap('panel-greeting', vp, sch);
      await say('how long does it take');           await snap('answer-normal', vp, sch);
      await say('do you have chairs', 1200);                       await snap('answer-with-cards', vp, sch);
      await send('Network.clearBrowserCookies');
      await go('/'); await say('refund', 1200);     await snap('clarifying-question', vp, sch);
      await go('/'); await say('qwerty zzz asdf', 1200); await snap('answer-fallback', vp, sch);
      await say('what are your delivery areas', 900); await snap('conversation-collapsed-stage', vp, sch);

      // refund form card (signed in)
      await send('Network.clearBrowserCookies'); await signIn(); await go('/');
      await say('I want a refund for my repair', 1200);
      await snap('refund-pick-request', vp, sch);
      await say(vp === 390 ? 'SI-2026-9001' : 'SI-2026-9013', 1200);
      await evalJs(`(() => { const f = document.querySelector('.chat-form'); if (f) f.scrollIntoView(); })()`);
      await snap('refund-form-card', vp, sch);
      await evalJs(`(() => { const b = document.querySelector('.chat-form button[type=submit]'); if (b) b.click(); })()`); await sleep(600);
      await snap('refund-form-errors', vp, sch);

      // after hours: Tuesday 21:30 Manila, Saturday noon, both in the same session shape
      await send('Network.clearBrowserCookies');
      await fakeClock('2026-10-20T21:30:00+08:00');
      await go('/'); await open(); await sleep(500);
      await snap('after-hours-notice', vp, sch);
      await fakeClock('2026-10-24T12:00:00+08:00');
      await go('/'); await open(); await sleep(500);
      if (full) await snap('after-hours-weekend', vp, sch);
      await fakeClock(null);

      // after she is dismissed: the nav item
      await send('Network.clearBrowserCookies'); await signIn(); await go('/');
      await evalJs(`document.getElementById('luma-fab-x').click()`); await sleep(1600);
      if (vp === 390) await evalJs(`toggleMobileNav()`);
      await sleep(400);
      const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82 });
      fs.writeFileSync(path.join(OUT, `nav-assistant-item-${vp}${sch === 'dark' ? '-dark' : ''}.jpg`), Buffer.from(shot.data, 'base64'));
      const nav = await evalJs(`(() => { const b = document.getElementById('nav-chatbot-reactivate'); const r = b.getBoundingClientRect(); const cs = getComputedStyle(b); const after = getComputedStyle(b, '::after');
        return { label: after.content, visible: r.width > 0, h: Math.round(r.height), w: Math.round(r.width), color: cs.color, aria: b.getAttribute('aria-label') }; })()`);
      console.log('nav Assistant item', vp, sch, JSON.stringify(nav));
      results.push({ file: `nav-${vp}-${sch}`, nav });
    }
  }
  // ── interaction states: hover, keyboard focus, disabled, each checked by the same audits and saved as a crop of the panel ──
  for (const sch of (process.env.SCHEMES || 'light,dark').split(',')) {
    const vp = 1280;
    await scheme(sch); await setVp(vp, 900, 1);
    await send('Network.clearBrowserCookies'); await fakeClock('2026-10-20T11:00:00+08:00');
    await go('/'); await say('do you have chairs', 1300);
    const centre = (sel) => evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); e.scrollIntoView({ block: 'nearest' }); const b = e.getBoundingClientRect(); return { x: b.x + b.width / 2, y: b.y + b.height / 2 }; })()`);
    const hover = async (sel) => { const c = await centre(sel); await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: c.x, y: c.y }); await sleep(350); };
    const unhover = async () => { await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 5 }); await sleep(250); };
    const focusKey = async (sel) => { await evalJs(`document.querySelector(${JSON.stringify(sel)}).blur(); 0`); await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Shift', code: 'ShiftLeft' }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Shift', code: 'ShiftLeft' }); await evalJs(`document.querySelector(${JSON.stringify(sel)}).focus(); 0`); await sleep(250); };
    const state = async (name, sel) => {
      const text = await evalJs(TEXT_SWEEP);
      const buttons = await evalJs(BUTTONS);
      const fails = buttons.failures.filter((f) => !/Customize Furniture|Contact Us|Shop Now/.test(f.text) && !/btn-view-details/.test(f.cls));
      await clip('#chatbot-window', `state-${name}-${vp}${sch === 'dark' ? '-dark' : ''}.png`, 14);
      const ring = sel ? await evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); const cs = getComputedStyle(e); return { outline: cs.outlineStyle + ' ' + cs.outlineWidth + ' ' + cs.outlineColor, fvMatches: e.matches(':focus-visible'), hover: e.matches(':hover') }; })()`) : null;
      const bad = text.fails.length || fails.length;
      console.log((bad ? 'CHECK ' : 'ok    ') + `state-${name}-${vp}-${sch}`, JSON.stringify({ textMin: text.min, textFails: text.fails, buttonFails: fails.map((f) => f.text), ring }));
      results.push({ file: `state-${name}-${vp}-${sch}`, textMin: text.min, textFails: text.fails, buttonFails: fails.map((f) => f.text), ring });
    };
    await hover('.chat-buttons .lp-btn--primary');          await state('hover-primary-button', '.chat-buttons .lp-btn--primary'); await unhover();
    await hover('.chat-suggestions .chat-chip');            await state('hover-quick-reply-chip', '.chat-suggestions .chat-chip'); await unhover();
    await hover('.chat-send');                              await state('hover-send-button', '.chat-send'); await unhover();
    await focusKey('.chat-suggestions .chat-chip');         await state('focus-quick-reply-chip', '.chat-suggestions .chat-chip');
    await focusKey('.chat-buttons .lp-btn--primary');       await state('focus-primary-button', '.chat-buttons .lp-btn--primary');
    await focusKey('.chat-input');                          await state('focus-text-input', '.chat-input');
    await focusKey('.chat-close');                          await state('focus-close-button', '.chat-close');
    await evalJs(`(() => { const b = document.querySelector('.chat-buttons .lp-btn--primary'); b.setAttribute('aria-disabled', 'true'); b.blur(); })()`); await sleep(250);
    await state('disabled-primary-button', null);
    await evalJs(`document.querySelector('.chat-buttons .lp-btn--primary').removeAttribute('aria-disabled')`);
    await fakeClock(null);
  }
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(results, null, 2));
  ws.close();
  chrome.kill();
}

main().catch((e) => { console.error(e); process.exit(1); });
