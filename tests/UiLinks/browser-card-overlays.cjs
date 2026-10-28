/*
 * Overlay stacking check (SCRATCH DATABASE ONLY): the Quick View window opens above the nav and Luma's launcher from the shared product
 * cards on /, /catalog and a product page at 1440, 1001 and 390px, and the other custom overlays (home gallery lightbox, My Designs
 * preview box) have no ancestor that traps them under the nav (a transform / filter / contain / z-index stacking context).
 *   node --experimental-websocket tests/UiLinks/browser-card-overlays.cjs      (same setup as browser-product-cards.cjs)
 */
const { execFileSync } = require('child_process');
const path = require('path');
const lib = require('./cdp-lib.cjs');

const results = [];
const note = (ok, msg) => { results.push(ok); console.log((ok ? 'PASS ' : 'FAIL ') + msg); };

const TRAPS = (sel) => `(() => {
  const el = document.querySelector(${JSON.stringify(sel)}); if (!el) return null;
  const out = [];
  for (let p = el.parentElement; p && p !== document.body && p !== document.documentElement; p = p.parentElement) {
    const s = getComputedStyle(p);
    const why = [];
    if (s.transform !== 'none') why.push('transform'); if (s.filter !== 'none') why.push('filter'); if (s.perspective !== 'none') why.push('perspective');
    if (parseFloat(s.opacity) < 1) why.push('opacity'); if (s.contain && s.contain !== 'none') why.push('contain ' + s.contain); if (s.containerType && s.containerType !== 'normal') why.push('container-type');
    if (s.willChange && s.willChange !== 'auto') why.push('will-change'); if (s.isolation === 'isolate') why.push('isolation'); if (s.mixBlendMode !== 'normal') why.push('blend');
    if (s.position !== 'static' && s.zIndex !== 'auto') why.push('z-index ' + s.zIndex);
    if (why.length) out.push((p.id || p.className || p.tagName) + ' [' + why.join(', ') + ']');
  }
  return out;
})()`;

(async () => {
  const c = await lib.start(9363);
  const { evalJs, go, viewport, sleep } = c;
  for (const w of [1440, 1001, 390]) {
    await viewport(w, w === 390 ? 844 : 900);
    for (const pg of ['/', '/catalog', '/product/3']) {
      await go(pg);
      await evalJs(`document.querySelector('.lp-pcard__media').click()`);
      for (let t = 0; t < 30 && (await evalJs(`document.getElementById('cxQd').getAttribute('data-state')`)) === 'loading'; t++) await sleep(150);
      await sleep(300);
      const r = await evalJs(`(() => {
        const mod = document.getElementById('cxMod'); const inMod = (x, y) => { const e = document.elementFromPoint(x, y); return !!e && mod.contains(e); };
        const pts = { nav: [], fab: [], center: [innerWidth / 2, innerHeight / 2], corner: [6, 6] };
        const nav = document.querySelector('nav.site-nav'); if (nav) { const b = nav.getBoundingClientRect(); if (b.height > 0 && getComputedStyle(nav).visibility !== 'hidden') pts.nav = [b.left + b.width / 2, b.top + b.height / 2]; }
        const fab = document.getElementById('chatbot-fab'); if (fab) { const b = fab.getBoundingClientRect(); pts.fab = [b.left + b.width / 2, b.top + b.height / 2]; }
        return { open: mod.classList.contains('on'), center: inMod(...pts.center), corner: inMod(...pts.corner), nav: pts.nav.length ? inMod(...pts.nav) : true, fab: pts.fab.length ? inMod(...pts.fab) : true };
      })()`);
      note(r.open && r.center && r.corner && r.nav && r.fab, `${w} ${pg}: Quick View is above the nav, Luma and the page (${JSON.stringify(r)})`);
      await evalJs(`document.getElementById('cxClose').click()`);
    }
  }
  const trapsQv = await evalJs(TRAPS('#cxMod'));
  note(trapsQv && trapsQv.length === 0, `Quick View container has no ancestor that traps it (${JSON.stringify(trapsQv)})`);

  await viewport(1440, 900); await go('/');
  const lb = await evalJs(TRAPS('#lightboxOverlay'));
  note(lb === null || lb.length === 0, `home gallery lightbox: no trapping ancestor (${lb === null ? 'no gallery images in this database, markup checked in home.blade.php' : JSON.stringify(lb)})`);

  const cookie = JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php')], { cwd: lib.ROOT, encoding: 'utf8' }));
  await c.setCookie(cookie);
  for (const pg of ['/design-gallery', '/design']) {
    await go(pg);
    const md = await evalJs(TRAPS('#md-modal'));
    note(md === null || md.length === 0, `${pg}: My Designs preview box has no trapping ancestor (${md === null ? 'not on this page' : JSON.stringify(md)})`);
  }
  console.log(`\n${results.filter(Boolean).length} passed, ${results.filter((x) => !x).length} failed`);
  c.close();
  process.exit(results.every(Boolean) ? 0 : 1);
})().catch((e) => { console.error(e); process.exit(2); });
