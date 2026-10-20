/*
 * Shared footer browser walk (SCRATCH DATABASE ONLY). Saves screenshots into docs/stage-footer-screenshots/, checks horizontal
 * scroll at 390px, 44px link/button heights, the nav switching to its dark variant over the footer, and measures text contrast
 * (footer text, links, social buttons, the request strip) from real pixels. Writes docs/stage-footer-screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_hero.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-footer.cjs
 *
 * The script sets/clears the footer contact values itself (php tests/UiLinks/footer-settings.php set|blank).
 * Signed-in pages use a session minted by tests/UiLinks/session-cookie-for.php (the login form and reCAPTCHA are not driven).
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-footer-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

// In the page: given a screenshot with all text transparent and the text boxes, the worst-case contrast of each text colour.
const SAMPLE = `(async (dataUrl, items) => {
  const img = new Image(); img.src = dataUrl; await img.decode();
  const cv = document.createElement('canvas'); cv.width = img.width; cv.height = img.height;
  const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0);
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const lum = (r, g, b) => 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
  return items.map((it) => {
    const x = Math.max(0, Math.floor(it.x)), y = Math.max(0, Math.floor(it.y)), w = Math.max(1, Math.min(img.width - x, Math.ceil(it.w))), h = Math.max(1, Math.min(img.height - y, Math.ceil(it.h)));
    const d = cx.getImageData(x, y, w, h).data; const ls = [];
    for (let i = 0; i < d.length; i += 4) ls.push(lum(d[i], d[i + 1], d[i + 2]));
    ls.sort((a, b) => a - b);
    const tl = lum(it.r, it.g, it.b);
    const bg = tl > 0.4 ? ls[Math.floor(ls.length * 0.99)] : ls[Math.floor(ls.length * 0.01)];
    const hi = Math.max(tl, bg), lo = Math.min(tl, bg);
    return { name: it.name, text: it.text, ratio: Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100 };
  });
})`;

async function main() {
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9338', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try { target = (await getJson('http://127.0.0.1:9338/json')).find((t) => t.type === 'page'); } catch (e) { /* not up yet */ }
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

  const go = async (url, wait = 700) => {
    events.length = 0;
    await send('Page.navigate', { url: BASE + url });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(wait);
  };
  const viewport = (w, h = 900) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });
  const shot = async (file) => {
    const r = await send('Page.captureScreenshot', { format: 'jpeg', quality: 82 });
    fs.writeFileSync(path.join(OUT, file), Buffer.from(r.data, 'base64'));
  };
  const toBottom = async () => { await evalJs('window.scrollTo(0, document.documentElement.scrollHeight)'); await sleep(600); };
  const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), ...(email ? [email] : [])], { cwd: ROOT, encoding: 'utf8' }));
  const setCookie = async (c) => { await send('Network.clearBrowserCookies'); if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); };
  const settings = (mode) => execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'footer-settings.php'), mode], { cwd: ROOT, encoding: 'utf8' });

  async function contrast(tag) {
    const items = await evalJs(`(() => {
      const out = []; const w = document.createTreeWalker(document.querySelector('footer.sf'), NodeFilter.SHOW_TEXT); let t;
      while ((t = w.nextNode())) {
        if (!t.textContent.trim()) continue;
        const p = t.parentElement; if (p.closest('svg, style')) continue;
        const rg = document.createRange(); rg.selectNodeContents(t); const b = rg.getBoundingClientRect();
        if (!b.width || b.bottom < 0 || b.top > innerHeight) continue;
        const c = getComputedStyle(p).color.match(/[\\d.]+/g).map(Number);
        out.push({ name: '${tag}', text: t.textContent.trim().slice(0, 34), x: b.left, y: b.top, w: b.width, h: b.height, r: c[0], g: c[1], b: c[2] });
      }
      return out; })()`);
    await evalJs(`(() => { const s = document.createElement('style'); s.id = '__t'; s.textContent = '* { color: transparent !important; text-shadow: none !important; } svg { visibility: hidden !important; }'; document.head.appendChild(s); })()`);
    await sleep(150);
    const r = await send('Page.captureScreenshot', { format: 'png' });
    await evalJs(`document.getElementById('__t').remove()`);
    return evalJs(`(${SAMPLE})('data:image/png;base64,${r.data}', ${JSON.stringify(items)})`);
  }

  const report = { overflow: [], tap: [], nav: [], contrast: [], states: [] };
  const home = [['home', '/'], ['catalog', '/catalog']];

  for (const state of ['socials', 'blank']) {
    settings(state === 'socials' ? 'set' : 'blank');
    for (const signedIn of [false, true]) {
      await setCookie(signedIn ? cookieFor() : null);
      for (const w of [1440, 390]) {
        await viewport(w, w === 390 ? 844 : 900);
        for (const [name, url] of home) {
          await go(url, 900); await toBottom();
          const m = await evalJs(`(() => {
            const f = document.querySelector('footer.sf'); const r = (e) => e.getBoundingClientRect();
            const hs = [...f.querySelectorAll('a')].filter((a) => a.getClientRects().length).map((a) => ({ t: a.innerText.trim().slice(0, 30), h: Math.round(r(a).height) }));
            const nav = document.getElementById('site-nav');
            return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, links: hs, follow: !!f.querySelector('.sf__follow'),
              fb: !!f.querySelector('[aria-label^="LP Furniture on Facebook"]'), tt: !!f.querySelector('[aria-label^="LP Furniture on TikTok"]'), phone: !!f.querySelector('a[href^="tel:"]'), email: !!f.querySelector('a[href^="mailto:"]'),
              navLight: nav ? nav.classList.contains('light') : null, navCollapsed: nav ? nav.classList.contains('collapsed') : null, footerTop: Math.round(r(f).top) }; })()`);
          const who = signedIn ? 'user' : 'guest';
          const tag = `${name}-${state}-${who}-${w}`;
          report.states.push({ tag, ...m });
          report.overflow.push({ tag, overflow: m.overflow });
          const short = m.links.filter((l) => l.h < 44);
          report.tap.push({ tag, min: Math.min(...m.links.map((l) => l.h)), short: short.map((l) => l.t + ':' + l.h) });
          if (!(who === 'guest')) { /* screenshots below only for the guest to keep the set small */ }
          if (!signedIn) await shot(`footer-${name}-${state}-${w}.jpg`);
          console.log((m.overflow > 0 || short.length ? 'CHECK ' : 'ok    ') + tag, `overflow=${m.overflow} minLink=${Math.min(...m.links.map((l) => l.h))} fb=${m.fb} tt=${m.tt} follow=${m.follow} tel=${m.phone} mail=${m.email}`);
          if (!signedIn && state === 'socials' && w === 1440) report.contrast.push(...(await contrast(`${name}-${w}`)));
          if (!signedIn && state === 'socials' && w === 390 && name === 'home') report.contrast.push(...(await contrast(`${name}-${w}`)));
        }
      }
    }
  }

  // nav over the footer: scroll so the footer sits under the bar and check the light class is off (dark variant); then scroll up and check it is on again
  settings('set');
  await setCookie(null); await viewport(1440, 420);
  await go('/catalog', 800);
  await evalJs(`(() => { const f = document.querySelector('footer.sf'); window.scrollTo(0, f.getBoundingClientRect().top + scrollY + 80); })()`); await sleep(900);
  await evalJs('window.scrollBy(0, -10)'); await sleep(800);
  const over = await evalJs(`(() => { const nav = document.getElementById('site-nav'); return { light: nav.classList.contains('light'), collapsed: nav.classList.contains('collapsed') }; })()`);
  await shot('nav-over-footer-1440.jpg');
  await evalJs('window.scrollTo(0, 0)'); await sleep(900);
  const top = await evalJs(`document.getElementById('site-nav').classList.contains('light')`);
  report.nav.push({ overFooterLight: over.light, overFooterCollapsed: over.collapsed, atTopLight: top });
  console.log((over.light === false && top === true ? 'ok    ' : 'CHECK ') + 'nav over footer: light=' + over.light + ' collapsed=' + over.collapsed + ', at top light=' + top);

  // keyboard focus ring on a footer link and a social button
  await go('/catalog', 600); await toBottom();
  await evalJs(`document.querySelector('footer.sf .sf__soc').focus()`);
  const key = async (k, code, vk) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: vk }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk }); };
  await key('Tab', 'Tab', 9); await key('Tab', 'Tab', 9); await sleep(200);
  const ring = await evalJs(`(() => { const a = document.activeElement; const s = getComputedStyle(a); return { el: (a.innerText || '').trim().slice(0, 24), outline: s.outlineStyle + ' ' + s.outlineWidth + ' ' + s.outlineColor }; })()`);
  report.focus = ring;
  console.log('focus rings', JSON.stringify(ring));

  const worst = report.contrast.slice().sort((a, b) => a.ratio - b.ratio).slice(0, 8);
  report.lowest = worst;
  console.log('lowest contrast:', worst.map((c) => `${c.ratio} ${c.name} "${c.text}"`).join(' | '));
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  settings('set');
  chrome.kill();
  process.exit(0);
}
main().catch((e) => { console.error(e); process.exit(1); });
