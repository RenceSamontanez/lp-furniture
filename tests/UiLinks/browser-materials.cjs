/*
 * Materials walk (SCRATCH DATABASE ONLY, server on 127.0.0.1:8011, php tests/UiLinks/seed_materials.php first; phpunit rebuilds the scratch DB, so seed after it).
 *   functional: real clicks - Restock (live "After restocking:", movement saved), Edit Material (Correct The Count), Material Estimates (edit + Reset To Starter),
 *               Product Add (starter picked from the name, Edited tag, Reset To Starter, wood warning, saved with its estimate), old URLs, Reports > Materials
 *   proof:      widths 320, 360, 390, 430, 768, 1100, 1440: no horizontal overflow, every modal fully visible above the sidebar and top bar, bottom sheet at 700px
 *               and below; screenshots in docs/admin/screenshots-materials/
 *   node --experimental-websocket tests/UiLinks/browser-materials.cjs [functional|proof|all]
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(ROOT, 'docs', 'admin', 'screenshots-materials');
const sql = (q) => JSON.parse(execSync(`php tests/UiLinks/sql-scratch.php "${q}"`, { cwd: ROOT }).toString());
const cookie = JSON.parse(execSync('php tests/UiLinks/session-cookie-for.php mat-admin@example.test admin', { cwd: ROOT }).toString());
const results = [];
const note = (ok, msg) => { results.push(ok); console.log((ok ? 'ok    ' : 'FAIL  ') + msg); };

const MODAL_CHECK = `(() => {
  const m = [...document.querySelectorAll('.lp-modal')].find((x) => !x.hidden); if (!m) return { open: false };
  const box = m.querySelector('.lp-modal__box').getBoundingClientRect();
  const vw = innerWidth, vh = innerHeight;
  const inside = (el) => !!el && (el === m || m.contains(el));
  const probe = (el) => { if (!el) return true; const r = el.getBoundingClientRect(); if (r.width === 0) return true; return inside(document.elementFromPoint(Math.min(vw - 2, Math.max(2, r.left + r.width / 2)), Math.min(vh - 2, Math.max(2, r.top + Math.min(r.height / 2, 40))))); };
  const btns = [...m.querySelectorAll('.lp-modal__foot .lp-btn')].map((b) => { const r = b.getBoundingClientRect(); return r.top >= -1 && r.bottom <= vh + 1 && r.left >= -1 && r.right <= vw + 1; });
  const wide = [...m.querySelectorAll('.form-input, label, .am-help')].filter((e) => e.getBoundingClientRect().right > box.right + 1).length;
  return { open: true, id: m.id, boxInViewport: box.top >= -1 && box.left >= -1 && box.right <= vw + 1 && box.bottom <= vh + 1,
           aboveSidebar: probe(document.querySelector('.admin-sidebar')), aboveTopbar: probe(document.querySelector('.admin-topbar')),
           footerVisible: btns.length > 0 && btns.every(Boolean), overflowing: wide, sheet: Math.abs(box.bottom - vh) < 2 && Math.abs(box.width - vw) < 2,
           font: getComputedStyle(m.querySelector('.form-label') || m.querySelector('.lp-modal__title')).fontFamily.split(',')[0] };
})()`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await start(9460);
  await b.setCookie(cookie);
  const $ = (s) => `document.querySelector(${JSON.stringify(s)})`;
  const settle = async (ms = 1500) => { await sleep(ms); for (let i = 0; i < 30 && (await b.evalJs('document.readyState')) !== 'complete'; i++) await sleep(150); await sleep(350); };
  const click = (s) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.click(); return true;})()`);
  const setVal = (s, v) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); return true;})()`);
  const openId = () => b.evalJs(`(()=>{const m=[...document.querySelectorAll('.lp-modal')].find(x=>!x.hidden); return m ? m.id : null})()`);
  const text = (s) => b.evalJs(`(${$(s)}||{}).textContent||''`);
  const shot = async (name) => { const r = await b.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const q1 = (query) => sql(query)[0];

  try {
    if (MODE === 'functional' || MODE === 'all') {
      await b.viewport(1440, 900);

      // ── Materials Inventory ─────────────────────────────────────────────────────────────────
      await b.go('/admin/inventory'); await settle();
      note((await text('.mt-counts')).includes('Short'), 'count cards: Materials, In Stock, Low Stock, Short');
      note((await b.evalJs(`document.body.textContent.includes('Short by 40 sqft')`)), 'a negative count reads "Short by 40 sqft"');
      note((await b.evalJs(`!!document.querySelector('.mt-chip.short')`)), 'the Short chip is shown');
      const glue = q1("select id,material_quantity from inventory where material_name='Wood Glue'");
      await b.evalJs(`document.querySelector('[data-lp-modal-open=material-restock-${glue.id}]').click()`); await sleep(500);
      note((await openId()) === `material-restock-${glue.id}`, 'Restock opens its modal');
      await setVal(`#material-restock-${glue.id}-form-add`, '5');
      note((await text(`#material-restock-${glue.id} [data-after]`)).includes('After restocking: 7'), 'live "After restocking:" follows the typed amount');
      await setVal(`#material-restock-${glue.id}-form-note`, 'Walk delivery');
      await click(`[form=material-restock-${glue.id}-form]`); await settle();
      note(Number(q1(`select material_quantity as q from inventory where id=${glue.id}`).q) === Number(glue.material_quantity) + 5, 'restock added to the count');
      note(q1(`select count(*) as c from inventory_movements where inventory_id=${glue.id} and type='restocked' and note='Walk delivery'`).c == 1, "a 'restocked' movement was written with the note");
      await b.evalJs(`document.querySelector('[data-lp-modal-open=material-restock-${glue.id}]').click()`); await sleep(400);
      await click(`[form=material-restock-${glue.id}-form]`); await sleep(300);
      note((await b.evalJs(`document.querySelectorAll('#material-restock-${glue.id} .am-field.is-invalid').length`)) === 1, 'empty Restock shows the client message and stays open');
      await b.evalJs(`document.querySelector('#material-restock-${glue.id} [data-lp-modal-close]').click()`); await sleep(300);
      await b.evalJs(`document.querySelector('[data-lp-modal-open=material-edit-${glue.id}]').click()`); await sleep(400);
      note((await text(`#material-edit-${glue.id} label[for$=qty]`)) === 'Correct The Count', 'Edit Material has "Correct The Count"');
      await b.go('/admin/inventory/create'); await settle(1000);
      note((await openId()) === 'material-add', 'old Add URL opens the Add modal on the list');

      // ── Material Estimates ──────────────────────────────────────────────────────────────────
      await b.go('/admin/material-estimates'); await settle();
      note((await b.evalJs(`document.querySelectorAll('#mt-est-rows tr').length`)) === 37, 'all 37 furniture types are listed');
      const king = q1("select id from material_estimates where name='Bed, King'");
      await b.go(`/admin/material-estimates/${king.id}/edit`); await settle(1000);
      note((await openId()) === `estimate-edit-${king.id}`, 'old Edit URL opens that furniture type');
      await setVal(`#estimate-edit-${king.id}-form-wood`, '130');
      await click(`[form=estimate-edit-${king.id}-form]`); await settle();
      note(Number(q1(`select wood as w from material_estimates where id=${king.id}`).w) === 130 && q1(`select is_edited as e from material_estimates where id=${king.id}`).e == 1, 'edit saved and marked Edited');
      await b.evalJs(`document.querySelector('[data-lp-modal-open=estimate-edit-${king.id}]').click()`); await sleep(400);
      await click(`[form=estimate-edit-${king.id}-reset]`); await settle();
      note(Number(q1(`select wood as w from material_estimates where id=${king.id}`).w) === 100, 'Reset To Starter restored 100 sqft');

      // ── Products: Add with Materials Needed ────────────────────────────────────────────────
      await b.go('/admin/products?add=1'); await settle(1800);
      note((await openId()) === 'product-add', 'Add Product opens the modal on the list');
      await setVal('#product-add-form-name', 'Queen Bed Frame');
      note((await text('#product-add [data-mt-tag]')).includes('Starter: Bed, Queen'), 'starter picked from the name: Bed, Queen');
      note((await b.evalJs(`${$('#product-add-form-est-wood')}.value`)) === '77', 'wood 77 sqft filled in');
      await setVal('#product-add-form-name', 'Rocking Chair');
      note((await text('#product-add [data-mt-tag]')).includes('Starter: Rocking Chair'), '"Rocking Chair" is not mistaken for "king"');
      await setVal('#product-add-form-name', 'Queen Bed Frame');
      await setVal('#product-add-form-est-wood', '90');
      note((await text('#product-add [data-mt-tag]')).includes('Edited, from Bed, Queen'), 'editing a number shows the Edited tag');
      await b.evalJs(`document.querySelector('#product-add [data-mt-reset]').click()`);
      note((await b.evalJs(`${$('#product-add-form-est-wood')}.value`)) === '77' && (await text('#product-add [data-mt-tag]')).includes('Starter: Bed, Queen'), 'Reset To Starter restores 77');
      await setVal('#product-add-form-mat', 'Bamboo-wood');
      await b.evalJs(`document.querySelector('#product-add-form-mat').dispatchEvent(new Event('change',{bubbles:true}))`);
      note((await b.evalJs(`document.querySelector('#product-add [data-mt-warn]').classList.contains('is-on')`)), 'wood not in the inventory shows the warning');
      await setVal('#product-add-form-price', '12000'); await setVal('#product-add-form-stock', '2');
      await b.evalJs(`(()=>{const s=document.querySelector('#product-add-form-cat'); s.value=[...s.options].find(o=>o.value==='Bed').value; s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
      await b.evalJs(`(()=>{for (const id of ['#product-add-form-fin']) { const s=document.querySelector(id); if (s && s.options.length>1) s.selectedIndex=0; }})()`);
      await click('[form=product-add-form]'); await settle(2000);
      const made = sql("select p.id, e.type_name, e.wood from products p join product_material_estimates e on e.product_id=p.id where p.product_name='Queen Bed Frame'")[0];
      note(!!made && made.type_name === 'Bed, Queen' && Number(made.wood) === 77, 'product saved with its estimate');
      await b.go('/admin/products'); await settle(1500);
      note((await b.evalJs(`document.body.textContent.includes('Materials Estimate')`)), 'products list has the Materials Estimate column');
      await b.go('/admin/products/create'); await settle(1000);
      note((await openId()) === 'product-add', 'old Add Product URL opens the modal');

      // ── Reports > Materials ─────────────────────────────────────────────────────────────────
      await b.go('/admin/reports?tab=materials'); await settle();
      note((await b.evalJs(`document.querySelector('#rp-materials').classList.contains('is-on')`)), 'Materials tab opens');
      note((await b.evalJs(`document.querySelectorAll('#rp-materials .mt-rp .kpi').length`)) === 6, 'six KPI cards');
      note((await b.evalJs(`getComputedStyle(document.querySelector('.rp > .rp-kpis')).display`)) === 'none', 'the other tabs\' KPI row is hidden on this tab');
      note(!(await text('#rp-materials')).includes('₱'), 'no prices');
      await click('#rp-materials [data-mperiod="90"]'); await settle();
      note((await b.evalJs('location.search')).includes('mperiod=90'), 'Last 3 Months switches the period');
    }

    if (MODE === 'proof' || MODE === 'all') {
      const kingId = q1("select id from material_estimates where name='Bed, King'").id;
      const prodId = q1("select id from products where product_name='King Bed Frame'").id;
      const pages = [
        ['inventory-list', '/admin/inventory', null, null],
        ['inventory-add', '/admin/inventory?add=1', 'material-add', null],
        ['inventory-edit', null, null, '[data-lp-modal-open^=material-edit]'],
        ['inventory-restock', null, null, '[data-lp-modal-open^=material-restock]'],
        ['inventory-remove', null, null, '[data-lp-modal-open^=material-remove]'],
        ['estimates-list', '/admin/material-estimates', null, null],
        ['estimate-edit', `/admin/material-estimates?edit=${kingId}`, `estimate-edit-${kingId}`, null],
        ['estimate-add', '/admin/material-estimates?add=1', 'estimate-add', null],
        ['products-list', '/admin/products', null, null],
        ['product-add', '/admin/products?add=1', 'product-add', null],
        ['product-edit', `/admin/products?edit=${prodId}`, `product-edit-${prodId}`, null],
        ['reports-materials', '/admin/reports?tab=materials', null, null],
      ];
      for (const w of [320, 360, 390, 430, 768, 1100, 1440]) {
        await b.viewport(w, w < 700 ? 800 : 900);
        for (const [name, url, modal, opener] of pages) {
          await b.go(url || '/admin/inventory'); await settle(900);
          if (opener) { await b.evalJs(`document.querySelector(${JSON.stringify(opener)}).click()`); await sleep(500); }
          const over = await b.evalJs('document.documentElement.scrollWidth - innerWidth');
          note(over <= 0, `${name} @${w}: no horizontal overflow (${over})`);
          if (modal || opener) {
            const m = await b.evalJs(MODAL_CHECK);
            const ok = m.open && m.boxInViewport && m.aboveSidebar && m.aboveTopbar && m.footerVisible && m.overflowing === 0 && (w > 700 || m.sheet) && /Montserrat/i.test(m.font);
            note(ok, `${name} @${w}: modal visible above sidebar/top bar, footer reachable, ${w <= 700 ? 'bottom sheet' : 'centred'}, font ${m.font} ${JSON.stringify({ ...m, font: undefined })}`);
          }
          if (name === 'reports-materials') {
            const cut = await b.evalJs(`[...document.querySelectorAll('#rp-materials *')].filter(e=>e.scrollWidth>e.clientWidth+1 && getComputedStyle(e).overflow!=='visible' && !e.closest('table')).length`);
            note(cut === 0, `${name} @${w}: nothing clipped (${cut})`);
          }
          if ([390, 1440].includes(w) || !modal) { await shot(`${name}-${w}`); }
        }
      }
    }
  } finally {
    b.close();
  }
  const bad = results.filter((r) => !r).length;
  console.log(`\n${results.length - bad} passed, ${bad} failed`);
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
