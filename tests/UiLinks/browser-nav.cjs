/*
 * Smart nav + focus pages + glass toasts browser walk (SCRATCH DATABASE ONLY). Saves screenshots into docs/stage-nav-screenshots/,
 * checks the light/dark rule against REAL pixels on every customer page, the collapse / reveal behaviour, the modal and Luma hide,
 * the mobile menu, horizontal overflow at 390px, toast behaviour, and measures text contrast (nav + toasts, both variants) from real
 * pixels. Writes docs/stage-nav-screenshots/_checks.json.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_hero.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-nav.cjs
 *
 * The signed-in part uses sessions minted by tests/UiLinks/session-cookie-for.php (customer sign-in has reCAPTCHA, which is not bypassed).
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-nav-screenshots');
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

// Runs in the page: given a screenshot (data URL) with ALL text made transparent and the list of text boxes, returns the contrast of
// each text colour against the worst painted pixel behind it (lightest for light text, darkest for dark text).
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

// Mean luminance (0..1) of a strip of a screenshot, run in the page.
const STRIP = `(async (dataUrl, x, y, w, h) => {
  const img = new Image(); img.src = dataUrl; await img.decode();
  const cv = document.createElement('canvas'); cv.width = img.width; cv.height = img.height;
  const cx = cv.getContext('2d', { willReadFrequently: true }); cx.drawImage(img, 0, 0);
  const lin = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); };
  const d = cx.getImageData(Math.max(0, x), Math.max(0, y), Math.min(w, img.width - x), Math.min(h, img.height - y)).data; let s = 0, n = 0;
  for (let i = 0; i < d.length; i += 4) { s += 0.2126 * lin(d[i]) + 0.7152 * lin(d[i + 1]) + 0.0722 * lin(d[i + 2]); n++; }
  return s / n;
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

  const go = async (url, wait = 900) => {
    events.length = 0;
    await send('Page.navigate', { url: BASE + url });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(wait);
  };
  const viewport = (w, h = 900) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 500 });
  const shot = async (file, opts = {}) => {
    let params = { format: 'jpeg', quality: 82 };
    if (opts.full) {
      const m = await send('Page.getLayoutMetrics');
      params = { ...params, captureBeyondViewport: true, clip: { x: 0, y: 0, width: m.cssContentSize.width, height: Math.min(m.cssContentSize.height, opts.max || 1400), scale: 1 } };
    }
    const r = await send('Page.captureScreenshot', params);
    fs.writeFileSync(path.join(OUT, file), Buffer.from(r.data, 'base64'));
  };
  const logins = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookieFor = (guard) => JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), guard === 'admin' ? logins.admin_email : logins.customer_email, guard || 'web'], { cwd: ROOT, encoding: 'utf8' }));
  const setCookie = async (c) => { await send('Network.clearBrowserCookies'); if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE }); };
  const key = async (k, code, vk) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: vk }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk }); };
  const scrollTo = async (y) => { await evalJs(`window.scrollTo(0, ${y})`); await sleep(260); };
  // scroll to y, then nudge up a little so the bar is expanded again (scroll up shows it)
  const expandedAt = async (y) => { await evalJs(`window.scrollTo(0, ${y + 12})`); await sleep(150); await evalJs(`window.scrollTo(0, ${y})`); await sleep(520); };

  const report = { themeScan: [], behaviour: {}, overflow: [], toast: {}, contrast: [], modal: {}, mobile: {} };
  const bad = [];
  const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

  async function contrastOf(selectors, tag, hide) {
    // 1) read each text box and colour, 2) make all text transparent, 3) screenshot, 4) sample the pixels behind the text
    const items = await evalJs(`(() => {
      const out = []; const seen = new Set();
      for (const sel of ${JSON.stringify(selectors)}) for (const el of document.querySelectorAll(sel)) {
        if (!el.getClientRects().length || seen.has(el) || getComputedStyle(el).visibility === 'hidden') continue; seen.add(el);
        const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); let t;
        while ((t = w.nextNode())) {
          if (!t.textContent.trim()) continue;
          const p = t.parentElement; if (p.closest('.sn-sr')) continue;
          const rg = document.createRange(); rg.selectNodeContents(t); const b = rg.getBoundingClientRect(); if (!b.width || b.bottom < 0 || b.top > innerHeight) continue;
          const c = getComputedStyle(p).color.match(/[\\d.]+/g).map(Number);
          out.push({ name: '${tag}', text: t.textContent.trim().slice(0, 28), x: b.left, y: b.top, w: b.width, h: b.height, r: c[0], g: c[1], b: c[2] });
        }
      }
      return out; })()`);
    if (!items.length) return [];
    await evalJs(`(() => { const s = document.createElement('style'); s.id = '__hide_text'; s.textContent = '*, *::before, *::after { color: transparent !important; text-shadow: none !important; -webkit-text-fill-color: transparent !important; -webkit-text-stroke: 0 !important; transition: none !important; animation-play-state: paused !important; } svg { color: transparent !important; fill: transparent !important; stroke: transparent !important; }'; document.head.appendChild(s); })()`);
    await sleep(150);
    const png = await send('Page.captureScreenshot', { format: 'png' });
    await evalJs(`document.getElementById('__hide_text').remove()`);
    return evalJs(`(${SAMPLE})('data:image/png;base64,${png.data}', ${JSON.stringify(items)})`);
  }

  const navInfo = () => evalJs(`(() => { const n = document.getElementById('site-nav'); const b = n.querySelector('.sn-brand').getBoundingClientRect(); const cs = getComputedStyle(n);
    return { collapsed: n.classList.contains('collapsed'), light: n.classList.contains('light'), open: n.classList.contains('is-open'), bg: cs.backgroundColor, border: cs.borderTopColor, blur: cs.backdropFilter, opacity: cs.opacity, visibility: cs.visibility,
      logo: { l: Math.round(b.left), t: Math.round(b.top), w: Math.round(b.width), h: Math.round(b.height) }, logoStroke: getComputedStyle(n.querySelector('.sn-brand')).webkitTextStrokeWidth,
      linksInert: n.querySelector('.sn-links').hasAttribute('inert'), burgerInert: document.getElementById('sn-burger').hasAttribute('inert'), linksVis: getComputedStyle(n.querySelector('.sn-links')).visibility }; })()`);

  // ---------- 1. light/dark rule against real pixels, every customer page ----------
  const pid = (await (async () => { await setCookie(null); await go('/catalog', 300); return evalJs(`(document.body.innerHTML.match(/\\/product\\/(\\d+)/) || [])[1]`); })()) || '1';
  const guestPages = ['/', '/catalog', '/product/' + pid, '/terms', '/guide/custom-design', '/guide/measure'];
  const userPages = ['/', '/catalog', '/product/' + pid, '/cart', '/orders', '/profile', '/notifications', '/design', '/feedback', '/services/request', '/checkout', '/validate-account', '/guide/custom-design', '/terms'];
  await viewport(1440, 900);
  for (const [who, pages] of [['guest', guestPages], ['user', userPages]]) {
    await setCookie(who === 'user' ? cookieFor() : null);
    for (const url of pages) {
      await go(url, 600);
      const h = await evalJs(`document.documentElement.scrollHeight`);
      if (!(await evalJs(`!!document.getElementById('site-nav')`))) { console.log('skip  (no nav) ' + who + ' ' + url + ' -> ' + (await evalJs('location.pathname'))); continue; }
      const maxY = Math.min(h - 900, 6000);
      for (let y = 0; y <= Math.max(0, maxY); y += 140) {
        await expandedAt(y);
        const st = await navInfo();
        if (st.collapsed) continue; // top of a very short page, nothing to check
        // hide the nav, screenshot, average luminance of the strip where the bar sits
        await evalJs(`document.getElementById('site-nav').style.visibility = 'hidden'`);
        await sleep(60);
        const png = await send('Page.captureScreenshot', { format: 'png' });
        await evalJs(`document.getElementById('site-nav').style.visibility = ''`);
        const lum = await evalJs(`(${STRIP})('data:image/png;base64,${png.data}', 300, 20, 840, 64)`);
        const rowBad = (st.light && lum < 0.28) || (!st.light && lum > 0.55);
        report.themeScan.push({ who, url, y, light: st.light, lum: Math.round(lum * 100) / 100, bad: rowBad });
        if (rowBad) {
          const under = await evalJs(`(() => { const n = document.getElementById('site-nav'); return document.elementsFromPoint(innerWidth / 2, 46).filter((e) => !n.contains(e)).slice(0, 4).map((e) => e.tagName + '.' + String(e.className).slice(0, 40) + ' ' + getComputedStyle(e).backgroundColor).join(' > '); })()`);
          console.log('CHECK theme ' + who + ' ' + url + ' y=' + y + ' light=' + st.light + ' lum=' + lum.toFixed(2) + ' under: ' + under);
        }
      }
    }
  }
  const themeBad = report.themeScan.filter((r) => r.bad);
  note(themeBad.length === 0, `light/dark rule matches the real pixels in ${report.themeScan.length} samples (${themeBad.length} mismatches)`);

  // ---------- 2. behaviour: collapse, logo corner, reveal, inert ----------
  await setCookie(cookieFor());
  await viewport(1440, 900);
  await go('/', 1500);
  let s0 = await navInfo();
  note(!s0.collapsed && !s0.light, 'home top: expanded, dark variant over the hero');
  await scrollTo(700); await scrollTo(1400);
  let s1 = await navInfo();
  note(s1.collapsed && s1.bg === 'rgba(0, 0, 0, 0)' && s1.blur === 'none' && s1.linksInert && s1.burgerInert && s1.linksVis === 'hidden', 'scrolling down past 140px collapses: no bar, no blur, links inert and hidden');
  note(s1.logo.l <= 16 && s1.logo.t < 30, `collapsed logo sits in the top-left corner (${s1.logo.l},${s1.logo.t})`);
  note(s1.light && s1.logoStroke === '0px', 'collapsed logo over a light area uses the soft glow, no stroke');
  await shot('home-collapsed-over-light-1440.jpg');
  // tab order: nothing in the collapsed nav except the logo is reachable
  const tabbable = await evalJs(`[...document.getElementById('site-nav').querySelectorAll('a,button')].filter((e) => !e.closest('[inert]')).map((e) => e.className)`);
  note(tabbable.length === 1 && /sn-brand/.test(tabbable[0]), 'collapsed: only the logo is tabbable');
  // hover on the logo shows the bar
  const lg = s1.logo;
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: lg.l + lg.w / 2, y: lg.t + lg.h / 2 });
  await sleep(700);
  const s2 = await navInfo();
  note(!s2.collapsed && !s2.linksInert, 'hovering the logo reveals the bar');
  await shot('home-hovered-over-light-1440.jpg');
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 700, y: 600 });
  await sleep(1400);
  const s3 = await navInfo();
  note(s3.collapsed, 'leaving the bar hides it again (still scrolled down)');
  // scroll up shows it
  await scrollTo(1300);
  const s4 = await navInfo();
  note(!s4.collapsed, 'scrolling up shows the bar');
  // small scroll changes (< 8px) are ignored
  await scrollTo(1700); await scrollTo(1900);
  await evalJs(`window.scrollTo(0, 1905)`); await sleep(200);
  note((await navInfo()).collapsed, 'collapsed again after scrolling down');
  // keyboard focus shows it
  await key('Tab', 'Tab', 9); await sleep(400);
  const s5 = await navInfo();
  note(!s5.collapsed, 'keyboard focus inside the nav reveals it (Tab from the page top lands on the logo/links)');
  await evalJs(`document.activeElement && document.activeElement.blur()`);
  // top of page always expanded
  await scrollTo(0); await sleep(300);
  note(!(await navInfo()).collapsed, 'within 40px of the top it is always expanded');

  // ---------- 3. home: dark hero, screenshots ----------
  await go('/', 1500);
  const hs = await navInfo();
  note(!hs.light && !hs.collapsed, 'home top (over the dark hero): dark variant');
  await shot('home-top-1440.jpg');
  const darkNav = await contrastOf(['nav.site-nav .sn-link', 'nav.site-nav .sn-cta', 'nav.site-nav .sn-brand', 'nav.site-nav .sn-label'], 'nav-dark-home');
  report.contrast.push(...darkNav);
  await scrollTo(400); await scrollTo(700);
  await shot('home-scrolled-collapsed-1440.jpg');
  const hc = await navInfo();
  note(hc.collapsed, 'home scrolled: collapsed');
  // sections declared in the page: footer is light (slim footer today)
  await scrollTo(100000); await scrollTo(99000);
  await shot('home-bottom-1440.jpg');

  // ---------- 3b. guest scroll parity: a visitor who is NOT signed in gets the same nav states as a customer ----------
  const stepState = async (url, who) => {
    await setCookie(who === 'user' ? cookieFor() : null);
    await go(url, 700);
    const rows = [];
    for (const y of [0, 300, 700, 1200, 2000, 2520]) {
      await expandedAt(y);
      const st = await navInfo();
      rows.push({ y, light: st.light, collapsed: st.collapsed, bg: st.bg });
      if (who === 'guest' && url === '/' && [0, 700, 1200].includes(y)) await shot(`guest-home-scroll-${y}-1440.jpg`);
      if (who === 'guest' && url === '/catalog' && y === 700) await shot('guest-catalog-scroll-700-1440.jpg');
    }
    return rows;
  };
  await viewport(1440, 900);
  for (const url of ['/', '/catalog', '/terms', '/reviews']) {
    const g = await stepState(url, 'guest');
    const u = await stepState(url, 'user');
    report.guestParity = report.guestParity || {};
    report.guestParity[url] = { guest: g, user: u };
    note(JSON.stringify(g) === JSON.stringify(u), `guest scroll states equal the signed-in ones on ${url}: ` + g.map((r) => r.y + (r.light ? 'L' : 'D') + (r.collapsed ? 'c' : '')).join(' '));
  }

  // ---------- 4. light variant text contrast on catalog / cart ----------
  for (const [name, url] of [['catalog', '/catalog'], ['cart', '/cart']]) {
    await go(url, 700);
    await expandedAt(300);
    const st = await navInfo();
    note(st.light && !st.collapsed, `${name} scrolled 300: expanded, light variant`);
    const rows = await contrastOf(['nav.site-nav .sn-link', 'nav.site-nav .sn-cta', 'nav.site-nav .sn-brand', 'nav.site-nav .sn-label'], `nav-light-${name}`);
    report.contrast.push(...rows);
    await shot(`${name}-scrolled-1440.jpg`);
  }
  // the light bar over a dark PHOTO inside a light section (home gallery, y=2520): the worst real case for the light variant
  await go('/', 900); await expandedAt(2520);
  report.contrast.push(...(await contrastOf(['nav.site-nav .sn-link', 'nav.site-nav .sn-cta', 'nav.site-nav .sn-brand', 'nav.site-nav .sn-label'], 'nav-light-over-dark-photo')));
  await shot('home-light-nav-over-photo-1440.jpg');
  await go('/cart', 700);
  await shot('cart-top-1440.jpg');

  // ---------- 5. modals and Luma hide the bar ----------
  await go('/catalog', 700);
  await evalJs(`document.getElementById('chatbot-fab-btn').click()`); await sleep(500);
  let m1 = await evalJs(`(() => { const c = getComputedStyle(document.getElementById('site-nav')); return { cls: document.body.classList.contains('modal-open'), opacity: c.opacity, vis: c.visibility, pe: c.pointerEvents }; })()`);
  note(m1.cls && m1.opacity === '0' && m1.vis === 'hidden' && m1.pe === 'none', 'Luma chat open: nav hidden (opacity 0, visibility hidden, pointer-events none)');
  await shot('luma-open-nav-hidden-1440.jpg');
  await evalJs(`toggleChatbot()`); await sleep(500);
  m1 = await evalJs(`getComputedStyle(document.getElementById('site-nav')).visibility`);
  note(m1 === 'visible', 'Luma closed: nav is back');
  // a shared .lp-modal
  await go('/orders', 600);
  const hasModal = await evalJs(`!!document.querySelector('[data-lp-modal-open]')`);
  report.modal.hasModalOnOrders = hasModal;
  await go('/services/request', 700);
  // no page shipped with a .lp-modal is reachable with the demo data, so add the real component markup and open it
  await evalJs(`(() => { const d = document.createElement('div'); d.className = 'lp-modal'; d.id = 'walk-modal'; d.hidden = true; d.setAttribute('role', 'dialog'); d.setAttribute('aria-modal', 'true'); d.setAttribute('aria-labelledby', 'walk-modal-t'); d.innerHTML = '<div class="lp-modal__box"><div class="lp-modal__head"><h2 class="lp-modal__title" id="walk-modal-t">Walk modal</h2><button type="button" class="lp-modal__x" data-lp-modal-close aria-label="Close">&times;</button></div><div class="lp-modal__body">Body</div><div class="lp-modal__foot"><button type="button" class="lp-btn lp-btn--secondary" data-lp-modal-close>Close</button></div></div>'; document.body.appendChild(d); })()`);
  if (await evalJs(`!!window.LpModal && !!document.querySelector('.lp-modal')`)) {
    const id = await evalJs(`document.querySelector('.lp-modal').id`);
    await evalJs(`LpModal.open(document.getElementById('${id}'))`); await sleep(400);
    const mo = await evalJs(`({ body: document.body.classList.contains('modal-open'), vis: getComputedStyle(document.getElementById('site-nav')).visibility, focusInModal: !!document.activeElement.closest('.lp-modal') })`);
    note(mo.body && mo.vis === 'hidden' && mo.focusInModal, 'shared .lp-modal open: nav hidden, focus moved into the modal');
    await key('Escape', 'Escape', 27); await sleep(400);
    const mc = await evalJs(`({ body: document.body.classList.contains('modal-open'), vis: getComputedStyle(document.getElementById('site-nav')).visibility })`);
    note(!mc.body && mc.vis === 'visible', 'modal closed (Escape): nav is back');
  } else { console.log('skip  no .lp-modal on /services/request'); }

  // ---------- 6. mobile 390: closed, open, collapsed, overflow on every touched page ----------
  await viewport(390, 844);
  await go('/', 1400);
  await shot('mobile-home-closed-390.jpg');
  await evalJs(`document.getElementById('sn-burger').click()`); await sleep(500);
  const mo1 = await evalJs(`(() => { const rows = [...document.querySelectorAll('#sn-links > *')].filter((e) => e.getClientRects().length).map((e) => ({ t: (e.innerText || '').trim().replace(/\\s+/g, ' '), h: Math.round(e.getBoundingClientRect().height) })); return { rows, bg: getComputedStyle(document.getElementById('sn-links')).backgroundColor, light: document.getElementById('site-nav').classList.contains('light') }; })()`);
  note(mo1.rows.length >= 5 && mo1.rows.every((r) => r.h >= 48), 'mobile menu rows are 48px or more: ' + mo1.rows.map((r) => r.t + ':' + r.h).join(' | '));
  note(/rgba\(36, 21, 13, 0\.92\)/.test(mo1.bg), 'open menu panel is .92 alpha dark over the dark hero: ' + mo1.bg);
  await shot('mobile-home-menu-open-390.jpg');
  await scrollTo(300);
  note(!(await navInfo()).collapsed, 'menu open: the bar never collapses');
  await key('Escape', 'Escape', 27); await sleep(300);
  await go('/catalog', 700); await scrollTo(300);
  await evalJs(`document.getElementById('sn-burger').click()`); await sleep(500);
  await shot('mobile-catalog-menu-open-light-390.jpg');
  const mo2 = await evalJs(`getComputedStyle(document.getElementById('sn-links')).backgroundColor`);
  note(/rgba\(255, 252, 246, 0\.94\)/.test(mo2), 'open menu panel over a light page is .94 alpha light: ' + mo2);
  const rowsLight = await contrastOf(['nav.site-nav .sn-link', 'nav.site-nav .sn-label'], 'nav-light-mobile-menu');
  report.contrast.push(...rowsLight);
  await evalJs(`document.getElementById('sn-burger').click()`); await sleep(300);
  await scrollTo(900); await scrollTo(1200);
  await shot('mobile-catalog-collapsed-390.jpg');
  const bp = await evalJs(`document.documentElement.clientWidth`);
  report.mobile.collapsed = await navInfo();
  note(report.mobile.collapsed.collapsed, 'mobile: collapses on scroll too');
  // 1000 / 1001 breakpoint
  for (const [w, wantBurger] of [[1000, true], [1001, false]]) {
    await viewport(w, 800); await go('/', 400);
    const d = await evalJs(`getComputedStyle(document.getElementById('sn-burger')).display`);
    note((d !== 'none') === wantBurger, `${w}px: hamburger ${wantBurger ? 'shown' : 'hidden'} (display ${d})`);
  }

  // desktop widths just above the hamburger breakpoint: the full link set must fit on one row without touching the logo
  await setCookie(cookieFor());
  for (const w of [1001, 1100, 1280, 1440]) {
    await viewport(w, 800); await go('/catalog', 500);
    const fit = await evalJs(`(() => { const n = document.getElementById('site-nav').getBoundingClientRect(); const brand = document.querySelector('.sn-brand').getBoundingClientRect();
      const ls = [...document.querySelectorAll('#sn-links > *')].filter((e) => e.getClientRects().length).map((e) => e.getBoundingClientRect());
      return { rows: new Set(ls.map((r) => Math.round(r.top))).size, gap: Math.round(ls[0].left - brand.right), overflowRight: Math.round(ls[ls.length - 1].right - n.right), names: ls.length, over: document.documentElement.scrollWidth - document.documentElement.clientWidth }; })()`);
    note(fit.rows === 1 && fit.gap >= 8 && fit.overflowRight <= 0 && fit.over <= 0, `${w}px: ${fit.names} nav items on one row, ${fit.gap}px from the logo, no overflow ` + JSON.stringify(fit));
    if (w === 1001) await shot('nav-1001.jpg');
  }
  // ---------- 7. overflow at 390 on every page touched ----------
  await viewport(390, 844);
  const focusPages = ['/login', '/register', '/forgot-password', '/reset-password/abc'];
  for (const [who, pages] of [['guest', [...guestPages, ...focusPages]], ['user', [...userPages, '/verify-email']]]) {
    await setCookie(who === 'user' ? cookieFor() : null);
    for (const url of pages) {
      await go(url, 450);
      const o = await evalJs(`({ path: location.pathname, over: document.documentElement.scrollWidth - document.documentElement.clientWidth, nav: !!document.getElementById('site-nav') })`);
      report.overflow.push({ who, url, ...o });
      note(o.over <= 0, `no horizontal scroll at 390: ${who} ${url} -> ${o.path}`);
    }
  }

  // ---------- 8. focus pages ----------
  await setCookie(null);
  for (const [name, url] of [['login', '/login'], ['register', '/register'], ['forgot', '/forgot-password']]) {
    await viewport(1280, 800); await go(url, 700); await shot(`focus-${name}-1280.jpg`);
    const f = await evalJs(`({ nav: !!document.getElementById('site-nav'), footer: !!document.querySelector('.lp-footer'), luma: !!document.getElementById('chatbot-window'), logo: !!document.querySelector('.focus-logo'), back: !!document.querySelector('.auth-back-btn'), errors: 0 })`);
    note(!f.nav && !f.footer && !f.luma && f.logo && f.back, `focus page ${name}: no nav, no footer, logo + own back control`);
  }
  await viewport(390, 844); await go('/login', 700); await shot('focus-login-390.jpg');
  const centered = await evalJs(`(() => { const c = document.querySelector('.auth-card').getBoundingClientRect(); return { l: Math.round(c.left), r: Math.round(innerWidth - c.right), top: Math.round(c.top), fits: c.width <= innerWidth }; })()`);
  note(Math.abs(centered.l - centered.r) <= 2 && centered.fits, `login card centered on mobile (${centered.l}/${centered.r})`);
  await setCookie(cookieFor());
  await viewport(1280, 800); await go('/validate-account', 800); await shot('focus-validation-1280.jpg');
  note(await evalJs(`!document.getElementById('site-nav') && !document.querySelector('.lp-footer')`), 'validation page is a focus page');

  // ---------- 9. toasts ----------
  await setCookie(cookieFor());
  await viewport(1440, 900);
  await go('/', 1400);
  await evalJs(`LpToast.show('success', 'Saved to your cart. Open the cart when you are ready to check out.'); LpToast.show('info', 'Your order OC-2026-0042 is now in production.')`);
  await sleep(700);
  const tDark = await evalJs(`[...document.querySelectorAll('.lp-toast')].map((t) => ({ light: t.classList.contains('light'), role: t.getAttribute('role'), top: Math.round(t.getBoundingClientRect().top) }))`);
  note(tDark.every((t) => !t.light) && tDark[0].top >= 90 && tDark[0].top <= 100, 'toasts over the dark hero are dark glass and start 96px from the top: ' + JSON.stringify(tDark));
  await shot('toast-over-dark-1440.jpg');
  report.contrast.push(...(await contrastOf(['.lp-toast .lp-toast-msg'], 'toast-dark')));
  await go('/catalog', 900);
  await evalJs(`LpToast.show('success', 'Saved to your cart. Open the cart when you are ready to check out.'); LpToast.show('error', 'That did not go through. Check your details and try again.'); LpToast.show('warning', 'Only 2 left in stock.'); LpToast.show('info', 'Your order is now in production.')`);
  await sleep(700);
  const tLight = await evalJs(`[...document.querySelectorAll('.lp-toast')].map((t) => ({ type: t.className.replace('lp-toast ', ''), light: t.classList.contains('light'), role: t.getAttribute('role') }))`);
  note(tLight.length === 4 && tLight.every((t) => t.light), 'toasts over the light catalog are light glass: ' + JSON.stringify(tLight.map((t) => t.type)));
  note(tLight.find((t) => /error/.test(t.type)).role === 'alert' && tLight.filter((t) => t.role === 'status').length === 3, 'error toast is role=alert, the rest role=status');
  await shot('toast-over-light-1440.jpg');
  report.contrast.push(...(await contrastOf(['.lp-toast .lp-toast-msg'], 'toast-light')));
  // max 4: add two more
  await evalJs(`LpToast.show('info', 'Fifth one'); LpToast.show('info', 'Sixth one')`);
  const cnt = await evalJs(`document.querySelectorAll('.lp-toast').length`);
  const newestFirst = await evalJs(`document.querySelector('.lp-toast .lp-toast-msg').textContent`);
  note(cnt === 4 && newestFirst === 'Sixth one', `at most 4 toasts, newest on top (count ${cnt}, first "${newestFirst}")`);
  // pause on hover: timer bar stops
  const tb = await evalJs(`(() => { const r = document.querySelector('.lp-toast').getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2 }; })()`);
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: tb.x, y: tb.y }); await sleep(300);
  const play = await evalJs(`getComputedStyle(document.querySelector('.lp-toast .lp-toast-bar')).animationPlayState`);
  note(play === 'paused', 'hover pauses the timer bar');
  const xsz = await evalJs(`(() => { const b = document.querySelector('.lp-toast-close').getBoundingClientRect(); return Math.round(b.width) + 'x' + Math.round(b.height) + ' ' + document.querySelector('.lp-toast-close').getAttribute('aria-label'); })()`);
  note(/^44x44 Dismiss notification$/.test(xsz), 'close button is 44x44 with the right label: ' + xsz);
  await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 500 });
  // toast re-themes when the content behind it changes (scroll from light page region to the dark footer is not dark today; check class toggles via data-nav swap)
  await evalJs(`document.querySelector('main').setAttribute('data-nav', 'dark')`); await evalJs(`window.scrollBy(0, 5)`); await sleep(600);
  const re = await evalJs(`document.querySelector('.lp-toast').classList.contains('light')`);
  await evalJs(`document.querySelector('main').removeAttribute('data-nav')`);
  note(re === false, 'a toast re-checks what is behind it on scroll (flipped to dark when the area behind became data-nav=dark)');
  // auto-dismiss: success goes at 5s
  await go('/catalog', 500);
  await evalJs(`LpToast.show('success', 'Gone in five seconds')`);
  await sleep(5600);
  note((await evalJs(`document.querySelectorAll('.lp-toast').length`)) === 0, 'a success toast auto-dismisses after ~5s');
  await evalJs(`LpToast.show('error', 'Stays eight seconds')`);
  await sleep(5600);
  const still = await evalJs(`document.querySelectorAll('.lp-toast').length`);
  await sleep(3000);
  note(still === 1 && (await evalJs(`document.querySelectorAll('.lp-toast').length`)) === 0, 'an error toast lasts ~8s');
  // mobile toast spans the width
  await viewport(390, 844); await go('/catalog', 600);
  await evalJs(`LpToast.show('error', 'That did not go through. Check your details and try again.')`); await sleep(600);
  const mt = await evalJs(`(() => { const r = document.querySelector('.lp-toast').getBoundingClientRect(); return { l: Math.round(r.left), r: Math.round(innerWidth - r.right), top: Math.round(r.top) }; })()`);
  note(mt.l === 12 && mt.r === 12 && mt.top === 88, 'mobile toast spans left/right 12px, below the nav: ' + JSON.stringify(mt));
  await shot('toast-mobile-390.jpg');
  // reduced motion
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'reduce' }] });
  await viewport(1440, 900); await go('/catalog', 600);
  await evalJs(`LpToast.show('info', 'No slide in this mode')`); await sleep(100);
  const rm = await evalJs(`(() => { const t = document.querySelector('.lp-toast'); const cs = getComputedStyle(t); return { anim: cs.animationName, transform: cs.transform, bar: getComputedStyle(t.querySelector('.lp-toast-bar')).animationName, navTransition: getComputedStyle(document.getElementById('site-nav')).transitionDuration }; })()`);
  note(rm.anim === 'none' && rm.bar === 'none' && /^0s/.test(rm.navTransition), 'prefers-reduced-motion: no slide, static timer bar, no nav transitions ' + JSON.stringify(rm));
  await send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-motion', value: 'no-preference' }] });

  // ---------- 10. admin toast ----------
  await viewport(1440, 900);
  await setCookie(cookieFor('admin'));
  await go('/admin/dashboard', 900);
  note(await evalJs(`!document.getElementById('site-nav') && !!document.getElementById('lp-toasts')`), 'admin: no smart nav, toast container present');
  await evalJs(`LpToast.show('success', 'Order status updated.'); LpToast.show('error', 'Could not save the product. Check the highlighted fields.')`);
  await sleep(700);
  const at = await evalJs(`(() => { const r = document.querySelector('.lp-toast').getBoundingClientRect(); return { top: Math.round(r.top), light: document.querySelector('.lp-toast').classList.contains('light') }; })()`);
  note(at.top === 16 && at.light, 'admin toast sits 16px from the top and falls back to light: ' + JSON.stringify(at));
  await shot('toast-admin-1440.jpg');
  report.contrast.push(...(await contrastOf(['.lp-toast .lp-toast-msg'], 'toast-admin-light')));

  // ---------- 11. shared button audit (measure.js) over both nav variants: guest Login / Register / burger + the page buttons ----------
  report.buttons = [];
  for (const [name, url, w, y, signed] of [['home-guest-dark', '/', 1440, 0, false], ['catalog-guest-light', '/catalog', 1440, 200, false], ['home-guest-dark-390', '/', 390, 0, false], ['catalog-guest-light-390', '/catalog', 390, 200, false], ['login-focus-390', '/login', 390, 0, false]]) {
    await viewport(w, w === 390 ? 844 : 900);
    await setCookie(signed ? cookieFor() : null);
    await go(url, 900);
    if (y) await expandedAt(y);
    const b = await evalJs(BUTTONS);
    // only the nav's own buttons are this session's; other failures (page buttons that predate it) are listed, not counted
    const mine = b.failures.filter((f) => /sn-|lp-toast/.test(f.cls));
    const others = [...new Set(b.failures.filter((f) => !/sn-|lp-toast/.test(f.cls)).map((f) => f.cls + ' ' + f.min))];
    report.buttons.push({ name, count: b.count, navFailures: mine.map((f) => ({ text: f.text, cls: f.cls, min: f.min })), otherPreExisting: others });
    note(mine.length === 0, `button audit ${name}: ${b.count} buttons, ${mine.length} nav failures` + (others.length ? ' (not from this session: ' + others.join('; ') + ')' : ''));
  }

  // ---------- result ----------
  const fails = report.contrast.filter((r) => r.ratio < 4.5);
  note(fails.length === 0, `contrast: ${report.contrast.length} text samples, ${fails.length} under 4.5`);
  const by = {};
  for (const r of report.contrast) { by[r.name] = by[r.name] ? Math.min(by[r.name], r.ratio) : r.ratio; }
  report.contrastMinByGroup = by;
  console.log('\ncontrast minimum per group:', JSON.stringify(by, null, 1));
  report.problems = bad;
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  console.log('\nwrote _checks.json; problems: ' + bad.length);
  chrome.kill();
  process.exit(0);
}

main().catch((e) => { console.error(e); process.exit(1); });
