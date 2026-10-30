/*
 * Inventory and New Ticket modals walk (SCRATCH DATABASE ONLY, server on 127.0.0.1:8011, php tests/UiLinks/seed_inventory_ticket.php first).
 *   functional: real clicks - Add Material (client check, dirty guard, server rejection reopens, save), Edit Material, old URLs, New Ticket
 *               (client check, account search + link, server rejection reopens with values, save)
 *   proof:      widths 320, 360, 390, 430, 768, 1100, 1440: no horizontal overflow, every modal fully visible above the sidebar and top bar,
 *               bottom sheet at 700px and below, quantity box in the list not clipped, computed fonts; screenshots in
 *               docs/admin/screenshots-inventory-ticket/
 *   node --experimental-websocket tests/UiLinks/browser-admin-inventory-ticket.cjs [functional|proof|all]
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(ROOT, 'docs', 'admin', 'screenshots-inventory-ticket');
const sql = (q) => JSON.parse(execSync(`php tests/UiLinks/sql-scratch.php "${q}"`, { cwd: ROOT }).toString());
const cookie = JSON.parse(execSync('php tests/UiLinks/session-cookie-for.php inv-admin@example.test admin', { cwd: ROOT }).toString());
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
           font: getComputedStyle(m.querySelector('.form-label')).fontFamily.split(',')[0], titleFont: getComputedStyle(m.querySelector('.lp-modal__title')).fontFamily.split(',')[0] };
})()`;

// is the number in every quantity box fully inside its box?
const QTY_CHECK = `(() => [...document.querySelectorAll('.admin-quick-stock-input')].map((i) => {
  const c = document.createElement('canvas').getContext('2d'); const s = getComputedStyle(i); c.font = s.fontWeight + ' ' + s.fontSize + ' ' + s.fontFamily;
  const text = c.measureText(i.value).width, room = i.clientWidth - parseFloat(s.paddingLeft) - parseFloat(s.paddingRight);
  return { value: i.value, text: Math.round(text), room: Math.round(room), fits: text <= room };
}))()`;

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await start(9450);
  await b.setCookie(cookie);
  const $ = (s) => `document.querySelector(${JSON.stringify(s)})`;
  const settle = async (ms = 1500) => { await sleep(ms); for (let i = 0; i < 30 && (await b.evalJs('document.readyState')) !== 'complete'; i++) await sleep(150); await sleep(350); };
  const click = (s) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.click(); return true;})()`);
  const clickText = (sel, t) => b.evalJs(`(()=>{const e=[...document.querySelectorAll(${JSON.stringify(sel)})].find(x=>x.textContent.trim().replace(/\\s+/g,' ')===${JSON.stringify(t)}); if(!e) return false; e.click(); return true;})()`);
  const setVal = (s, v) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true})); return true;})()`);
  const openId = () => b.evalJs(`(()=>{const m=[...document.querySelectorAll('.lp-modal')].find(x=>!x.hidden); return m ? m.id : null})()`);
  const esc = () => b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  const shot = async (name) => { const r = await b.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };

  try {
    if (MODE === 'functional' || MODE === 'all') {
      // ── Materials Inventory ───────────────────────────────────────────────────────────────────
      await b.viewport(1440, 900);
      await b.go('/admin/inventory');
      note((await b.evalJs(`!!${$('a[href$="/inventory/create"]')}`)) === false, 'no link to the old Add page');
      await clickText('button', 'Add Material'); await sleep(500);
      note((await openId()) === 'material-add', 'Add Material opens the modal on the list');
      await click('[form=material-add-form]'); await sleep(300);
      note((await b.evalJs(`document.querySelectorAll('#material-add .am-field.is-invalid').length`)) === 3, 'empty Save shows the three client messages (threshold defaults to 10) and stays open');
      await setVal('#material-add-form-name', 'Walk Dowels');
      await setVal('#material-add-form-qty', '12.5');
      await setVal('#material-add-form-unit', 'pcs');
      await setVal('#material-add-form-threshold', '-3');
      await esc(); await sleep(300);
      note((await b.evalJs(`!!${$('#material-add .am-discard.is-on')}`)), 'Escape with typed text asks "Discard what you typed?"');
      await click('#material-add [data-keep]'); await sleep(200);
      await click('[form=material-add-form]'); await settle();
      note((await openId()) === 'material-add', 'server rejection (negative threshold) reopens the Add modal');
      note((await b.evalJs(`${$('#material-add-form-name')}.value`)) === 'Walk Dowels', 'typed values kept after the rejection');
      note(((await b.evalJs(`(${$('#material-add .am-field.is-invalid .am-err')}||{}).textContent`)) || '').length > 0, 'server message shown under the field');
      await setVal('#material-add-form-threshold', '5');
      await click('[form=material-add-form]'); await settle();
      const added = sql("select id,inventory_status from inventory where material_name='Walk Dowels'")[0];
      note(!!added && added.inventory_status === 'in_stock', 'material saved through the existing store route (status computed)');
      note((await openId()) === null, 'modal closed after the save');
      // edit
      await b.go('/admin/inventory');
      await b.evalJs(`[...document.querySelectorAll('tr')].find(r=>r.textContent.includes('Walk Dowels')).querySelector('[data-lp-modal-open]').click()`); await sleep(500);
      note((await openId()) === `material-edit-${added.id}`, 'Edit opens that material in the modal');
      note((await b.evalJs(`${$('#material-edit-' + added.id + '-form-qty')}.value`)) === '12.50', 'edit modal is pre-filled');
      await setVal(`#material-edit-${added.id}-form-qty`, '1');
      await click(`[form=material-edit-${added.id}-form]`); await settle();
      note(sql(`select inventory_status from inventory where id=${added.id}`)[0].inventory_status === 'low_stock', 'edit saved and status recomputed to Low Stock');
      // old URLs
      await b.go('/admin/inventory/create'); await settle(1000);
      note((await openId()) === 'material-add' && (await b.evalJs('location.pathname')) === '/admin/inventory', 'old Add URL lands on the list with the Add modal open');
      await b.go(`/admin/inventory/${added.id}/edit`); await settle(1000);
      note((await openId()) === `material-edit-${added.id}`, 'old Edit URL lands on the list with that Edit modal open');

      // ── Other Services: New Ticket ────────────────────────────────────────────────────────────
      await b.go('/admin/service-inquiries');
      note((await b.evalJs(`!!${$('a[href$="/service-inquiries/create"]')}`)) === false, 'no link to the old New Ticket page');
      await clickText('button', 'New Ticket'); await sleep(500);
      note((await openId()) === 'ticket-new', 'New Ticket opens the modal on the list');
      await click('[form=ticket-new-form]'); await sleep(300);
      note((await b.evalJs(`document.querySelectorAll('#ticket-new .am-field.is-invalid').length`)) >= 3, 'empty Create shows the client messages and stays open');
      await setVal('#nt-search', 'linda'); await sleep(1200);
      note((await b.evalJs(`document.querySelectorAll('#nt-results li[role=option]').length`)) >= 1, 'account search lists the customer inside the modal');
      await b.evalJs(`document.querySelector('#nt-results li[role=option]').click()`); await sleep(300);
      note((await b.evalJs(`${$('#nt-name')}.value`)) === 'Linda Walker' && (await b.evalJs(`!${$('#nt-linked')}.hidden`)), 'linking fills the name and shows the linked bar');
      await b.evalJs(`document.querySelector('#ticket-new input[name=service_type][value=repair]').click()`);
      await setVal('#nt-concern', 'short');
      await click('[form=ticket-new-form]'); await settle();
      note((await openId()) === 'ticket-new', 'server rejection (concern too short) reopens the New Ticket modal');
      note((await b.evalJs(`${$('#nt-name')}.value`)) === 'Linda Walker' && (await b.evalJs(`${$('#nt-concern')}.value`)) === 'short', 'typed values kept');
      note((await b.evalJs(`!${$('#nt-linked')}.hidden`)), 'the linked customer is still shown');
      await setVal('#nt-concern', 'Wobbly chair leg that needs repair.');
      await click('[form=ticket-new-form]'); await settle();
      note(/\/admin\/service-inquiries\/\d+$/.test(await b.evalJs('location.pathname')), 'ticket created and the ticket page opens');
      await b.go('/admin/service-inquiries/create'); await settle(1000);
      note((await openId()) === 'ticket-new', 'old New Ticket URL lands on the list with the modal open');
    }

    if (MODE === 'proof' || MODE === 'all') {
      const pages = [
        ['inventory-list', '/admin/inventory', null],
        ['inventory-add', '/admin/inventory?add=1', 'material-add'],
        ['inventory-edit', '/admin/inventory?edit=' + sql("select id from inventory where material_name='Narra Wood Planks'")[0].id, null],
        ['ticket-new', '/admin/service-inquiries?new=1', 'ticket-new'],
      ];
      for (const w of [320, 360, 390, 430, 768, 1100, 1440]) {
        await b.viewport(w, w < 700 ? 800 : 900);
        for (const [name, url] of pages) {
          await b.go(url); await settle(900);
          const over = await b.evalJs('document.documentElement.scrollWidth - innerWidth');
          note(over <= 0, `${name} @${w}: no horizontal overflow (${over})`);
          if (name === 'inventory-list') {
            const q = await b.evalJs(QTY_CHECK);
            note(q.length > 0 && q.every((x) => x.fits), `${name} @${w}: every quantity number fits its box ${JSON.stringify(q.map((x) => x.value + ':' + x.text + '/' + x.room))}`);
            await shot(`${name}-${w}`);
            continue;
          }
          const m = await b.evalJs(MODAL_CHECK);
          const ok = m.open && m.boxInViewport && m.aboveSidebar && m.aboveTopbar && m.footerVisible && m.overflowing === 0 && (w > 700 || m.sheet) && /Montserrat/i.test(m.font);
          note(ok, `${name} @${w}: modal visible above sidebar/top bar, footer reachable, ${w <= 700 ? 'bottom sheet' : 'centred'}, font ${m.font} ${JSON.stringify({ ...m, font: undefined, titleFont: m.titleFont })}`);
          await shot(`${name}-${w}`);
          // Edit modal: open it from the button at this width
        }
        await b.go('/admin/inventory'); await settle(700);
        await b.evalJs(`document.querySelector('[data-lp-modal-open^=material-edit]').click()`); await sleep(500);
        const e = await b.evalJs(MODAL_CHECK);
        note(e.open && e.boxInViewport && e.footerVisible && e.overflowing === 0 && (w > 700 || e.sheet), `inventory-edit-button @${w}: Edit modal fully visible${w <= 700 ? ' as a bottom sheet' : ''}`);
        await shot(`inventory-edit-${w}`);
      }
    }
  } finally {
    b.close();
  }
  const bad = results.filter((r) => !r).length;
  console.log(`\n${results.length - bad} passed, ${bad} failed`);
  process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
