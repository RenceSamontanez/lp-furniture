/*
 * Landing hero browser walk (SCRATCH DATABASE ONLY). Saves screenshots into docs/stage-hero-screenshots/, checks overflow and the
 * hamburger at every width, measures text contrast over EACH rotating hero photo from real pixels, checks the rails (seamless loop,
 * pause on hover and focus, reduced motion) and Luma's launcher against the rails. Writes docs/stage-hero-screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_hero.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-hero.cjs
 *
 * The signed-in part uses a session minted by tests/UiLinks/session-cookie-for.php (customer sign-in has reCAPTCHA, which is not bypassed).
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-hero-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

// Runs in the page: given a screenshot (data URL) of the page with ALL text made transparent, and the list of text boxes, returns the
// contrast of each text colour against the worst painted pixel behind it (lightest for light text, darkest for dark text).
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
    const lightText = tl > 0.4;
    const bg = lightText ? ls[Math.floor(ls.length * 0.99)] : ls[Math.floor(ls.length * 0.01)];
    const hi = Math.max(tl, bg), lo = Math.min(tl, bg);
    return { name: it.name, text: it.text, ratio: Math.round(((hi + 0.05) / (lo + 0.05)) * 100) / 100 };
  });
})`;

async function main() {
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9337', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try { target = (await getJson('http://127.0.0.1:9337/json')).find((t) => t.type === 'page'); } catch (e) { /* not up yet */ }
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

  const go = async (url, wait = 900) => {
    events.length = 0;
    await send('Page.navigate', { url: BASE + url });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(wait);
  };
  const viewport = (w, h = 900) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });
  const shot = async (file, opts = {}) => {
    const full = opts.full;
    let params = { format: 'jpeg', quality: 80 };
    if (full) {
      const m = await send('Page.getLayoutMetrics');
      params = { ...params, captureBeyondViewport: true, clip: { x: 0, y: 0, width: m.cssContentSize.width, height: Math.min(m.cssContentSize.height, opts.max || 1400), scale: 1 } };
    }
    const r = await send('Page.captureScreenshot', params);
    fs.writeFileSync(path.join(OUT, file), Buffer.from(r.data, 'base64'));
  };
  const cookieFor = (email) => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), ...(email ? [email] : [])], { cwd: ROOT, encoding: 'utf8' }));
  const setCookie = async (c) => { await send('Network.clearBrowserCookies'); if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); };

  const report = { widths: [], hamburger: [], contrast: [], rails: {}, luma: [], buttons: [] };
  const flat = (o) => JSON.stringify(o);

  // ---------- 1. every width: screenshots, overflow, burger, button wrapping, text column, Luma ----------
  for (const signedIn of [false, true]) {
    await setCookie(signedIn ? cookieFor() : null);
    for (const w of [1836, 1440, 1366, 1100, 900, 390]) {
      await viewport(w, w === 390 ? 844 : 900);
      await go('/', 1400);
      const m = await evalJs(`(() => {
        const r = (e) => { const b = e.getBoundingClientRect(); return { l: Math.round(b.left), t: Math.round(b.top), r: Math.round(b.right), b: Math.round(b.bottom), w: Math.round(b.width), h: Math.round(b.height) }; };
        const q = (s) => document.querySelector(s);
        const burger = q('#sn-burger'); const bs = getComputedStyle(burger);
        const ctas = [...document.querySelectorAll('.lp-hero__cta')].map((e) => ({ t: e.innerText.trim(), h: Math.round(e.getBoundingClientRect().height), lines: (() => { const rg = document.createRange(); const tn = [...e.childNodes].find((n) => n.nodeType === 3 && n.textContent.trim()); rg.selectNodeContents(tn); return rg.getClientRects().length; })() }));
        const fab = r(q('#chatbot-fab'));
        const hit = (box) => { const cx = (box.l + box.r) / 2, cy = (box.t + box.b) / 2; const el = document.elementFromPoint(cx, cy); return el ? (el.closest('#chatbot-fab') ? 'LUMA' : (el.closest('a') ? el.closest('a').innerText.trim().slice(0, 20) : el.tagName)) : null; };
        // hit-test a grid of points over the launcher and its "Ask Luma" label: any card or AI link under them (clipped parts do not count) is an overlap
        const under = (box) => { const hits = new Set(); for (let x = box.l + 2; x < box.r; x += 8) for (let y = box.t + 2; y < box.b; y += 8) { for (const e of document.elementsFromPoint(x, y)) { const a = e.closest && e.closest('.lp-pc, .lp-ai__link, .lp-ai__tile'); if (a) hits.add((a.innerText || a.className).trim().slice(0, 18)); } } return [...hits]; };
        const tip = r(q('#luma-fab-tip'));
        const overlapLuma = under(fab); const overlapTip = under(tip);
        return { vw: document.documentElement.clientWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          burger: { display: bs.display, w: Math.round(burger.getBoundingClientRect().width) }, ctas, copy: q('.lp-hero__copy') ? r(q('.lp-hero__copy')) : null,
          heroH: r(q('.lp-hero')).h, navH: r(q('nav.site-nav')).h, ai: q('.lp-ai').getClientRects().length > 0, rails: document.querySelectorAll('.lp-rail').length && q('.lp-rail').getClientRects().length > 0,
          overlapLuma, overlapTip, fab };
      })()`);
      const row = { signedIn, w, ...m };
      report.widths.push(row);
      const problems = [];
      if (m.overflow > 0) problems.push('overflow ' + m.overflow);
      if (w > 860 && (m.burger.display !== 'none' || m.burger.w > 0)) problems.push('burger visible above 860');
      if (w <= 860 && m.burger.display === 'none') problems.push('burger missing at <=860');
      if (w >= 1100 && m.ctas.some((c) => c.lines > 1)) problems.push('button label wraps');
      if (w >= 1100 && m.copy && m.copy.w < 440) problems.push('text column ' + m.copy.w);
      if (w >= 1100 && (!m.ai || !m.rails)) problems.push('desktop zones missing');
      if (w < 1100 && (m.ai || m.rails)) problems.push('desktop zones visible below 1100');
      if (m.overlapLuma.length || m.overlapTip.length) problems.push('Luma overlaps ' + [...m.overlapLuma, ...m.overlapTip].join(','));
      console.log((problems.length ? 'CHECK ' : 'ok    ') + (signedIn ? 'user ' : 'guest ') + w, problems.join('; '));
      await shot(`home-${signedIn ? 'signed-in' : 'guest'}-${w}.jpg`);
    }
  }

  // ---------- 2. hamburger at 390 and 860 (guest, signed in, signed in with Luma hidden) ----------
  for (const mode of ['guest', 'signed-in', 'signed-in-assistant']) {
    await viewport(390, 844);
    await setCookie(mode === 'guest' ? null : cookieFor());
    await go('/', 600);
    await evalJs(`try { localStorage.setItem('lp_chat_dismissed', '${mode.endsWith('assistant') ? 'true' : 'false'}'); } catch (e) {}`);
    await go('/', 800);
    const before = await evalJs(`(() => { const b = document.getElementById('sn-burger'); return { exp: b.getAttribute('aria-expanded'), label: b.getAttribute('aria-label'), panelVisible: getComputedStyle(document.getElementById('sn-links')).display !== 'none' }; })()`);
    await shot(`menu-${mode}-closed-390.jpg`);
    await evalJs(`document.getElementById('sn-burger').click()`);
    await sleep(500);
    const open = await evalJs(`(() => { const b = document.getElementById('sn-burger'); const rows = [...document.querySelectorAll('#sn-links > *')].filter((e) => e.getClientRects().length).map((e) => ({ t: (e.innerText || '').trim().replace(/\\s+/g, ' '), h: Math.round(e.getBoundingClientRect().height) }));
      return { exp: b.getAttribute('aria-expanded'), label: b.getAttribute('aria-label'), rows, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth, burgerSize: Math.round(b.getBoundingClientRect().width) + 'x' + Math.round(b.getBoundingClientRect().height) }; })()`);
    await shot(`menu-${mode}-open-390.jpg`);
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
    await sleep(300);
    const after = await evalJs(`({ exp: document.getElementById('sn-burger').getAttribute('aria-expanded'), focusOnBurger: document.activeElement && document.activeElement.id === 'sn-burger', label: document.getElementById('sn-burger').getAttribute('aria-label') })`);
    report.hamburger.push({ mode, before, open, after });
    const bad = before.exp !== 'false' || open.exp !== 'true' || open.rows.some((r) => r.h < 48) || after.exp !== 'false' || !after.focusOnBurger || open.overflow > 0;
    console.log((bad ? 'CHECK ' : 'ok    ') + 'hamburger ' + mode, open.rows.map((r) => r.t).join(' | '));
  }
  await evalJs(`try { localStorage.removeItem('lp_chat_dismissed'); } catch (e) {}`);
  for (const w of [860, 861]) {
    await viewport(w, 800); await go('/', 500);
    report.hamburger.push({ w, burgerDisplay: await evalJs(`getComputedStyle(document.getElementById('sn-burger')).display`) });
  }

  // ---------- 3. glass nav over light content (catalog, product, cart, services), scrolled so content passes under the bar ----------
  await viewport(1440, 900);
  await setCookie(cookieFor());
  const pid = (await evalJs(`fetch('/catalog').then(r => r.text()).then(t => (t.match(/\\/product\\/(\\d+)/) || [])[1])`)) || '1';
  for (const [name, url] of [['catalog', '/catalog'], ['product', '/product/' + pid], ['cart', '/cart'], ['services', '/services/request']]) {
    await go(url, 700);
    await evalJs('window.scrollTo(0, 420)'); await sleep(300);
    await shot(`nav-over-${name}-1440.jpg`);
    // text colour vs the real pixels behind it, nav links only
    const r = await contrastOf(['nav.site-nav .sn-link', 'nav.site-nav .sn-cta', 'nav.site-nav .sn-brand'], `nav-${name}`);
    report.contrast.push(...r);
  }
  await viewport(390, 844);
  await go('/catalog', 700); await evalJs('window.scrollTo(0, 500)'); await sleep(300); await shot('nav-over-catalog-390.jpg');
  await evalJs(`document.getElementById('sn-burger').click()`); await sleep(400); await shot('nav-over-catalog-open-390.jpg');

  async function contrastOf(selectors, tag, scale = 1) {
    // 1) read each text box and colour, 2) make all text transparent, 3) screenshot, 4) sample the pixels behind the text
    await evalJs(`(() => { if (!document.getElementById('__pause')) { const s = document.createElement('style'); s.id = '__pause'; s.textContent = '.lp-rail__track { animation-play-state: paused !important; } .hero-v2-slide, .hero-v2-content { animation: none !important; }'; document.head.appendChild(s); } })()`);
    await sleep(120);
    const items = await evalJs(`(() => {
      const out = []; const seen = new Set();
      for (const sel of ${JSON.stringify(selectors)}) for (const el of document.querySelectorAll(sel)) {
        if (!el.getClientRects().length || el.closest('.is-clone') || seen.has(el)) continue; seen.add(el);
        const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); let t;
        while ((t = w.nextNode())) {
          if (!t.textContent.trim()) continue;
          const p = t.parentElement; if (p.closest('.sn-label, .sn-sr') && getComputedStyle(p.closest('.sn-label, .sn-sr')).position === 'absolute') continue;
          const rg = document.createRange(); rg.selectNodeContents(t); const b = rg.getBoundingClientRect(); if (!b.width || b.bottom < 0 || b.top > innerHeight) continue;
          // the rails fade out over the top and bottom 9% of their window (a mask): only text in the fully opaque middle is measured
          const vw = p.closest('.lp-rail__view'); if (vw) { const v = vw.getBoundingClientRect(); if (b.top < v.top + 0.1 * v.height || b.bottom > v.bottom - 0.1 * v.height) continue; }
          const c = getComputedStyle(p).color.match(/[\\d.]+/g).map(Number);
          out.push({ name: '${tag}', text: t.textContent.trim().slice(0, 28), x: b.left, y: b.top, w: b.width, h: b.height, r: c[0], g: c[1], b: c[2], a: c[3] === undefined ? 1 : c[3] });
        }
      }
      return out; })()`);
    if (!items.length) return [];
    await evalJs(`(() => { const s = document.createElement('style'); s.id = '__hide_text'; s.textContent = '*, *::before, *::after { color: transparent !important; text-shadow: none !important; -webkit-text-fill-color: transparent !important; } svg { color: transparent !important; fill: transparent !important; stroke: transparent !important; }'; document.head.appendChild(s); })()`);
    await sleep(150);
    const png = await send('Page.captureScreenshot', { format: 'png' });
    await evalJs(`document.getElementById('__hide_text').remove(); document.getElementById('__pause').remove();`);
    const res = await evalJs(`(${SAMPLE})('data:image/png;base64,${png.data}', ${JSON.stringify(items)})`);
    return res;
  }

  // ---------- 4. text contrast over EACH rotating hero photo (desktop 1440, signed out) ----------
  await viewport(1440, 900);
  await setCookie(null);
  await go('/', 1500);
  const slides = await evalJs(`document.querySelectorAll('.hero-v2-slide').length`);
  const heroSel = ['nav.site-nav .sn-link', 'nav.site-nav .sn-cta', 'nav.site-nav .sn-brand', '.lp-hero__copy .hero-v2-tag', '.lp-hero__copy .hero-v2-title', '.lp-hero__copy .hero-v2-sub', '.lp-hero__cta',
    '.lp-ai__chip', '.lp-ai__title', '.lp-ai__text', '.lp-ai__tile b', '.lp-ai__foot', '.lp-ai__link', '.lp-rail__h', '.lp-pc__tag', '.lp-pc__nm', '.lp-pc__pr'];
  for (let i = 0; i < slides; i++) {
    for (let attempt = 0; attempt < 4; attempt++) {
      await evalJs(`(() => { document.querySelectorAll('.hero-v2-slide').forEach((s, k) => { s.style.transition = 'none'; s.classList.toggle('active', k === ${i}); }); })()`);
      await sleep(250);
      const rows = await contrastOf(heroSel, `photo${i + 1}`);
      const still = await evalJs(`[...document.querySelectorAll('.hero-v2-slide')].findIndex((s) => s.classList.contains('active'))`);
      if (still === i) { report.contrast.push(...rows); break; }
    }
    await shot(`hero-photo${i + 1}-1440.jpg`);
  }

  // ---------- 5. rails: seamless loop, pause on hover and focus, reduced motion ----------
  await viewport(1440, 900);
  await go('/', 1200);
  const geo = await evalJs(`(() => [...document.querySelectorAll('.lp-rail')].map((rail) => {
    const lis = [...rail.querySelectorAll('li')]; const half = lis.length / 2; const track = rail.querySelector('.lp-rail__track');
    const cs = getComputedStyle(track);
    return { name: rail.querySelector('h2').innerText.trim(), cards: half, cloneAriaHidden: lis.slice(half).every((l) => l.getAttribute('aria-hidden') === 'true' && l.querySelector('a').getAttribute('tabindex') === '-1'),
      loopDistance: lis[half].offsetTop - lis[0].offsetTop, halfOfTrack: (track.scrollHeight - parseFloat(cs.paddingTop)) / 2 - 0, anim: cs.animationName, dur: cs.animationDuration, dir: cs.animationName.includes('Down') ? 'down' : 'up', static: rail.classList.contains('is-static') };
  }))()`);
  report.rails.geometry = geo;
  const tf = () => evalJs(`[...document.querySelectorAll('.lp-rail__track')].map((t) => new DOMMatrix(getComputedStyle(t).transform).m42)`);
  const t1 = await tf(); await sleep(1200); const t2 = await tf();
  report.rails.moving = { t1, t2, directions: t2.map((v, i) => (v - t1[i] > 0 ? 'down' : 'up')) };
  const rb = await evalJs(`(() => { const b = document.querySelector('.lp-rail').getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: rb.x, y: rb.y });
  await sleep(400);
  const h1 = await tf(); await sleep(1000); const h2 = await tf();
  report.rails.hoverPaused = { state: await evalJs(`getComputedStyle(document.querySelector('.lp-rail__track')).animationPlayState`), still: h1[0] === h2[0], otherStillMoves: h1[1] !== h2[1] };
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 5 });
  await sleep(400);
  await evalJs(`document.querySelectorAll('.lp-rail')[1].querySelector('li:not(.is-clone) a').focus()`);
  await sleep(300);
  const f1 = await tf(); await sleep(1000); const f2 = await tf();
  report.rails.focusPaused = { state: await evalJs(`getComputedStyle(document.querySelectorAll('.lp-rail__track')[1]).animationPlayState`), still: f1[1] === f2[1] };
  await evalJs(`document.activeElement.blur()`);
  await shot('rails-1440.jpg');
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await go('/', 1000);
  report.rails.reducedMotion = await evalJs(`[...document.querySelectorAll('.lp-rail')].map((rail) => { const t = rail.querySelector('.lp-rail__track'); const v = rail.querySelector('.lp-rail__view'); const cs = getComputedStyle(t);
    return { anim: cs.animationName, transform: cs.transform, clonesHidden: [...rail.querySelectorAll('.is-clone')].every((c) => getComputedStyle(c).display === 'none'), scrollable: v.scrollHeight > v.clientHeight && getComputedStyle(v).overflowY === 'auto' }; })`);
  await shot('rails-reduced-motion-1440.jpg');
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

  // ---------- 6. shared button audit on the home page (measure.js) ----------
  for (const [w, signed] of [[1440, false], [1440, true], [390, false]]) {
    await viewport(w, w === 390 ? 844 : 900);
    await setCookie(signed ? cookieFor() : null);
    await go('/', 1200);
    const b = await evalJs(BUTTONS);
    report.buttons.push({ w, signed, count: b.count, failures: b.failures.map((f) => ({ text: f.text, cls: f.cls, min: f.min })), rows: b.rows.filter((r) => /lp-hero__cta|sn-cta|sn-burger/.test(r.cls)).map((r) => ({ text: r.text, cls: r.cls, min: r.min, h: r.h, states: r.states })) });
  }

  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  console.log('\nwrote _checks.json');
  chrome.kill();
  process.exit(0);
}

main().catch((e) => { console.error(e); process.exit(1); });
