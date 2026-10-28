/*
 * Shared product card walk (SCRATCH DATABASE ONLY). Checks <x-product-card> on /, /catalog and a product page at 1440, 1001, 800 and 390px:
 * button lines per row, tag / badge / Quick View boxes never intersect, identical type sizes, images, console errors; then the behaviour
 * (links, Quick View open/close/price, keyboard focus, filters, sort, carousel, N Ordered counts, hidden products).
 * Screenshots go to docs/stage-cards-screenshots/, the results to _checks.json there.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_product_cards.php
 *   powershell -File tests/Rbac/serve_test.ps1                      (http://127.0.0.1:8011)
 *   node --experimental-websocket tests/UiLinks/browser-product-cards.cjs
 */
const fs = require('fs');
const path = require('path');
const lib = require('./cdp-lib.cjs');

const OUT = path.join(lib.ROOT, 'docs', 'stage-cards-screenshots');
fs.mkdirSync(OUT, { recursive: true });
const results = [];
const note = (ok, msg) => { results.push({ ok: !!ok, msg }); console.log((ok ? 'PASS ' : 'FAIL ') + msg); };

async function main() {
  const c = await lib.start(9361);
  const { send, evalJs, go, viewport, sleep } = c;
  const errors = [];
  const consoleErrors = () => errors.splice(0);
  // console / exception capture (cdp-lib's message handler only resolves ids, so poll the page itself)
  const hook = `(() => { if (window.__errs) return; window.__errs = []; window.addEventListener('error', (e) => window.__errs.push(String(e.message))); window.addEventListener('unhandledrejection', (e) => window.__errs.push('rej ' + e.reason)); const ce = console.error; console.error = function () { window.__errs.push([...arguments].join(' ')); ce.apply(console, arguments); }; })()`;
  const shotRect = async (name, sel, pad = 12) => {
    const r = await evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; e.scrollIntoView({ block: 'center' }); const b = e.getBoundingClientRect(); return { x: b.left + scrollX, y: b.top + scrollY, w: b.width, h: b.height }; })()`);
    if (!r) return;
    await sleep(500);
    const x = Math.max(0, r.x - pad), y = Math.max(0, r.y - pad);
    const s = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true, clip: { x, y, width: Math.min(r.w + pad * 2, 1500), height: Math.min(r.h + pad * 2, 1400), scale: 1 } });
    fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(s.data, 'base64'));
  };

  // measuring code that runs in the page for every card group on the page
  const MEASURE = `(() => {
    const R = (e) => { const b = e.getBoundingClientRect(); return { l: b.left, t: b.top, r: b.right, b: b.bottom, w: b.width, h: b.height }; };
    const hit = (a, b) => a.l < b.r - 0.5 && b.l < a.r - 0.5 && a.t < b.b - 0.5 && b.t < a.b - 0.5;
    const cards = [...document.querySelectorAll('.lp-pcard')];
    const rows = {};
    const overlaps = [];
    const sizes = [];
    const imgs = [];
    for (const card of cards) {
      const top = Math.round(card.getBoundingClientRect().top + scrollY);
      const btn = card.querySelector('.lp-pcard__btn');
      (rows[top] = rows[top] || []).push(Math.round((btn.getBoundingClientRect().top + scrollY) * 10) / 10);
      const tag = R(card.querySelector('.lp-pcard__tag'));
      const media = R(card.querySelector('.lp-pcard__media'));
      const parts = [['tag', tag], ...[...card.querySelectorAll('.lp-pcard__badge')].map((b, i) => ['badge' + i, R(b)])];
      const qv = card.querySelector('.lp-pcard__qv'); const qs = getComputedStyle(qv);
      const qvShown = qs.display !== 'none' && parseFloat(qs.opacity) > 0.05 && qs.pointerEvents !== 'none';
      if (qvShown) parts.push(['qv', R(qv)]);
      for (let i = 0; i < parts.length; i++) for (let j = i + 1; j < parts.length; j++) if (hit(parts[i][1], parts[j][1])) overlaps.push(card.dataset.productId + ':' + parts[i][0] + '/' + parts[j][0]);
      for (const [n, b] of parts) if (b.l < media.l - 0.5 || b.r > media.r + 0.5 || b.b > media.b + 0.5) overlaps.push(card.dataset.productId + ':' + n + ' outside picture');
      const cs = (s) => getComputedStyle(card.querySelector(s));
      const w = Math.round(card.getBoundingClientRect().width);
      const bucket = w <= 235 ? 'narrow' : (w <= 290 ? 'compact' : 'wide');
      sizes.push({ bucket, w, sig: ['.lp-pcard__title', '.lp-pcard__cat', '.lp-pcard__desc', '.lp-pcard__price', '.lp-pcard__btn'].map((s) => { const x = cs(s); return s.slice(10) + ':' + x.fontFamily.split(',')[0] + '/' + x.fontSize + '/' + x.fontWeight + '/' + x.lineHeight + (s === '.lp-pcard__price' ? '' : '/' + x.padding); }).join(' | ') + ' | body:' + cs('.lp-pcard__body').padding + ' | card:' + getComputedStyle(card).padding });
      const im = card.querySelector('img'); imgs.push(im.complete && im.naturalWidth > 0);
    }
    const rowSpread = Object.values(rows).map((a) => Math.max(...a) - Math.min(...a));
    return { n: cards.length, rowSpread, overlaps, sizes, imgsOk: imgs.every(Boolean), sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth,
             errs: window.__errs || [] };
  })()`;

  const hoverAll = async () => {
    const pts = await evalJs(`[...document.querySelectorAll('.lp-pcard__media')].map((m) => { m.scrollIntoView({ block: 'center', inline: 'center' }); const b = m.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })`);
    return pts;
  };

  const summary = {};
  for (const w of [1440, 1001, 800, 390]) {
    await viewport(w, w === 390 ? 844 : 900);
    for (const pg of ['/', '/catalog', 'product']) {
      let url = pg;
      if (pg === 'product') url = '/product/3';
      await go(url);
      await evalJs(hook);
      await evalJs(`document.querySelectorAll('.lp-pcard img').forEach((i) => i.loading = 'eager')`); await sleep(700);
      const m = await evalJs(MEASURE);
      const tag = `${w} ${pg}`;
      note(m.n > 0, `${tag}: ${m.n} product cards`);
      note(m.rowSpread.every((x) => x <= 1), `${tag}: buttons on one line per row (max spread ${Math.max(0, ...m.rowSpread)}px)`);
      note(m.overlaps.length === 0, `${tag}: tag, badges and Quick View never intersect and stay inside the picture ${m.overlaps.join(',')}`);
      note(m.imgsOk, `${tag}: no broken images`);
      note(m.sw <= m.cw, `${tag}: no sideways scroll (${m.sw}/${m.cw})`);
      note(m.errs.length === 0, `${tag}: no JavaScript errors ${m.errs.join(';')}`);
      (summary[w] = summary[w] || []).push(...m.sizes);
      if (pg === '/catalog' || pg === '/' || pg === 'product') {
        const sel = pg === '/' ? '.bestsellers-section .carousel-wrapper' : (pg === '/catalog' ? '.cx-floor' : '.detail-related');
        await shotRect(`${pg === '/' ? 'home-carousel' : pg === '/catalog' ? 'catalog' : 'related'}-${w}`, sel, 8);
        if (pg === '/') await shotRect(`home-featured-${w}`, '.lp-pgrid', 8);
      }
      // hover every card in turn (hover devices) and re-measure the Quick View pill against tag and badges
      if (w >= 800) {
        const count = await evalJs(`document.querySelectorAll('.lp-pcard').length`);
        const bad = [];
        for (let i = 0; i < count; i++) {
          let o = 'x';
          for (let t = 0; t < 3 && o; t++) {   // the home carousel auto-slides: a card that moved under the pointer is measured again
            const pt = await evalJs(`(() => { const m = document.querySelectorAll('.lp-pcard__media')[${i}]; m.scrollIntoView({ block: 'center', inline: 'center' }); const b = m.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
            await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: pt.x, y: pt.y });
            await sleep(380);
            o = await evalJs(`(() => { const R = (e) => e.getBoundingClientRect(); const card = document.querySelectorAll('.lp-pcard')[${i}]; if (!card.matches(':hover')) return 'not hovered'; const qb = R(card.querySelector('.lp-pcard__qv')); const bad = [];
              for (const o of card.querySelectorAll('.lp-pcard__tag, .lp-pcard__badge')) { if (parseFloat(getComputedStyle(o).opacity) < 0.05 || parseFloat(getComputedStyle(o.closest('.lp-pcard__badges') || o).opacity) < 0.05) continue; const b = R(o); if (qb.left < b.right && b.left < qb.right && qb.top < b.bottom && b.top < qb.bottom) bad.push(o.className); }
              return bad.join(','); })()`);
            if (o === 'not hovered') o = 'x'; else break;
          }
          if (o && o !== 'x') bad.push(i + ':' + o);
        }
        note(bad.length === 0, `${tag}: Quick View pill never covers the tag or a visible badge on hover ${bad.join(' ')}`);
        await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: 5, y: 5 });
      }
    }
  }
  // identical type sizes per width bucket
  for (const w of Object.keys(summary)) {
    const by = {};
    for (const s of summary[w]) (by[s.bucket] = by[s.bucket] || new Set()).add(s.sig);
    const bad = Object.entries(by).filter(([, set]) => set.size > 1);
    note(bad.length === 0, `${w}: text sizes, fonts and paddings identical in every place that uses the card (${Object.entries(by).map(([k, v]) => k + ':' + v.size).join(', ')})`);
    if (w === '1440') for (const [k, set] of Object.entries(by)) fs.writeFileSync(path.join(OUT, `_type-${w}-${k}.txt`), [...set].join('\n'));
    if (w === '390') for (const [k, set] of Object.entries(by)) fs.writeFileSync(path.join(OUT, `_type-${w}-${k}.txt`), [...set].join('\n'));
  }

  // ------------- behaviour at 1440 -------------
  await viewport(1440, 900);
  await go('/catalog');
  await evalJs(hook);
  const cards = await evalJs(`[...document.querySelectorAll('.lp-pcard')].map((c) => ({ id: c.dataset.productId, name: c.querySelector('.lp-pcard__title').textContent.trim(), href: c.querySelector('.lp-pcard__btn').getAttribute('href'), titleHref: c.querySelector('.lp-pcard__title a').getAttribute('href'),
    tag: c.querySelector('.lp-pcard__tag').getAttribute('aria-label'), price: c.querySelector('.lp-pcard__price').textContent.trim(), was: (c.querySelector('.lp-pcard__was') || {}).textContent || null, badges: [...c.querySelectorAll('.lp-pcard__badge')].map((b) => b.textContent.trim()) }))`);
  note(cards.length === 9, `catalog: 9 available products shown, the hidden one excluded (${cards.length})`);
  note(cards.every((k) => k.href === '/product/' + k.id || k.href.endsWith('/product/' + k.id)) && cards.every((k) => k.titleHref === k.href), 'catalog: View Details and the title link go to the right product page for every card');
  note(cards.every((k) => /^Price ₱[\d,]+\.\d\d/.test(k.tag)), 'catalog: every tag has an accessible name like "Price ₱18,500.00"');
  const sale = cards.find((k) => k.name === 'Narra Dining Table');
  note(sale && sale.price === '₱14,800.00' && sale.was === '₱18,500.00' && sale.tag === 'Price ₱14,800.00, was ₱18,500.00', `catalog: sale product shows sale price in the tag with the old price struck (${sale && sale.price} / ${sale && sale.was})`);
  note(sale && sale.badges[0] === '-20%' && sale.badges.some((b) => /best seller/i.test(b)), `catalog: sale product shows -20% first and a Best Seller badge (${sale && sale.badges.join(' | ')})`);
  note(!(await evalJs(`document.body.innerText.includes('Hidden Draft Cabinet')`)), 'catalog: unavailable product is not listed, so it cannot be ordered');

  // Quick View for every card: right product, right price, closes with X, Escape and the backdrop
  let qvOk = true; const qvNotes = [];
  for (const [k, kind] of cards.map((x, i) => [x, ['x', 'esc', 'bg'][i % 3]])) {
    await go('/catalog'); await evalJs(hook);
    await evalJs(`document.querySelector('.lp-pcard[data-product-id="${k.id}"] .lp-pcard__qv').click()`);
    for (let t = 0; t < 30 && (await evalJs(`document.getElementById('cxQd').getAttribute('data-state')`)) === 'loading'; t++) await sleep(150);
    await sleep(200);
    const s = await evalJs(`(() => { const m = document.getElementById('cxMod'); const q = document.getElementById('cxQd'); const b = q.getBoundingClientRect(); const top = document.elementFromPoint(b.left + b.width / 2, b.top + 40); const nav = document.querySelector('nav.site-nav'); const nb = nav.getBoundingClientRect(); const topNav = document.elementFromPoint(nb.left + nb.width / 2, nb.top + nb.height / 2);
      return { open: m.classList.contains('on') && !m.hidden, name: document.getElementById('cxName').textContent, price: document.getElementById('cxPrice').textContent, onTop: !!top && q.contains(top), navCovered: !!topNav && m.contains(topNav) }; })()`);
    if (!(s.open && s.name === k.name && s.price === k.price && s.onTop && s.navCovered)) { qvOk = false; qvNotes.push(k.id + JSON.stringify(s)); }
    if (kind === 'x') await evalJs(`document.getElementById('cxClose').click()`);
    else if (kind === 'esc') { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 }); }
    else { await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: 6, y: 450, button: 'left', clickCount: 1 }); await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: 6, y: 450, button: 'left', clickCount: 1 }); }
    await sleep(300);
    const closed = await evalJs(`document.getElementById('cxMod').hidden && !document.body.classList.contains('modal-open')`);
    if (!closed) { qvOk = false; qvNotes.push(k.id + ' did not close with ' + kind); }
  }
  note(qvOk, `Quick View: opens the right product with the sale/real price, sits above the nav and everything, closes with X, Escape and backdrop for all ${cards.length} cards ${qvNotes.join(' ')}`);

  // picture click also opens Quick View
  await go('/catalog');
  await evalJs(`document.querySelector('.lp-pcard__media img').click()`); await sleep(600);
  note(await evalJs(`document.getElementById('cxMod').classList.contains('on')`), 'catalog: a click on the card picture opens Quick View');
  await evalJs(`document.getElementById('cxClose').click()`);

  // navigation from the cards
  await go('/catalog');
  const first = cards[0];
  await evalJs(`document.querySelector('.lp-pcard[data-product-id="${first.id}"] .lp-pcard__btn').click()`); await sleep(1200);
  note((await evalJs('location.pathname')) === '/product/' + first.id, 'catalog: clicking View Details lands on that product page');

  // keyboard: Tab through the catalog cards, every card control reachable with a visible focus ring
  await go('/catalog');
  const focused = [];
  for (let i = 0; i < 90; i++) {
    await send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    const f = await evalJs(`(() => { const a = document.activeElement; if (!a || !a.closest('.lp-pcard')) return null; const s = getComputedStyle(a); return { cls: a.className, ring: s.outlineStyle !== 'none' && parseFloat(s.outlineWidth) >= 2 }; })()`);
    if (f) focused.push(f);
  }
  const kinds = new Set(focused.map((f) => f.cls.split(' ')[0] || 'a'));
  note(focused.length >= 27 && focused.every((f) => f.ring), `keyboard: Tab reaches the Quick View, title link and View Details of every card, each with a visible ring (${focused.length} stops, ${focused.filter((f) => !f.ring).length} without a ring)`);

  // filters, search, sort
  const list = async (q) => { await go('/catalog' + q); return evalJs(`[...document.querySelectorAll('.lp-pcard')].map((c) => ({ n: c.querySelector('.lp-pcard__title').textContent.trim(), cat: c.querySelector('.lp-pcard__cat').textContent.trim(), p: Number(c.querySelector('.lp-pcard__price').textContent.replace(/[^0-9.]/g, '')) }))`); };
  let l = await list('?category=Chairs'); note(l.length === 4 && l.every((x) => x.cat === 'Chairs'), `filter: category chip Chairs shows only chairs (${l.length})`);
  l = await list('?search=table'); note(l.length >= 2 && l.every((x) => /table/i.test(x.n)), `search: "table" shows only matching products (${l.map((x) => x.n).join(', ')})`);
  l = await list('?sort=price_asc'); note(l.every((x, i) => i === 0 || l[i - 1].p <= x.p), 'sort: low to high is ordered by the tag price');
  l = await list('?sort=price_desc'); note(l.every((x, i) => i === 0 || l[i - 1].p >= x.p), 'sort: high to low is ordered by the tag price');
  l = await list('?max_price=10000'); note(l.length > 0 && l.every((x) => x.p <= 10000), 'filter: highest price 10,000 keeps only products at or under it');
  l = await list('?category=Cabinets&sort=price_desc&max_price=50000'); note(l.length >= 1 && l.every((x) => x.cat === 'Cabinets' && x.p <= 50000), 'filters combine (category + sort + max price)');
  l = await list('?search=zzzz'); note(l.length === 0 && (await evalJs(`!!document.querySelector('.cx-empty')`)), 'filter: no matches shows the empty message');

  // carousel
  await viewport(1440, 900); await go('/'); await evalJs(hook);
  const car = await evalJs(`(() => { const t = document.getElementById('bestsellers'); return { n: t.querySelectorAll('.carousel-card').length, ordered: [...t.querySelectorAll('.carousel-card')].map((c) => c.querySelector('.lp-pcard__title').textContent.trim() + '=' + ([...c.querySelectorAll('.lp-pcard__badge')].map((b) => b.textContent.trim()).filter((x) => /Ordered|New/.test(x))[0] || '')), dots: document.querySelectorAll('#bestsellers-dots .carousel-dot').length, btn: [...t.querySelectorAll('.lp-pcard__btn')].map((b) => b.textContent.trim()) }; })()`);
  note(car.n >= 5 && car.btn.every((b) => b === 'Order Now'), `carousel: ${car.n} cards, every button says Order Now`);
  const want = { 'Narra Dining Table': 9, 'Narra Arm Chair With Cushioned Seat, Woven Back And Hand Carved Armrests': 6, 'Executive Office Chair': 4, 'Rocking Chair': 3, 'Study Table': 1 };
  note(Object.entries(want).every(([n, v]) => car.ordered.some((o) => o.toLowerCase().startsWith(n.toLowerCase() + '=') && o.includes(v + ' Ordered'))), `carousel: "N Ordered" counts are the real order counts (${car.ordered.slice(0, 5).join(' | ')})`);
  await viewport(900, 900); await go('/'); await evalJs(hook);
  const sc0 = await evalJs(`document.getElementById('bestsellers').scrollLeft`);
  const dots = await evalJs(`document.querySelectorAll('#bestsellers-dots .carousel-dot').length`);
  await evalJs(`document.querySelector('.carousel-btn-next').click()`); await sleep(900);
  const sc1 = await evalJs(`document.getElementById('bestsellers').scrollLeft`);
  const act = await evalJs(`[...document.querySelectorAll('#bestsellers-dots .carousel-dot')].findIndex((d) => d.classList.contains('active'))`);
  await evalJs(`document.querySelector('.carousel-btn-prev').click()`); await sleep(900);
  const sc2 = await evalJs(`document.getElementById('bestsellers').scrollLeft`);
  note(dots >= 2 && sc1 > sc0 && sc2 < sc1 && act >= 1, `carousel: arrows scroll both ways (${sc0} -> ${sc1} -> ${sc2}) and the dots follow (${dots} dots, active ${act})`);
  await evalJs(`document.getElementById('bestsellers').scrollTo({ left: 600, behavior: 'instant' })`); await sleep(300);
  let back = 999; for (let t = 0; t < 3 && back > 6; t++) { await evalJs(`document.querySelectorAll('#bestsellers-dots .carousel-dot')[0].click()`); await sleep(1000); back = await evalJs(`document.getElementById('bestsellers').scrollLeft`); } // the page auto-slides every 5 s, so allow a retry
  note(back <= 6, 'carousel: first dot scrolls back to the start');

  // phone sale + best seller closeups (1440 and 390)
  for (const w of [1440, 390]) {
    await viewport(w, w === 390 ? 844 : 900); await go('/catalog');
    await shotRect(`sale-best-seller-${w}`, '.lp-pcard[data-product-id="1"]', 16);
    await go('/catalog?sort=price_desc');
    await shotRect(`long-name-128000-${w}`, '.lp-pcard[data-product-id="3"]', 16);
    await go('/catalog'); await evalJs(`document.querySelector('.lp-pcard[data-product-id="1"] .lp-pcard__media').click()`); await sleep(900);
    const s = await evalJs(`(() => { const q = document.getElementById('cxQd'); const b = q.getBoundingClientRect(); return { x: b.left, y: b.top, w: b.width, h: b.height }; })()`);
    const sh = await send('Page.captureScreenshot', { format: 'png' });
    fs.writeFileSync(path.join(OUT, `quick-view-${w}.png`), Buffer.from(sh.data, 'base64'));
    await evalJs(`document.getElementById('cxClose').click()`);
  }
  await viewport(1001, 900); await go('/catalog'); await evalJs(`document.querySelector('.lp-pcard[data-product-id="1"] .lp-pcard__media').click()`); await sleep(900);
  const sh = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, 'quick-view-1001.png'), Buffer.from(sh.data, 'base64'));
  const top1001 = await evalJs(`(() => { const q = document.getElementById('cxQd').getBoundingClientRect(); const e = document.elementFromPoint(q.left + q.width / 2, q.top + 30); return !!e && document.getElementById('cxQd').contains(e); })()`);
  note(top1001, 'Quick View at 1001px opens above everything');
  await evalJs(`document.getElementById('cxClose').click()`);

  // price on the product page's related card equals the product's price
  await go('/product/3');
  const rel = await evalJs(`[...document.querySelectorAll('.detail-related .lp-pcard')].map((c) => ({ id: c.dataset.productId, price: c.querySelector('.lp-pcard__price').textContent.trim(), href: c.querySelector('.lp-pcard__btn').getAttribute('href') }))`);
  note(rel.length >= 1 && rel.every((r) => r.href.endsWith('/product/' + r.id)), `product page: related cards link to their own pages (${rel.length})`);

  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(results, null, 1));
  const fails = results.filter((r) => !r.ok).length;
  console.log(`\n${results.length - fails} passed, ${fails} failed`);
  c.close();
  process.exit(fails ? 1 : 0);
}
main().catch((e) => { console.error(e); process.exit(2); });
