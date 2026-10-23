/*
 * Hero panel sizes (SCRATCH DATABASE ONLY, http://127.0.0.1:8011): measures the AI Showcase panel and both rails at 4 sizes and
 * saves screenshots into docs/stage-hero-sizes-screenshots/. Seed first: php tests/UiLinks/seed_hero.php
 *   node --experimental-websocket tests/UiLinks/browser-hero-sizes.cjs
 */
const fs = require('fs');
const path = require('path');
const lib = require('./cdp-lib.cjs');

(async () => {
  const c = await lib.start(9352);
  const out = path.join(lib.ROOT, 'docs', 'stage-hero-sizes-screenshots');
  fs.mkdirSync(out, { recursive: true });
  const rows = [];
  for (const [w, h] of [[1900, 1080], [1366, 768], [1024, 768], [390, 844]]) {
    await c.viewport(w, h);
    await c.go('/');
    await c.sleep(1200);
    const m = await c.evalJs(`(() => {
      const r = (e) => { const b = e.getBoundingClientRect(); return { x: Math.round(b.left), y: Math.round(b.top), w: Math.round(b.width), h: Math.round(b.height) }; };
      const rails = [...document.querySelectorAll('.lp-rail')].map(r);
      return { ai: r(document.querySelector('.lp-ai')), best: rails[0], hot: rails[1], overflowX: document.documentElement.scrollWidth - innerWidth,
        luma: (() => { const f = document.querySelector('#chatbot-fab'); return f ? r(f) : null; })() };
    })()`);
    rows.push({ size: w + 'x' + h, ...m });
    const r = await c.send('Page.captureScreenshot', { format: 'png', ...(w < 1100 ? { captureBeyondViewport: true, clip: await c.evalJs(`(() => { const b = document.querySelector('.lp-hero').getBoundingClientRect(); return { x: 0, y: Math.round(b.top + scrollY), width: innerWidth, height: Math.round(b.height), scale: 1 }; })()`) } : {}) });
    fs.writeFileSync(path.join(out, `hero-${w}x${h}.png`), Buffer.from(r.data, 'base64'));
  }
  console.log(JSON.stringify(rows, null, 1));
  c.close();
})();
