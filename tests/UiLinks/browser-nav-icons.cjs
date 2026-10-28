/*
 * Navbar icon alignment (SCRATCH DATABASE ONLY, http://127.0.0.1:8011): for Assistant, My Orders, Cart and Notifications reports the vertical
 * offset between the centre of the icon's drawn shape and the centre of the text's x-height (baseline - xHeight/2); every offset must be <= 1px.
 * Also checks the icon box size and gap are identical. Runs at 1440 and 1001 (bar) and 390 (phone menu). Screenshots: docs/nav-icons/.
 *
 *   php tests/UiLinks/seed_order_tracking.php ; (scratch server on :8011) ; node --experimental-websocket tests/UiLinks/browser-nav-icons.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');
const OUT = path.join(lib.ROOT, 'docs', 'nav-icons');
fs.mkdirSync(OUT, { recursive: true });
let pass = 0, fail = 0;
const ok = (n, c, d) => { c ? pass++ : fail++; console.log(c ? 'PASS' : 'FAIL', n, c || d === undefined ? '' : JSON.stringify(d)); };

const MEASURE = `(() => {
  const links = [['Assistant', '#nav-chatbot-reactivate'], ['My Orders', 'a[href$="/orders"].sn-icon'], ['Cart', 'a[href$="/cart"].sn-icon'], ['Notifications', '#sn-bell']];
  const ctx = document.createElement('canvas').getContext('2d');
  return links.map(([name, sel]) => {
    const a = document.querySelector('nav.site-nav ' + sel);
    const svg = a.querySelector('svg');
    const label = [...a.querySelectorAll('span')].find(s => s.textContent.trim() === name) || a.querySelector('.sn-label');
    // drawn-shape centre (viewBox units -> pixels)
    const vb = svg.viewBox.baseVal, r = svg.getBoundingClientRect();
    let y0 = 1e9, y1 = -1e9;
    svg.querySelectorAll('path,circle').forEach(p => { const b = p.getBBox(); y0 = Math.min(y0, b.y); y1 = Math.max(y1, b.y + b.height); });
    const inkCenter = r.top + (((y0 + y1) / 2) - vb.y) / vb.height * r.height;
    // text x-height centre: baseline from a zero-size inline probe, x-height from the font
    const probe = document.createElement('span'); probe.style.cssText = 'display:inline-block;width:0;height:0;vertical-align:baseline';
    label.appendChild(probe); const baseline = probe.getBoundingClientRect().top; probe.remove();
    const cs = getComputedStyle(label); ctx.font = cs.fontWeight + ' ' + cs.fontSize + ' ' + cs.fontFamily;
    const m = ctx.measureText('x'); const xh = m.actualBoundingBoxAscent;
    const textCenter = baseline - xh / 2;
    const lr = label.getBoundingClientRect();
    return { name, w: Math.round(r.width * 10) / 10, h: Math.round(r.height * 10) / 10, gap: Math.round((lr.left - r.right) * 10) / 10, offset: Math.round((inkCenter - textCenter) * 100) / 100 };
  });
})()`;

(async () => {
  const c = await lib.start(9300 + Math.floor(Math.random() * 500));
  const E = (x) => c.evalJs(x);
  const cookie = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), 'ot-customer@example.test', 'web'], { cwd: lib.ROOT, encoding: 'utf8' }));
  const zoom = async (name, clip) => { const r = await c.send('Page.captureScreenshot', { format: 'png', clip: { ...clip, scale: 3 } }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  try {
    await c.setCookie(cookie);
    for (const [w, h, menu] of [[1440, 900, false], [1001, 800, false], [390, 844, true]]) {
      await c.viewport(w, h);
      await c.go('/catalog');
      await E(`window.scrollTo(0,0)`);
      if (menu) { await E(`document.querySelector('.sn-burger').click()`); await c.sleep(500); }
      const rows = await E(MEASURE);
      console.log(w, JSON.stringify(rows));
      rows.forEach((r) => ok(`${w}: ${r.name} icon centre is within 1px of the text x-height centre (${r.offset}px)`, Math.abs(r.offset) <= 1, r));
      ok(`${w}: all icons share one box size`, new Set(rows.map((r) => r.w + 'x' + r.h)).size === 1, rows);
      ok(`${w}: all icons share one gap to their word`, new Set(rows.map((r) => r.gap)).size === 1, rows);
      const box = await E(`(() => { const n = document.querySelector('nav.site-nav'); const b = (menu => menu ? document.querySelector('nav.site-nav .sn-links') : n)(${menu}).getBoundingClientRect(); return { x: Math.max(0, b.left), y: Math.max(0, b.top), width: Math.min(innerWidth, b.width), height: b.height }; })()`);
      await zoom(`nav-${w}`, box);
    }
  } finally { c.close(); console.log(`\n${pass} passed, ${fail} failed`); process.exit(fail ? 1 : 0); }
})();
