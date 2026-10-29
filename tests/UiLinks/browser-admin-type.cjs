/*
 * Admin typography walk (SCRATCH DATABASE ONLY). Screenshots the admin pages at 1440, 1001 and 390 px, measures horizontal overflow and
 * clipped text, arrows on buttons, computed fonts on three pages and text contrast, and writes a JSON report.
 *
 *   BASE=http://127.0.0.1:8011 TAG=after node tests/UiLinks/browser-admin-type.cjs      (current tree)
 *   BASE=http://127.0.0.1:8012 TAG=before node tests/UiLinks/browser-admin-type.cjs     (baseline worktree of commit 13c54f6)
 *
 * Screenshots: docs/admin-type-screenshots/<tag>/<page>-<width>.png, report: docs/admin-type-screenshots/<tag>/report.json.
 * The admin session is minted by tests/UiLinks/session-cookie-for.php (the real sign-in form is protected by reCAPTCHA).
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

const TAG = process.env.TAG || 'after';
const OUT = path.join(ROOT, 'docs', 'admin-type-screenshots', TAG);
const ADMIN = process.env.ADMIN_EMAIL || 'ot-admin@example.test';
const PAGES = [
  ['login', '/admin/login', false], ['dashboard', '/admin/dashboard'], ['orders', '/admin/orders'], ['order-view', '/admin/orders/1'],
  ['products', '/admin/products'], ['add-product', '/admin/products/create'], ['customers', '/admin/customers'],
  ['custom-orders', '/admin/custom-orders'], ['custom-order-view', '/admin/custom-orders/1'], ['service-request', '/admin/service-inquiries/1'],
  ['refunds', '/admin/refund-requests'], ['notifications', '/admin/notifications'], ['contact-settings', '/admin/settings/contact'],
  ['luma-unanswered', '/admin/chatbot'], ['system-health', '/admin/system-health'],
];
const WIDTHS = [1440, 1001, 390];

const MEASURE = `(() => {
  const out = { overflowX: document.documentElement.scrollWidth - innerWidth, clipped: [], wrapped: [], offscreen: [], arrows: [], lowContrast: [] };
  const vis = (e) => { const r = e.getBoundingClientRect(); const s = getComputedStyle(e); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const label = (e) => (e.tagName.toLowerCase() + (e.className && typeof e.className === 'string' ? '.' + e.className.trim().split(/\\s+/).slice(0, 2).join('.') : '') + ' "' + (e.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 40) + '"');
  const inScroller = (e) => { for (let p = e.parentElement; p; p = p.parentElement) { const o = getComputedStyle(p).overflowX; if ((o === 'auto' || o === 'scroll') && p.scrollWidth > p.clientWidth) return true; } return false; };
  // text that does not fit its own box (ellipsis or overflow:hidden) on the elements that matter
  const sel = 'button, a.admin-btn, a.lp-btn, .admin-btn, .lp-btn, .admin-pill, .badge, .co-chip, .idr-pill, .tag, .admin-filter-tab, .admin-nav-link, th, td, label, legend, summary, h1, h2, h3, .admin-panel-title, .admin-stat-label, .admin-topbar-title, .co-count, .admin-nav-group-label';
  document.querySelectorAll(sel).forEach((e) => {
    if (!vis(e) || e.closest('svg')) return;
    const s = getComputedStyle(e);
    if (e.scrollWidth > e.clientWidth + 1 && (s.overflowX === 'hidden' || s.textOverflow === 'ellipsis' || s.overflowX === 'clip')) out.clipped.push(label(e));
    if (e.matches('button, a.admin-btn, a.lp-btn, .admin-btn, .lp-btn, .admin-pill, .badge, .co-chip, .admin-filter-tab, .admin-nav-link')) {
      const lh = parseFloat(s.lineHeight) || parseFloat(s.fontSize) * 1.3;
      const textH = e.getBoundingClientRect().height - parseFloat(s.paddingTop) - parseFloat(s.paddingBottom) - parseFloat(s.borderTopWidth) - parseFloat(s.borderBottomWidth);
      if (textH > lh * 1.6 && (e.textContent || '').trim().length > 0) out.wrapped.push(label(e));
    }
  });
  document.querySelectorAll('body *').forEach((e) => {
    if (!vis(e) || inScroller(e) || e.closest('svg') || ['SCRIPT', 'STYLE', 'HTML', 'BODY'].includes(e.tagName)) return;
    const r = e.getBoundingClientRect();
    if (r.right > innerWidth + 1 && r.left < innerWidth && (e.textContent || '').trim()) out.offscreen.push(label(e));
  });
  document.querySelectorAll('button, a.admin-btn, a.lp-btn, .admin-btn, .lp-btn, .admin-filter-tab, .admin-nav-link, .al-back, a.lp-link').forEach((e) => {
    if (/[←→»«›‹➜➔]/.test(e.textContent)) out.arrows.push(label(e));
    else if ([...e.querySelectorAll('svg path, svg polyline')].some((p) => /M5 ?12h14|M19 ?12H5|M9 ?18l6-6-6-6|M15 ?18l-6-6 6-6|M10 ?6l6 6-6 6|M14 ?6l-6 6 6 6|^9 18 15 12 9 6$|^15 18 9 12 15 6$/.test(p.getAttribute('d') || p.getAttribute('points') || ''))) out.arrows.push(label(e) + ' [icon]');
  });
  // text contrast (flat backgrounds only; gradients and images are skipped)
  const parse = (c) => { const m = c.match(/rgba?\\(([^)]+)\\)/); if (!m) return null; const p = m[1].split(',').map(Number); return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 }; };
  const lum = (c) => { const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const over = (top, bot) => ({ r: top.r * top.a + bot.r * (1 - top.a), g: top.g * top.a + bot.g * (1 - top.a), b: top.b * top.a + bot.b * (1 - top.a), a: 1 });
  const bgOf = (e) => { const stack = []; for (let p = e; p; p = p.parentElement) { const s = getComputedStyle(p); if (s.backgroundImage !== 'none') return null; const c = parse(s.backgroundColor); if (c && c.a > 0) { stack.push(c); if (c.a >= 1) break; } } let base = { r: 255, g: 255, b: 255, a: 1 }; for (let i = stack.length - 1; i >= 0; i--) base = over(stack[i], base); return base; };
  const seen = new Set();
  document.querySelectorAll('body *').forEach((e) => {
    if (!vis(e) || ['SCRIPT', 'STYLE'].includes(e.tagName) || e.closest('svg')) return;
    const own = [...e.childNodes].filter((n) => n.nodeType === 3 && n.textContent.trim()).map((n) => n.textContent.trim()).join(' ');
    if (!own || (e.disabled)) return;
    const s = getComputedStyle(e);
    const fg = parse(s.color); const bg = bgOf(e); if (!fg || !bg) return;
    const pulsing = s.animationName && s.animationName !== 'none';   // the count badges pulse 100% to 60%; judge them at rest
    const f = over({ ...fg, a: fg.a * (pulsing ? 1 : (parseFloat(s.opacity) || 1)) }, bg);
    const l1 = lum(f), l2 = lum(bg); const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    const px = parseFloat(s.fontSize), bold = parseInt(s.fontWeight, 10) >= 700;
    const need = (px >= 24 || (px >= 18.66 && bold)) ? 3 : 4.5;
    if (ratio < need) { const k = label(e) + '|' + s.color + '|' + s.backgroundColor; if (!seen.has(k)) { seen.add(k); out.lowContrast.push({ el: label(e), ratio: Math.round(ratio * 100) / 100, need, color: s.color }); } }
  });
  return out;
})()`;

const STYLES = `(() => {
  const pick = (q) => { const e = document.querySelector(q); if (!e) return null; const s = getComputedStyle(e); return { family: s.fontFamily.split(',')[0].replace(/["']/g, ''), size: s.fontSize, weight: s.fontWeight, numeric: s.fontVariantNumeric, text: (e.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 30) }; };
  return { pageTitle: pick('.admin-topbar-title'), tableHeader: pick('.admin-table th'), tableCell: pick('.admin-table td'), number: pick('.admin-stat-value, .admin-table td[class*=num], td[data-label=Total], td[data-label=Amount]'),
           button: pick('.admin-btn, .lp-btn'), formLabel: pick('label'), sidebarItem: pick('.admin-nav-link'), panelTitle: pick('.admin-panel-title') };
})()`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const cookie = JSON.parse(execSync(`php "${path.join(__dirname, 'session-cookie-for.php')}" ${ADMIN} admin`, { cwd: ROOT }).toString());
  const b = await start(9360 + (TAG === 'before' ? 1 : 0));
  const report = { tag: TAG, base: b.BASE, pages: {}, styles: {} };
  const measure = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
  for (const [name, url, auth = true] of PAGES) {
    await b.setCookie(auth ? cookie : null);
    for (const w of WIDTHS) {
      await b.viewport(w, 900);
      await b.go(url);
      const h = await b.evalJs('Math.max(document.documentElement.scrollHeight, 900)');
      await b.send('Emulation.setDeviceMetricsOverride', { width: w, height: Math.min(h, 4000), deviceScaleFactor: 1, mobile: w < 700 });
      await sleep(250);
      const r = await b.send('Page.captureScreenshot', { format: 'png' });
      fs.writeFileSync(path.join(OUT, `${name}-${w}.png`), Buffer.from(r.data, 'base64'));
      const m = await b.evalJs(MEASURE);
      let btn = null;
      if (w === 1440) { try { const c = await b.evalJs(`(${measure.replace(/;\s*$/, '')})`); btn = c && { count: c.count, failures: (c.failures || []).slice(0, 8) }; } catch (e) { btn = null; } }
      report.pages[`${name}@${w}`] = { url, ...m, buttonContrast: btn };
      if (w === 1440 && ['orders', 'add-product', 'order-view'].includes(name)) { report.styles[name] = await b.evalJs(STYLES); }
    }
  }
  fs.writeFileSync(path.join(OUT, 'report.json'), JSON.stringify(report, null, 1));
  const sum = Object.entries(report.pages).map(([k, v]) => `${k.padEnd(26)} overflowX=${v.overflowX} clipped=${v.clipped.length} wrapped=${v.wrapped.length} offscreen=${v.offscreen.length} arrows=${v.arrows.length} lowContrast=${v.lowContrast.length}`);
  console.log(sum.join('\n'));
  b.close();
})().catch((e) => { console.error(e); process.exit(1); });
