/*
 * Catalog + glass Quick view browser walk (SCRATCH DATABASE ONLY). At 1440, 1024 and 390 px, as a guest and as a signed-in customer
 * (session minted by tests/UiLinks/session-cookie-for.php; customer sign-in has reCAPTCHA, which is not bypassed) it checks: no sideways
 * scroll, no console errors, Quick view opens by mouse click and by keyboard, closes by X, Escape and backdrop, focus trap and focus
 * return, photo arrows + position, the nav hides while open and comes back, empty rows are hidden, the error + Retry state, the X not
 * overlapping the price, and text contrast (from real pixels) of the glass panel over a light and a dark photo.
 * Saves screenshots into docs/stage-catalog-screenshots/ and writes _checks.json there.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_catalog.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-catalog.cjs
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn, execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage-catalog-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

// In the page: contrast of each text box against the worst painted pixel behind it (screenshot taken with all text transparent).
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
  const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const cookieFor = () => JSON.parse(execFileSync('php', ['tests/UiLinks/session-cookie-for.php', login.customer_email], { cwd: ROOT }).toString());
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9341', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) { await sleep(200); try { target = (await getJson('http://127.0.0.1:9341/json')).find((t) => t.type === 'page'); } catch (e) { /* wait */ } }
  if (!target) throw new Error('Chrome did not start');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0; const waiting = new Map(); let consoleErrors = [];
  const IGNORE = /fonts\.(googleapis|gstatic)|recaptcha|gstatic\.com|ERR_NAME_NOT_RESOLVED|ERR_INTERNET_DISCONNECTED|ERR_CONNECTION/i;
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && waiting.has(d.id)) { waiting.get(d.id)(d.result || d); waiting.delete(d.id); return; }
    if (d.method === 'Runtime.exceptionThrown') consoleErrors.push('exception: ' + (d.params.exceptionDetails.exception?.description || d.params.exceptionDetails.text));
    if (d.method === 'Runtime.consoleAPICalled' && d.params.type === 'error') consoleErrors.push('console.error: ' + d.params.args.map((a) => a.value || a.description).join(' '));
    if (d.method === 'Log.entryAdded' && d.params.entry.level === 'error') consoleErrors.push('log: ' + d.params.entry.text + ' ' + (d.params.entry.url || ''));
  };
  const send = (method, params = {}) => new Promise((r) => { const id = ++n; waiting.set(id, r); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expression) => {
    const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('eval: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text) + ' <- ' + expression.slice(0, 120));
    return r.result.value;
  };
  await send('Network.enable'); await send('Page.enable'); await send('Runtime.enable'); await send('Log.enable');

  const setCookie = async (c) => {
    await send('Network.clearBrowserCookies');
    if (c) await send('Network.setCookie', { name: c.name, value: c.value, url: BASE });
  };
  const viewport = (w, h) => send('Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w < 700 });
  const go = async (p) => { await send('Page.navigate', { url: BASE + p }); await sleep(900); for (let i = 0; i < 40; i++) { if ((await evalJs('document.readyState')) === 'complete') break; await sleep(150); } await sleep(300); };
  const shot = async (name) => { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const mouse = async (x, y) => {
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x, y });
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x, y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x, y, button: 'left', clickCount: 1 });
  };
  const KEYS = { Tab: 9, Escape: 27, Enter: 13, ArrowLeft: 37, ArrowRight: 39 };
  const key = async (k, shift) => {
    const base = { key: k, code: k, windowsVirtualKeyCode: KEYS[k], modifiers: shift ? 8 : 0 };
    await send('Input.dispatchKeyEvent', { type: 'keyDown', ...base, ...(k === 'Enter' ? { text: '\r' } : {}) });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', ...base });
    await sleep(60);
  };

  const report = { runs: [], buttonContrast: [], modalContrast: [], problems: [] };
  const bad = [];
  const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

  const state = () => evalJs(`(() => { const q = document.getElementById('cxQd'), m = document.getElementById('cxMod'), nav = document.getElementById('site-nav'); const a = document.activeElement;
    const vis = (e) => e && !e.hidden && getComputedStyle(e).display !== 'none';
    return { open: m.classList.contains('on') && !m.hidden, state: q.dataset.state, modalOpenClass: document.body.classList.contains('modal-open'), bodyOverflow: document.body.style.overflow,
      navVisible: getComputedStyle(nav).visibility !== 'hidden' && getComputedStyle(nav).opacity !== '0',
      focusInside: q.contains(a), focusId: a && (a.id || a.getAttribute('data-quick-view') || a.tagName),
      name: document.getElementById('cxName').textContent, labelledBy: q.getAttribute('aria-labelledby'), role: q.getAttribute('role'), modal: q.getAttribute('aria-modal'),
      price: document.getElementById('cxPrice').textContent, cat: document.getElementById('cxCat').textContent,
      rows: [...document.querySelectorAll('#cxInfo .cx-ir')].map((r) => r.querySelector('dt').textContent + '=' + r.querySelector('dd').textContent),
      infoShown: vis(document.getElementById('cxInfo')), sizeShown: vis(document.getElementById('cxSizeWrap')), dims: [...document.querySelectorAll('#cxDims .cx-dm')].map((d) => d.textContent),
      descShown: vis(document.getElementById('cxDesc')), badge: vis(document.getElementById('cxBadge')) ? document.getElementById('cxBadge').textContent : null,
      arrows: vis(document.getElementById('cxPrev')) && vis(document.getElementById('cxNext')), pos: vis(document.getElementById('cxPos')) ? document.getElementById('cxPos').textContent : null,
      img: document.getElementById('cxImg').getAttribute('src'), imgW: document.getElementById('cxImg').naturalWidth, more: document.getElementById('cxMore').getAttribute('href'),
      buttons: [...q.querySelectorAll('button:not([hidden]), a[href]')].filter((e) => e.offsetParent !== null).map((e) => e.id || e.textContent.trim()),
      sheetOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
      paneOverflowX: document.querySelector('.cx-pane').scrollWidth > document.querySelector('.cx-pane').clientWidth + 1 }; })()`);
  const waitReady = async (want = 'ready') => { for (let i = 0; i < 50; i++) { if ((await state()).state === want) return true; await sleep(100); } return false; };
  const btnRect = (name) => evalJs(`(() => { const b = [...document.querySelectorAll('button[data-quick-view]')].find((x) => x.dataset.name === ${JSON.stringify(name)}); if (!b) return null;
    b.scrollIntoView({ block: 'center' }); const r = b.getBoundingClientRect(); return { x: r.left + r.width / 2, y: r.top + r.height / 2, h: r.height, id: b.dataset.quickView }; })()`);

  async function openByClick(name) {
    const r = await btnRect(name); await sleep(250);
    const r2 = await btnRect(name);
    await mouse(r2.x, r2.y);
    const ok = await waitReady();
    return { ok, id: r.id };
  }
  const openerFocused = (id) => evalJs(`document.activeElement && document.activeElement.getAttribute('data-quick-view') === ${JSON.stringify(String(id))}`);

  async function contrastOf(selectors, tag) {
    const items = await evalJs(`(() => { const out = []; const seen = new Set();
      for (const sel of ${JSON.stringify(selectors)}) for (const el of document.querySelectorAll(sel)) {
        if (!el.getClientRects().length || seen.has(el) || getComputedStyle(el).visibility === 'hidden') continue; seen.add(el);
        const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); let t;
        while ((t = w.nextNode())) { if (!t.textContent.trim()) continue; const p = t.parentElement;
          const rg = document.createRange(); rg.selectNodeContents(t); const b = rg.getBoundingClientRect(); if (!b.width || b.bottom < 0 || b.top > innerHeight || b.right > innerWidth + 1 || b.left < 0) continue;
          const c = getComputedStyle(p).color.match(/[\\d.]+/g).map(Number);
          out.push({ name: '${tag}', text: t.textContent.trim().slice(0, 28), x: b.left, y: b.top, w: b.width, h: b.height, r: c[0], g: c[1], b: c[2] }); } }
      return out; })()`);
    if (!items.length) return [];
    await evalJs(`(() => { const s = document.createElement('style'); s.id = '__hide_text'; s.textContent = '*, *::before, *::after { color: transparent !important; text-shadow: none !important; -webkit-text-fill-color: transparent !important; transition: none !important; } svg { color: transparent !important; }'; document.head.appendChild(s); })()`);
    await sleep(150);
    const png = await send('Page.captureScreenshot', { format: 'png' });
    await evalJs(`document.getElementById('__hide_text').remove()`);
    return evalJs(`(${SAMPLE})('data:image/png;base64,${png.data}', ${JSON.stringify(items)})`);
  }
  const QV_TEXT = ['#cxCat', '#cxName', '.cx-price span', '.cx-price strong', '.cx-desc', '.cx-ir dt', '.cx-ir dd', '.cx-lbl', '.cx-dm small', '.cx-dm b', '.cx-dm em', '.cx-act .lp-btn', '#cxBadge', '#cxPos'];

  for (const who of ['guest', 'user']) {
    for (const [w, h] of [[1440, 900], [1024, 800], [390, 844]]) {
      const tag = `${who}-${w}`;
      const run = { tag, checks: {} };
      consoleErrors = [];
      await setCookie(who === 'user' ? cookieFor() : null);
      await viewport(w, h);
      await go('/catalog');
      const page = await evalJs(`({ cards: document.querySelectorAll('.lp-pcard').length, tags: document.querySelectorAll('.lp-pcard__price').length, sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
        badges: [...document.querySelectorAll('.lp-pcard__badge--info')].map((b) => b.closest('.lp-pcard').querySelector('.lp-pcard__title').textContent.trim() + ':' + b.textContent),
        cats: [...document.querySelectorAll('.cx-cats a')].map((a) => a.textContent.trim().replace(/\\s+/g, ' ')), tagFont: getComputedStyle(document.querySelector('.lp-pcard__price')).fontFamily,
        bg: getComputedStyle(document.body).backgroundColor, catMinH: Math.min(...[...document.querySelectorAll('.cx-cats a')].map((a) => a.getBoundingClientRect().height)),
        navVisible: getComputedStyle(document.getElementById('site-nav')).visibility })`);
      run.page = page;
      note(page.sw <= page.cw, `${tag}: no sideways scroll on /catalog (${page.sw} vs ${page.cw})`);
      note(page.cards === 11 && page.tags === 11, `${tag}: 11 available products shown (hidden one excluded), each with a price tag (${page.cards}/${page.tags})`);
      note(page.catMinH >= 44, `${tag}: category links are at least 44px tall (${page.catMinH})`);
      await shot(`catalog-${tag}`);

      // ---- A: multi-photo product, opened by mouse click ----
      const a = await openByClick('Narra Dining Table');
      let s = await state();
      note(a.ok && s.open, `${tag}: Quick view opens by click`);
      note(s.role === 'dialog' && s.modal === 'true' && s.labelledBy === 'cxName' && s.name === 'Narra Dining Table', `${tag}: dialog role, aria-modal, labelled by the product name`);
      note(s.focusInside, `${tag}: focus moved into the dialog (${s.focusId})`);
      note(s.modalOpenClass && s.bodyOverflow === 'hidden', `${tag}: body.modal-open set and page scroll locked`);
      note(!s.navVisible, `${tag}: nav hidden while open`);
      note(s.price === '₱18,500.00', `${tag}: price is the real price (${s.price})`);
      note(s.rows.join('|') === 'Material=Narra|Finish=Natural varnish' && s.sizeShown && s.dims.length === 3, `${tag}: Material, Finish and Size shown (${s.rows.join(', ')}; ${s.dims.join(' / ')})`);
      note(s.badge === 'Best seller', `${tag}: Best seller badge in the Quick view (${s.badge})`);
      note(s.arrows && s.pos === '1 / 3', `${tag}: arrows and position shown (${s.pos})`);
      note(s.buttons.filter((b) => b !== 'cxPrev' && b !== 'cxNext').join('|') === 'cxClose|cxMore', `${tag}: only X and "View full details" besides the photo arrows (${s.buttons.join(', ')})`);
      note(!s.sheetOverflow && !s.paneOverflowX, `${tag}: nothing overflows sideways inside the Quick view`);
      const geo = await evalJs(`(() => { const r = (e) => e.getBoundingClientRect(); const x = r(document.getElementById('cxClose')); const pr = r(document.querySelector('.cx-price')); const h2 = document.getElementById('cxName'); const rg = document.createRange(); rg.selectNodeContents(h2); const t = rg.getBoundingClientRect();
        const hit = (a, b) => a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top; const vh = innerHeight; const q = r(document.getElementById('cxQd'));
        const k = document.getElementById('cxCat'); const rk = document.createRange(); rk.selectNodeContents(k); const kr = rk.getBoundingClientRect();
        return { xOverlapsPrice: hit(x, pr), xOverlapsName: hit(x, t), xOverlapsCat: kr.width > 0 && hit(x, kr), closeSize: [x.width, x.height], sheetBottomOk: q.bottom <= vh + 1 && q.top >= -1, linkH: r(document.getElementById('cxMore')).height,
          photoInside: (() => { const p = r(document.getElementById('cxPh')); return p.left >= q.left && p.right <= q.right && p.top >= q.top && p.bottom <= q.bottom; })() }; })()`);
      note(!geo.xOverlapsPrice && !geo.xOverlapsName && !geo.xOverlapsCat, `${tag}: close X does not overlap price, name or category`);
      note(geo.closeSize[0] >= 44 && geo.closeSize[1] >= 44 && geo.linkH >= 44, `${tag}: X ${geo.closeSize.join('x')} and View full details ${Math.round(geo.linkH)}px are at least 44px`);
      note(geo.sheetBottomOk && geo.photoInside, `${tag}: panel fits the screen and the photo box fits the panel`);
      await shot(`quickview-${tag}`);
      // arrows
      const before = s.img;
      await mouse(...(await evalJs(`(() => { const r = document.getElementById('cxNext').getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; })()`)));
      await sleep(500); s = await state();
      note(s.pos === '2 / 3' && s.img !== before, `${tag}: Next moves to photo 2 / 3 (${s.pos})`);
      await mouse(...(await evalJs(`(() => { const r = document.getElementById('cxPrev').getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; })()`)));
      await sleep(500); await mouse(...(await evalJs(`(() => { const r = document.getElementById('cxPrev').getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; })()`)));
      await sleep(500); s = await state();
      note(s.pos === '3 / 3', `${tag}: Previous wraps from 1 to 3 / 3 (${s.pos})`);
      await key('ArrowRight'); await sleep(450); s = await state();
      note(s.pos === '1 / 3', `${tag}: ArrowRight key moves on (${s.pos})`);
      // focus trap
      let escaped = 0;
      for (let i = 0; i < 12; i++) { await key('Tab'); if (!(await state()).focusInside) escaped++; }
      for (let i = 0; i < 12; i++) { await key('Tab', true); if (!(await state()).focusInside) escaped++; }
      note(escaped === 0, `${tag}: Tab / Shift+Tab never leave the dialog (24 presses)`);
      // close by X
      await mouse(...(await evalJs(`(() => { const r = document.getElementById('cxClose').getBoundingClientRect(); return [r.left + r.width / 2, r.top + r.height / 2]; })()`)));
      await sleep(250); s = await state();
      note(!s.open && !s.modalOpenClass && s.navVisible && s.bodyOverflow !== 'hidden', `${tag}: X closes it; nav back; scroll unlocked`);
      note(await openerFocused(a.id), `${tag}: focus returned to the card's Quick view button`);

      // ---- B: keyboard open (Enter), Escape closes ----
      await evalJs(`[...document.querySelectorAll('button[data-quick-view]')].find((x) => x.dataset.name === 'Executive Office Chair').focus()`);
      await key('Enter'); const okB = await waitReady(); s = await state();
      note(okB && s.open && s.focusInside, `${tag}: Enter on the focused button opens it`);
      note(!s.arrows && s.pos === null, `${tag}: single-photo product shows no arrows and no counter`);
      note(s.price === '₱7,200.00', `${tag}: single-photo price (${s.price})`);
      await key('Escape'); await sleep(250); s = await state();
      note(!s.open && s.navVisible, `${tag}: Escape closes it`);
      note(await openerFocused((await btnRect('Executive Office Chair')).id), `${tag}: focus returned after Escape`);

      // ---- C: backdrop closes ----
      const c = await openByClick('Executive Office Chair');
      await mouse(4, 4); await sleep(250); s = await state();
      note(c.ok && !s.open, `${tag}: clicking the backdrop closes it`);

      // ---- D: empty rows hidden; no photo shows the placeholder; sale price ----
      const d = await openByClick('Sofa Frame'); s = await state();
      note(d.ok && s.rows.length === 0 && !s.infoShown && !s.sizeShown && !s.descShown, `${tag}: product with no material/finish/size/description hides those rows (rows=${s.rows.length}, size=${s.sizeShown}, desc=${s.descShown})`);
      await key('Escape'); await sleep(200);
      const e = await openByClick('Photoless Stool (no photo)'); s = await state();
      note(e.ok && /placeholder\.jpg/.test(s.img) && !s.arrows, `${tag}: no-photo product uses the site placeholder (${s.img && s.img.split('/').pop()})`);
      await key('Escape'); await sleep(200);
      const f = await openByClick('Rocking Chair'); s = await state();
      note(f.ok && s.price === '₱4,500.00', `${tag}: sale price shown large (${s.price})`);
      await key('Escape'); await sleep(200);

      // ---- E: failure shows a plain message and Retry, then Retry works ----
      await evalJs(`window.__f = window.fetch; window.fetch = () => Promise.reject(new Error('offline'))`);
      const g = await openByClick('King Bed Frame').catch(() => ({ ok: false }));
      await waitReady('error'); s = await state();
      const err = await evalJs(`({ shown: getComputedStyle(document.querySelector('.cx-state-error')).display !== 'none', text: document.querySelector('.cx-state-error p').textContent, retryFocused: document.activeElement.id })`);
      note(s.state === 'error' && err.shown && /try again/i.test(err.text), `${tag}: failed load shows a plain message ("${err.text.slice(0, 40)}...") with Retry`);
      if (who === 'user' && w === 390) await shot(`quickview-error-${tag}`);
      await evalJs(`window.fetch = window.__f`);
      await evalJs(`document.getElementById('cxRetry').click()`);
      const okRetry = await waitReady(); s = await state();
      note(okRetry && s.name === 'King Bed Frame' && s.price === '₱28,000.00', `${tag}: Retry loads the piece (${s.price})`);
      await key('Escape'); await sleep(200);

      // ---- F: glass over a light photo and a dark photo (contrast from real pixels) ----
      if (who === 'user' && (w === 1440 || w === 390)) {
        await openByClick('Pale Oak Bench'); await sleep(300);
        const light = await contrastOf(QV_TEXT, 'light-photo');
        await shot(`quickview-light-photo-${tag}`);
        await evalJs(`document.getElementById('cxNext').click()`); await sleep(600);
        const dark1 = await contrastOf(QV_TEXT, 'dark-photo-2nd');
        await key('Escape'); await sleep(200);
        await openByClick('Midnight Console'); await sleep(300);
        const dark = await contrastOf(QV_TEXT, 'dark-photo');
        await shot(`quickview-dark-photo-${tag}`);
        const all = [...light, ...dark1, ...dark];
        report.modalContrast.push({ tag, min: Math.min(...all.map((i) => i.ratio)), rows: all });
        const worst = all.reduce((a2, b2) => (b2.ratio < a2.ratio ? b2 : a2));
        note(all.length > 10 && worst.ratio >= 4.5, `${tag}: Quick view text over light and dark photos, worst ${worst.ratio}:1 ("${worst.text}", ${worst.name})`);
        // buttons inside the open modal
        const mb = await evalJs(BUTTONS);
        report.buttonContrast.push({ tag: tag + '-modal', count: mb.count, failures: mb.failures });
        note(!mb.failures.length, `${tag}: measure.js on the open Quick view: ${mb.count} buttons, ${mb.failures.length} failures`);
        await key('Escape'); await sleep(200);
        // price tag and card text, from pixels, on the page
        await evalJs(`window.scrollTo(0, 0)`); await sleep(700);
        const cardText = await contrastOf(['.lp-pcard__price', '.lp-pcard__title', '.lp-pcard__cat', '.lp-pcard__desc', '.cx-count', '.cx-lede', '.cx-cats a', '.lp-pcard__badge', '.cx-field > span', '.lp-pcard__btn'], 'card');
        report.cardContrast = report.cardContrast || [];
        report.cardContrast.push({ tag, min: Math.min(...cardText.map((i) => i.ratio)), rows: cardText });
        const wc = cardText.reduce((a2, b2) => (b2.ratio < a2.ratio ? b2 : a2));
        note(wc.ratio >= 4.5, `${tag}: catalog text incl. price tag worst ${wc.ratio}:1 ("${wc.text}", ${wc.name})`);
        const tagOnly = cardText.filter((i) => /₱/.test(i.text));
        report.priceTagMin = Math.min(report.priceTagMin || 99, ...tagOnly.map((i) => i.ratio));
        const pb = await evalJs(BUTTONS);
        report.buttonContrast.push({ tag: tag + '-page', count: pb.count, failures: pb.failures });
        note(!pb.failures.length, `${tag}: measure.js on the catalog page: ${pb.count} buttons, ${pb.failures.length} failures`);
      }

      // ---- G: filters + sort work with plain links (no JS needed) ----
      if (w === 1440) {
        await go('/catalog?category=Cabinets&sort=price_desc');
        const names = await evalJs(`[...document.querySelectorAll('.lp-pcard__title')].map((x) => x.textContent.trim())`);
        note(names.join('|') === '4-Door Wardrobe Cabinet|TV Console Cabinet', `${tag}: category + sort high-to-low (${names.join(', ')})`);
        await go('/catalog?search=zzzz');
        const emp = await evalJs(`({ t: document.querySelector('.cx-empty').textContent.replace(/\\s+/g, ' ').trim(), clear: !!document.querySelector('.cx-empty a[href$="/catalog"]') })`);
        note(emp.clear && /Nothing matches/.test(emp.t), `${tag}: empty result explains itself and offers Clear filters`);
        await shot(`catalog-empty-${tag}`);
      }

      const errs = consoleErrors.filter((x) => !IGNORE.test(x));
      note(errs.length === 0, `${tag}: no console errors${errs.length ? ' -> ' + errs.slice(0, 3).join(' | ') : ''}`);
      run.consoleErrors = errs; run.ignoredConsole = consoleErrors.length - errs.length;
      report.runs.push(run);
    }
  }

  report.problems = bad;
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(report, null, 2));
  console.log(`\n${bad.length ? bad.length + ' CHECK(S) NEED ATTENTION' : 'ALL CHECKS PASSED'}`);
  chrome.kill();
  process.exit(bad.length ? 1 : 0);
}
main().catch((e) => { console.error(e); process.exit(2); });
