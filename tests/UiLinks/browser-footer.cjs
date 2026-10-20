/*
 * Shared footer (v11 Ember Glass) browser walk (SCRATCH DATABASE ONLY). Saves screenshots into docs/stage-footer-screenshots/, checks horizontal
 * scroll at 390px, 44px link/button heights, the nav switching to its dark variant over the footer, and measures text contrast
 * (footer text over the glow, pay tiles, links, buttons), keyboard reach, console errors and the footer being attached to the bottom from real pixels. Writes docs/stage-footer-screenshots/_checks.json.
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
  const errors = [];
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { const { res, rej } = pending.get(d.id); pending.delete(d.id); d.error ? rej(new Error(JSON.stringify(d.error))) : res(d.result); } else if (d.method) {
      events.push(d.method);
      if (d.method === 'Runtime.exceptionThrown') errors.push('exception ' + JSON.stringify(d.params.exceptionDetails.text));
      if (d.method === 'Log.entryAdded' && d.params.entry.level === 'error') errors.push((d.params.entry.text || '') + ' ' + (d.params.entry.url || ''));
      if (d.method === 'Runtime.consoleAPICalled' && d.params.type === 'error') errors.push('console.error ' + JSON.stringify(d.params.args.map((a) => a.value || a.description)));
    }
  };
  const send = (method, params = {}) => new Promise((res, rej) => { const id = ++n; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('JS error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  };
  await send('Page.enable'); await send('Runtime.enable'); await send('Network.enable'); await send('Log.enable');

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
  const toBottom = async () => { await evalJs(`document.documentElement.style.scrollBehavior = 'auto'`); await evalJs('window.scrollTo(0, document.documentElement.scrollHeight)'); await sleep(600); };
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

  const report = { overflow: [], tap: [], attached: [], keyboard: [], consoleErrors: [], contrast: [], buttons: null, nav: [], focus: null, states: [] };
  const pages = [['home', '/'], ['catalog', '/catalog'], ['reviews', '/reviews']];

  for (const state of ['full', 'bare']) {
    settings(state === 'full' ? 'set' : 'blank');
    for (const signedIn of [false, true]) {
      await setCookie(signedIn ? cookieFor() : null);
      for (const w of [1440, 1024, 390]) {
        await viewport(w, w === 390 ? 844 : 900);
        for (const [name, url] of pages) {
          errors.length = 0;
          await go(url, 900);
          const short = await evalJs(`document.documentElement.scrollHeight <= innerHeight + 1`);
          const m0 = await evalJs(`(() => { const f = document.querySelector('footer.sf'); const r = f.getBoundingClientRect(); return { short: document.documentElement.scrollHeight <= innerHeight + 1, bottomAtTop: Math.round(r.bottom), vh: innerHeight }; })()`);
          if (!signedIn && state === 'full' && short) await shot(`short-${name}-${w}-top.jpg`);
          await toBottom();
          const m = await evalJs(`(() => {
            const f = document.querySelector('footer.sf'); const r = (e) => e.getBoundingClientRect();
            const links = [...f.querySelectorAll('a')].filter((a) => a.getClientRects().length).map((a) => ({ t: (a.innerText || a.getAttribute('aria-label')).trim().slice(0, 30), h: Math.round(r(a).height), href: a.getAttribute('href') }));
            const fr = r(f);
            return { overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, links, attached: Math.abs(fr.bottom + scrollY - document.documentElement.scrollHeight) < 2,
              fb: !!f.querySelector('[aria-label^="Facebook"]'), tt: !!f.querySelector('[aria-label^="TikTok"]'), tel: !!f.querySelector('a[href^="tel:"]'), mail: !!f.querySelector('a[href^="mailto:"]'),
              iframe: !!f.querySelector('iframe[title]'), deadHash: links.some((l) => l.href === '#'), policies: [...f.querySelectorAll('.sf__pol a')].map((a) => a.innerText.trim()),
              wordOverflow: (() => { const w = f.querySelector('.sf__word'); return w.scrollWidth - w.clientWidth; })() }; })()`);
          const who = signedIn ? 'user' : 'guest';
          const tag = `${name}-${state}-${who}-${w}`;
          const minLink = Math.min(...m.links.map((l) => l.h));
          const bad = m.overflow > 0 || minLink < 44 || !m.attached || m.deadHash || (short && Math.abs(m0.bottomAtTop - m0.vh) > 2);
          report.overflow.push({ tag, overflow: m.overflow });
          report.tap.push({ tag, min: minLink });
          report.attached.push({ tag, attached: m.attached, shortPage: short, bottomAtTop: m0.bottomAtTop, viewport: m0.vh });
          report.consoleErrors.push(...errors.map((e) => ({ tag, e })));
          report.states.push({ tag, fb: m.fb, tt: m.tt, tel: m.tel, mail: m.mail, iframe: m.iframe, policies: m.policies });
          console.log((bad ? 'CHECK ' : 'ok    ') + tag, `overflow=${m.overflow} minLink=${minLink} attached=${m.attached} short=${short} fb=${m.fb} tt=${m.tt} tel=${m.tel} mail=${m.mail} map=${m.iframe} errs=${errors.length}`);
          if (!signedIn) await shot(`footer-${name}-${state}-${w}.jpg`);
        }
      }
    }
  }

  // ---- a short page: in a tall window the Other Services terms page does not fill the screen, so the footer must still sit on the bottom edge ----
  settings('set'); await setCookie(null);
  for (const [w, h] of [[1440, 2400], [1024, 2400], [390, 3400]]) {
    await viewport(w, h); await go('/services/terms', 900);
    const sp = await evalJs(`(() => { const f = document.querySelector('footer.sf').getBoundingClientRect(); return { sh: document.documentElement.scrollHeight, vh: innerHeight, bottom: Math.round(f.bottom), top: Math.round(f.top), overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()`);
    const okShort = sp.sh <= sp.vh + 1 && Math.abs(sp.bottom - sp.vh) <= 1;
    report.attached.push({ tag: `short-terms-${w}`, ...sp, ok: okShort });
    console.log((okShort && sp.overflow === 0 ? 'ok    ' : 'CHECK ') + `short page ${w}x${h}: scrollHeight=${sp.sh} footer bottom=${sp.bottom} (viewport ${sp.vh}) footer top=${sp.top} overflow=${sp.overflow}`);
    await shot(`short-terms-${w}.jpg`);
  }

  // ---- contrast from real pixels (full state, guest): scroll the footer through the viewport in steps ----
  settings('set'); await setCookie(null);
  for (const w of [1440, 390]) {
    await viewport(w, w === 390 ? 844 : 900);
    await go('/catalog', 900);
    const total = await evalJs(`(() => { const f = document.querySelector('footer.sf'); return { top: f.getBoundingClientRect().top + scrollY, h: f.offsetHeight, vh: innerHeight, sh: document.documentElement.scrollHeight }; })()`);
    const seen = new Set();
    for (let y = total.sh - total.vh; y > total.top - total.vh; y -= Math.floor(total.vh * 0.6)) {
      await evalJs(`document.documentElement.style.scrollBehavior = 'auto'; window.scrollTo(0, ${Math.max(0, y)})`); await sleep(500);
      const items = await evalJs(`(() => {
        const out = []; const w = document.createTreeWalker(document.querySelector('footer.sf'), NodeFilter.SHOW_TEXT); let t;
        while ((t = w.nextNode())) {
          if (!t.textContent.trim()) continue;
          const p = t.parentElement; if (p.closest('svg, style, .sf__word')) continue;
          const rg = document.createRange(); rg.selectNodeContents(t); const b = rg.getBoundingClientRect();
          if (!b.width || b.bottom > innerHeight || b.top < 90) continue; /* text under the sticky nav or the Luma launcher would measure those, not the footer */
          const fab = document.getElementById('chatbot-fab').getBoundingClientRect(); if (b.bottom > innerHeight - 110 && b.right > fab.left - 130) continue; /* launcher plus its Ask Luma tip */
          const c = getComputedStyle(p).color.match(/[\\d.]+/g).map(Number);
          out.push({ name: 'footer-${w}', text: t.textContent.trim().slice(0, 34), x: b.left, y: b.top, w: b.width, h: b.height, r: c[0], g: c[1], b: c[2] });
        }
        return out; })()`);
      // the hover colour of the policy links and the map link is white (#fff): measure it over the same pixels
      for (const it of items.slice()) if (/^(Terms|Privacy|Refund|Payment|Delivery|Warranty|Other Services Terms|Custom Design|Open in)/.test(it.text)) items.push({ ...it, name: it.name + '-hover', r: 255, g: 255, b: 255 });
      const fresh = items.filter((i) => !seen.has(i.text + i.y.toFixed(0) + y)); items.forEach((i) => seen.add(i.text + i.y.toFixed(0) + y));
      if (!fresh.length) continue;
      await evalJs(`(() => { const s = document.createElement('style'); s.id = '__t'; s.textContent = '.sf *, .sf a, .sf b, .sf span, .sf small, .sf address { color: transparent !important; text-shadow: none !important; } .sf svg { visibility: hidden !important; } .sf__pay li img { visibility: hidden !important; }'; document.head.appendChild(s); })()`);
      await sleep(150);
      const r = await send('Page.captureScreenshot', { format: 'png' });
      await evalJs(`document.getElementById('__t').remove()`);
      report.contrast.push(...(await evalJs(`(${SAMPLE})('data:image/png;base64,${r.data}', ${JSON.stringify(fresh)})`)));
    }
  }
  // the pay tiles' name text is dark on a white tile (measured by the same pixel method): included above via .sf__pay li span

  // ---- shared button audit (tests/ButtonContrast/measure.js) over the footer's pill buttons ----
  await viewport(1440, 900); await go('/catalog', 600); await toBottom();
  const bcr = await evalJs(fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8').trim().replace(/;?$/, ''));
  report.buttons = { footerRows: (bcr.rows || []).filter((r) => /Facebook|TikTok|Open in Google/.test(r.text)), failuresAll: (bcr.failures || []).map((r) => r.text + ' ' + r.min) };
  console.log('measure.js footer rows:', JSON.stringify(report.buttons.footerRows.map((r) => ({ t: r.text, min: r.min, h: r.height }))));

  // ---- keyboard: Tab through the whole page, every footer link must receive focus, with a visible ring ----
  for (const w of [1440, 390]) {
    await viewport(w, 844); await go('/catalog', 600);
    const expect = await evalJs(`[...document.querySelectorAll('footer.sf a')].filter((a) => a.getClientRects().length).map((a) => a.getAttribute('href'))`);
    const got = new Set(); const rings = [];
    await evalJs(`document.body.focus(); window.scrollTo(0, 0)`);
    for (let i = 0; i < 160; i++) {
      await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
      const a = await evalJs(`(() => { const e = document.activeElement; if (!e || !e.closest || !e.closest('footer.sf') || e.tagName !== 'A') return null; const s = getComputedStyle(e); return { href: e.getAttribute('href'), ring: s.outlineStyle + ' ' + s.outlineWidth + ' ' + s.outlineColor }; })()`);
      if (a) { got.add(a.href); if (!a.ring.startsWith('solid 3px')) rings.push(a); }
    }
    const missing = expect.filter((h) => !got.has(h));
    report.keyboard.push({ w, expected: expect.length, reached: got.size, missing, badRings: rings.length });
    console.log((missing.length || rings.length ? 'CHECK ' : 'ok    ') + `keyboard ${w}: reached ${got.size}/${new Set(expect).size}, missing ${JSON.stringify(missing)}, rings not 3px solid: ${rings.length}`);
  }

  // ---- nav over the footer (dark variant) ----
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

  const worst = report.contrast.slice().sort((a, b) => a.ratio - b.ratio).slice(0, 10);
  report.lowest = worst;
  console.log('lowest contrast:', worst.map((c) => `${c.ratio} ${c.name} "${c.text}"`).join(' | '));
  console.log('console errors:', report.consoleErrors.length, JSON.stringify(report.consoleErrors.slice(0, 5)));
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  settings('set');
  chrome.kill();
  process.exit(0);
}
main().catch((e) => { console.error(e); process.exit(1); });
