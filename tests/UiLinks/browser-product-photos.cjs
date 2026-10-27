/*
 * Product photos browser walk (SCRATCH DATABASE ONLY). Admin product list, Edit product, catalog, Quick view, cart, a product with
 * no photo and an old order, at 1920x1080, 1440x900, 1366x768, 820x1180 and 390x844. Checks every <img> on the page actually loaded
 * from /storage/product-photos (or is the placeholder where there is no photo), no console errors, no sideways scroll.
 * Screenshots go to docs/product-photos-screenshots/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_product_photos.php
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-product-photos.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'product-photos-screenshots');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const c = await lib.start(9391);
  const ids = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'product-photos-walk.json'), 'utf8'));
  const adminEmail = execFileSync('php', ['-r', "$l = json_decode(file_get_contents('storage/app/screenshot-login.json'), true); echo $l['admin_email'];"], { cwd: lib.ROOT, encoding: 'utf8' });
  const mint = (email, guard) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, guard], { cwd: lib.ROOT, encoding: 'utf8' }));
  const customerEmail = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8')).customer_email;

  const problems = [];
  const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) problems.push(msg); };
  // collect console.error / uncaught errors / failed resources on every page
  await c.send('Page.addScriptToEvaluateOnNewDocument', { source: `window.__errs = [];
    window.addEventListener('error', (e) => window.__errs.push((e.target && e.target.src) ? (e.target.getAttribute('src') ? 'resource: ' + e.target.src : 'ignored: empty src') : 'error: ' + e.message), true);
    const ce = console.error; console.error = (...a) => { window.__errs.push('console.error: ' + a.join(' ')); ce.apply(console, a); };` });
  const pageErrors = () => c.evalJs('window.__errs || []');

  const imgReport = () => c.evalJs(`(async () => {
    for (let y = 0; y < document.body.scrollHeight; y += 400) { window.scrollTo(0, y); await new Promise((r) => setTimeout(r, 80)); }
    window.scrollTo(0, 0);
    await new Promise((r) => setTimeout(r, 900));
    const imgs = [...document.querySelectorAll('img')].filter((i) => i.getClientRects().length && i.closest('main, .cx-floor, table, .cart-page, .order-page, body'));
    return imgs.map((i) => ({ src: i.currentSrc || i.src, ok: i.complete && i.naturalWidth > 0, alt: i.alt }));
  })()`);
  const noScroll = () => c.evalJs('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1');

  const sizes = [[1920, 1080], [1440, 900], [1366, 768], [820, 1180], [390, 844]];
  const pages = [
    { name: 'admin-product-list', guard: 'admin', url: '/admin/products', expectManaged: true },
    { name: 'admin-edit-product', guard: 'admin', url: `/admin/products/${ids.narra_id}/edit`, expectManaged: true },
    { name: 'admin-edit-no-photo', guard: 'admin', url: `/admin/products/${ids.none_id}/edit`, expectPlaceholder: true },
    { name: 'catalog', url: '/catalog', expectManaged: true, expectPlaceholder: true },
    { name: 'cart', guard: 'web', url: '/cart', expectManaged: true, expectPlaceholder: true },
    { name: 'product-no-photo', url: `/product/${ids.none_id}`, expectPlaceholder: true },
    { name: 'old-order', guard: 'web', url: `/orders/${ids.order_id}`, expectLegacy: true },
  ];

  for (const [w, h] of sizes) {
    await c.viewport(w, h);
    for (const p of pages) {
      await c.setCookie(p.guard === 'admin' ? mint(adminEmail, 'admin') : p.guard === 'web' ? mint(customerEmail, 'web') : null);
      await c.go(p.url);
      const imgs = await imgReport();
      const tag = `${p.name} @${w}x${h}`;
      const managed = imgs.filter((i) => i.src.includes('/storage/product-photos/'));
      const placeholder = imgs.filter((i) => i.src.includes('/images/products/placeholder.jpg'));
      if (p.expectManaged) note(managed.length > 0 && managed.every((i) => i.ok), `${tag}: ${managed.length} photo(s) from /storage/product-photos, all loaded`);
      if (p.expectPlaceholder) note(placeholder.length > 0 && placeholder.every((i) => i.ok), `${tag}: placeholder shown and loaded (${placeholder.length})`);
      if (p.expectLegacy) note(imgs.some((i) => i.src.includes('/images/products/wardrobe-cabinet.jpg') && i.ok), `${tag}: old order snapshot image still loads`);
      note(await noScroll(), `${tag}: no sideways scroll`);
      const errs = (await pageErrors()).filter((e) => !/^ignored|fonts\.(googleapis|gstatic)|recaptcha|gstatic\.com|net::ERR/i.test(e));
      note(errs.length === 0, `${tag}: no console errors` + (errs.length ? ' ' + JSON.stringify(errs) : ''));
      await c.shot(`${p.name}-${w}x${h}`.replace(/^/, ''));
      fs.renameSync(path.join(lib.OUT, `${p.name}-${w}x${h}.png`), path.join(OUT, `${p.name}-${w}x${h}.png`));
    }

    // Quick view (catalog, guest)
    await c.setCookie(null);
    await c.go('/catalog');
    const opened = await c.evalJs(`(async () => { const b = [...document.querySelectorAll('[data-quick-view]')].find((x) => x.dataset.name === 'Narra Dining Table'); if (!b) return false; b.scrollIntoView({ block: 'center' }); b.click();
      for (let i = 0; i < 40; i++) { if (document.getElementById('cxQd').dataset.state === 'ready') break; await new Promise((r) => setTimeout(r, 100)); }
      await new Promise((r) => setTimeout(r, 600));
      const im = document.getElementById('cxImg'); return { src: im.getAttribute('src'), loaded: im.naturalWidth > 0, pos: document.getElementById('cxPos').textContent }; })()`);
    note(opened && opened.loaded && opened.src.includes('/storage/product-photos/'), `quick-view @${w}x${h}: photo from product-photos, loaded (${opened && opened.pos})`);
    await c.shot(`quick-view-${w}x${h}`);
    fs.renameSync(path.join(lib.OUT, `quick-view-${w}x${h}.png`), path.join(OUT, `quick-view-${w}x${h}.png`));
  }

  c.close();
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify({ problems }, null, 2));
  console.log(problems.length ? `\n${problems.length} CHECK(S) NEED A LOOK` : '\nall checks ok');
  process.exit(problems.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
