/*
 * Customer typography walk (docs/fonts/typography-specimen-v2.html), SCRATCH DATABASE ONLY, http://127.0.0.1:8011.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   php tests/UiLinks/seed_order_tracking.php
 *   php tests/UiLinks/seed_customer_type.php            (a cart line for the demo customer)
 *   powershell tests/Rbac/serve_test.ps1                 (scratch server on :8011)
 *
 *   node --experimental-websocket tests/UiLinks/browser-customer-type.cjs customer
 *   node --experimental-websocket tests/UiLinks/browser-customer-type.cjs admin before   (three admin pages, hash of the pixels)
 *   node --experimental-websocket tests/UiLinks/browser-customer-type.cjs admin after    (then compares with "before")
 *
 * customer: screenshots at 1440 and 390 into docs/fonts/screenshots-customer-type, no horizontal scroll, fonts really applied,
 * peso sign drawn, no Google request, font bytes loaded, back buttons >= 44px, contrast of every button (tests/ButtonContrast/measure.js).
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'fonts', 'screenshots-customer-type');
fs.mkdirSync(OUT, { recursive: true });
const MEASURE = fs.readFileSync(path.join(lib.ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const cookieFor = (email, guard) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, guard], { cwd: lib.ROOT, encoding: 'utf8' }));
const mode = process.argv[2] || 'customer';
const report = { checks: [], pass: 0, fail: 0, fonts: {} };
const ok = (name, cond, detail) => { report.checks.push({ name, ok: !!cond, detail }); cond ? report.pass++ : report.fail++; console.log(cond ? 'PASS' : 'FAIL', name, cond || detail === undefined ? '' : JSON.stringify(detail)); };

(async () => {
  const c = await lib.start(9300 + Math.floor(Math.random() * 500));
  const E = (x) => c.evalJs(x);
  const snap = async (name, full = true) => {
    const r = await c.send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: full });
    const buf = Buffer.from(r.data, 'base64');
    fs.writeFileSync(path.join(OUT, name + '.png'), buf);
    return crypto.createHash('sha256').update(buf).digest('hex');
  };

  try {
    if (mode === 'admin') {
      const phase = process.argv[3] || 'after';
      const hashes = {};
      await c.viewport(1440, 900);
      await c.setCookie(cookieFor('ot-admin@example.test', 'admin'));
      for (const [slug, url] of [['dashboard', '/admin/dashboard'], ['orders', '/admin/orders'], ['order-track', '/admin/orders/1']]) {
        await c.go(url);
        await c.sleep(600);
        hashes[slug] = await snap(`admin-${slug}-${phase}`);
        const fam = await E(`getComputedStyle(document.body).fontFamily`);
        console.log(slug, phase, hashes[slug].slice(0, 12), fam);
        ok(`admin ${slug} (${phase}) still uses DM Sans`, /DM Sans/.test(fam), fam);
      }
      const file = path.join(OUT, '_admin-hashes.json');
      const all = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
      all[phase] = hashes;
      fs.writeFileSync(file, JSON.stringify(all, null, 1));
      if (all.before && all.after) for (const k of Object.keys(all.before)) ok(`admin ${k}: pixel-identical before and after`, all.before[k] === all.after[k]);
    } else {
      const pages = [
        ['home', '/', null], ['catalog', '/catalog', null], ['product', '/product/1', null], ['terms', '/terms', null],
        ['login', '/login', null], ['register', '/register', null],
        ['cart', '/cart', 'c'], ['checkout', '/checkout', 'c'], ['orders', '/orders', 'c'], ['order-tracking', '/orders/1', 'c'],
        ['order-tracking-2', '/orders/3', 'c'], ['order-success', '/payment/confirmed/1', 'c'], ['pay-stripe', '/payment/stripe/2', 'c'],
        ['account', '/profile', 'c'], ['customize', '/design', 'c'],
      ];
      const cust = cookieFor('ot-customer@example.test', 'web');
      for (const [w, h] of [[1440, 900], [390, 844]]) {
        await c.viewport(w, h);
        for (const [slug, url, auth] of pages) {
          await c.setCookie(auth ? cust : null);
          await c.go(url);
          await c.sleep(900);
          if (slug === 'checkout' && w < 700) {
            // the phone wizard: open each step in turn so every back button is seen
            for (const [from, to] of [[null, 'review'], ['review', 'delivery'], ['delivery', 'paytype'], ['paytype', 'paymethod'], ['paymethod', 'notes'], ['notes', 'finalreview']]) {
              await E(`goToScreen(${JSON.stringify(from)}, ${JSON.stringify(to)})`);
              await c.sleep(450);
              await snap(`checkout-step-${to}-${w}`, false);
              const bb = await E(`(() => { const e = document.querySelector('#screen-${to} .lp-btn--back'); if (!e) return null; const r = e.getBoundingClientRect(); return { h: Math.round(r.height), w: Math.round(r.width), fits: r.right <= innerWidth && r.left >= 0 }; })()`);
              ok(`checkout step ${to} ${w}: back button >= 44px and inside the screen`, bb && bb.h >= 44 && bb.fits, bb);
            }
            await E(`document.querySelectorAll('.checkout-screen.open').forEach(e => e.classList.remove('open')); document.body.style.overflow = ''`);
          }
          if (slug === 'checkout' && w >= 700) {
            for (const k of ['2', '3', '4', '5']) { /* desktop shows every section inline; one full-page shot covers them */ }
          }
          const hash = await snap(`${slug}-${w}`);
          const info = await E(`(() => {
            const cs = (s) => { const e = document.querySelector(s); return e ? getComputedStyle(e) : null; };
            const body = getComputedStyle(document.body);
            const h = cs('h1') || cs('h2');
            const btn = cs('.lp-btn:not(.lp-btn--chip):not(.lp-btn--icon)');
            const back = document.querySelector('.lp-btn--back');
            const price = cs('[class*="price"]');
            return {
              scroll: document.documentElement.scrollWidth <= innerWidth + 1,
              bodyClass: document.body.className,
              body: body.fontFamily.split(',')[0] + ' ' + body.fontWeight,
              h: h && (h.fontFamily.split(',')[0] + ' ' + h.fontWeight),
              btn: btn && (btn.fontFamily.split(',')[0] + ' ' + btn.fontWeight + ' ' + btn.fontSize),
              price: price && (price.fontFamily.split(',')[0] + ' ' + price.fontWeight + ' ' + price.fontVariantNumeric),
              back: back ? Math.round(back.getBoundingClientRect().height) : null,
              backVisible: back ? back.getBoundingClientRect().width > 0 : null,
              google: performance.getEntriesByType('resource').filter(r => /fonts.(googleapis|gstatic)/.test(r.name)).length,
            };
          })()`);
          console.log(slug, w, JSON.stringify(info));
          ok(`${slug} ${w}: no horizontal scroll`, info.scroll);
          if (/lp-type/.test(info.bodyClass)) {
            ok(`${slug} ${w}: Montserrat body`, /Montserrat/.test(info.body), info.body);
            if (info.h) ok(`${slug} ${w}: Cormorant headings`, /Cormorant/.test(info.h), info.h);
            ok(`${slug} ${w}: no Google font request`, info.google === 0);
            if (info.back != null && info.backVisible) ok(`${slug} ${w}: back button >= 44px`, info.back >= 44, info.back);
            const m = await E(`(() => { const r = (0, eval)(${JSON.stringify(MEASURE)}); return { count: r.count, failures: r.failures.map(f => f.text + ':' + f.min) }; })()`);
            ok(`${slug} ${w}: button contrast, ${m.count} buttons`, m.failures.length === 0, m.failures);
          }
        }
      }
      // font payload on a normal page (home, product, cart) as the browser fetched it
      for (const [slug, url] of [['home', '/'], ['product', '/product/1'], ['checkout', '/checkout']]) {
        await c.viewport(1440, 900);
        await c.setCookie(slug === 'checkout' ? cust : null);
        await c.go(url);
        await c.sleep(1200);
        const f = await E(`performance.getEntriesByType('resource').filter(r => /\\/fonts\\//.test(r.name)).map(r => [r.name.split('/').pop(), r.encodedBodySize || r.transferSize || r.decodedBodySize])`);
        report.fonts[slug] = { files: f, bytes: f.reduce((s, x) => s + x[1], 0) };
        console.log('fonts', slug, JSON.stringify(report.fonts[slug]));
      }
      // the peso sign comes out of the latin-ext file
      await c.go('/cart'); // cart needs a login
      await c.setCookie(cust); await c.go('/cart');
      ok('peso sign is drawn from Montserrat (latin-ext loaded)', await E(`document.fonts.check('500 16px Montserrat', '\\u20B1')`));
    }
  } catch (e) {
    console.log('ERROR', e.stack || e);
    report.fail++;
  } finally {
    c.close();
    console.log(`\n${report.pass} passed, ${report.fail} failed`);
    fs.writeFileSync(path.join(OUT, `_checks-${mode}${process.argv[3] ? '-' + process.argv[3] : ''}.json`), JSON.stringify(report, null, 1));
    process.exit(report.fail ? 1 : 0);
  }
})();
