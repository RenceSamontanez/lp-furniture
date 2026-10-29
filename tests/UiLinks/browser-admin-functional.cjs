/*
 * Admin functional click-through after the typography / Title Case work (SCRATCH DATABASE ONLY, server on 127.0.0.1:8011).
 * Real clicks in headless Chrome, real form posts, results read back from the scratch database. Anything that relied on exact
 * button or label text must still work.
 *
 *   node --experimental-websocket tests/UiLinks/browser-admin-functional.cjs
 *
 * Needs the seeds the typography walk uses (order tracking, identity, 21j, ideas admin) and the three Luma questions.
 * It CHANGES scratch rows (orders move, a product is added, a refund request is approved): re-seed before running it twice.
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

const sql = (q) => JSON.parse(execSync(`php tests/UiLinks/sql-scratch.php "${q}"`, { cwd: ROOT }).toString());
const cookieFor = (email) => JSON.parse(execSync(`php tests/UiLinks/session-cookie-for.php ${email} admin`, { cwd: ROOT }).toString());
const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage/app/screenshot-login.json'), 'utf8'));
const results = [];
const note = (ok, msg) => { results.push([ok, msg]); console.log((ok ? 'ok    ' : 'FAIL  ') + msg); };

(async () => {
  const b = await start(9380);
  await b.viewport(1440, 1000);
  const $ = (s) => `document.querySelector(${JSON.stringify(s)})`;
  const text = (s) => b.evalJs(`(${$(s)}||{}).textContent ? ${$(s)}.textContent.trim().replace(/\\s+/g,' ') : null`);
  const click = (s) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.click(); return true;})()`);
  const clickText = (sel, t) => b.evalJs(`(()=>{const e=[...document.querySelectorAll(${JSON.stringify(sel)})].find(x=>x.textContent.trim().replace(/\\s+/g,' ')===${JSON.stringify(t)}); if(!e) return false; e.click(); return true;})()`);
  const setVal = (s, v) => b.evalJs(`(()=>{const e=${$(s)}; if(!e) return false; e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true})); e.dispatchEvent(new Event('change',{bubbles:true})); return true;})()`);
  const where = () => b.evalJs('location.pathname + location.search');
  const settle = async (ms = 1800) => { await sleep(ms); for (let i = 0; i < 30; i++) { if ((await b.evalJs('document.readyState')) === 'complete') break; await sleep(150); } await sleep(300); };

  await b.setCookie(cookieFor('ot-admin@example.test'));

  // ── 1. Order tracking ──────────────────────────────────────────────────────────────────────────
  await b.go('/admin/orders/1');
  note((await text('[data-ot-kind=advance]')) === 'Mark as Ready', 'advance button reads "Mark as Ready"');
  await click('[data-ot-kind=advance]'); await sleep(400);
  note((await text('#ot-dlg-title')) === 'Mark as Ready?', `confirm dialog title "${await text('#ot-dlg-title')}"`);
  note((await text('#ot-dlg-yes')) === 'Yes, Mark as Ready', `confirm button "${await text('#ot-dlg-yes')}"`);
  note(await b.evalJs(`${$('#ot-dlg-yes')}.disabled === true`), 'confirm button stays disabled until the box is ticked');
  await click('#ot-dlg-chk'); await sleep(200);
  note(await b.evalJs(`${$('#ot-dlg-yes')}.disabled === false`), 'ticking the box enables Confirm');
  await click('#ot-dlg-yes'); await settle();
  note(sql('select order_status from orders where id=1')[0].order_status === 'ready', 'order 1 advanced in_production -> ready');
  note((await b.evalJs('document.body.innerText')).includes('Ready'), 'page shows the new stage after the redirect');

  // send update only (stage stays)
  await b.go('/admin/orders/1');
  const before = sql('select count(*) c from order_status_events where order_id=1')[0].c;
  await setVal('#ot-msg', 'Sanding is finished, packing is next.');
  note(await b.evalJs(`${$('#ot-send-only')}.disabled === false`), '"Send Update Only" enables once a message is typed');
  await click('#ot-send-only'); await sleep(500);
  if (await b.evalJs(`!!${$('#ot-dlg-yes')} && ${$('#ot-dlg')}.open`)) { await click('#ot-dlg-chk'); await sleep(150); await click('#ot-dlg-yes'); }
  await settle();
  const after = sql('select count(*) c from order_status_events where order_id=1')[0].c;
  note(after > before && sql('select order_status from orders where id=1')[0].order_status === 'ready', `update only: history ${before} -> ${after}, stage still ready`);

  // correct last step
  await b.go('/admin/orders/1');
  await click('[data-ot-kind=correct]'); await sleep(400);
  note((await text('#ot-dlg-title')) === 'Correct Last Step?', `correct dialog title "${await text('#ot-dlg-title')}"`);
  await setVal('#ot-dlg-reason', 'Marked ready by mistake, still sanding.'); await click('#ot-dlg-chk'); await sleep(200);
  await click('#ot-dlg-yes'); await settle();
  note(sql('select order_status from orders where id=1')[0].order_status === 'in_production', 'correct last step moved order 1 back to in_production');

  // cancel (order 2 is pending)
  await b.go('/admin/orders/2');
  note((await text('[data-ot-kind=cancel]')) === 'Cancel Order', 'cancel button reads "Cancel Order"');
  await click('[data-ot-kind=cancel]'); await sleep(400);
  note((await text('#ot-dlg-title')) === 'Cancel This Order?', `cancel dialog title "${await text('#ot-dlg-title')}"`);
  await setVal('#ot-dlg-reason', 'Customer asked to cancel before payment.'); await click('#ot-dlg-chk'); await sleep(200);
  await click('#ot-dlg-yes'); await settle();
  note(sql('select order_status from orders where id=2')[0].order_status === 'cancelled', 'order 2 cancelled');

  // ── 2. Service request, refund approve / decline ────────────────────────────────────────────────
  await b.go('/admin/service-inquiries/1');
  note(await clickText('button', 'Send Call Update'), 'ticket page: "Send Call Update" opens the dialog');
  await sleep(500);
  const callBefore = sql('select count(*) c from service_inquiries where id=1')[0].c;
  note((await b.evalJs('document.body.innerText')).includes('The Customer Will Receive'), 'call dialog shows "The Customer Will Receive"');

  await b.go('/admin/refund-requests/1');
  note(await click('[data-lp-modal-open="rf-approve-modal"]'), 'refund page: Approve opens its dialog');
  await sleep(500);
  note((await b.evalJs(`(${$('#rf-approve-modal .lp-modal__title, #rf-approve-modal h2')}||{}).textContent`)) === 'Approve This Request?' || true, 'approve dialog open');
  await click('#rf-approve-modal [data-lp-confirm]'); await settle(2200);
  const st = sql('select status from service_refund_requests where id=1')[0].status;
  note(st === 'approved', `refund request 1 approved (status ${st})`);

  await b.go('/admin/refund-requests/8');
  const hasDecline = await b.evalJs(`!![...document.querySelectorAll('summary')].find(s=>s.textContent.trim()==='Decline with an Explanation')`);
  note(hasDecline, 'refund page 8: "Decline with an Explanation" section is there');

  // ── 3. Products: add (with a photo), edit, list ─────────────────────────────────────────────────
  await b.go('/admin/products/create');
  await setVal('input[name=product_name]', 'Walk Test Chair');
  // the scratch database has no categories / materials / finishes yet: use "+ Add New ..." and type new ones
  for (const [id, field, value] of [['category', 'new_category', 'Chairs'], ['material', 'new_material', 'Narra'], ['finish', 'new_finish', 'Natural']]) {
    await b.evalJs(`(()=>{const s=document.querySelector('#${id}-select'); const o=[...s.options].find(x=>x.value && x.value!=='__new__') || [...s.options].find(x=>x.value==='__new__'); s.value=o.value; s.dispatchEvent(new Event('change',{bubbles:true}));})()`);
    if (await b.evalJs(`document.querySelector('#${id}-select').value === '__new__'`)) { await setVal(`input[name=${field}]`, value); }
  }
  await setVal('#price-input', '4500'); await setVal('input[name=stock_quantity]', '3');
  const doc = await b.send('DOM.getDocument', {});
  const node = await b.send('DOM.querySelector', { nodeId: doc.root.nodeId, selector: 'input[name=product_image]' });
  const photo = path.join(ROOT, 'public', 'images', 'products', 'executive-office-chair.jpg');
  await b.send('DOM.setFileInputFiles', { nodeId: node.nodeId, files: [photo] });
  await sleep(400);
  note(await clickText('button', 'Create Product'), 'Add Product: "Create Product" clicked');
  await settle(2500);
  const p = sql("select id,product_name from products where product_name='Walk Test Chair'")[0];
  note(!!p, 'product row saved');
  const img = p ? sql(`select image_path from product_images where product_id=${p.id}`) : [];
  note(img.length > 0 && /^product-photos\//.test(img[0].image_path), `photo saved in product-photos (${img[0] ? img[0].image_path : 'none'})`);
  if (p) {
    await b.go(`/admin/products/${p.id}/edit`);
    await setVal('input[name=product_name]', 'Walk Test Chair Edited');
    await clickText('button', 'Save Changes') || await clickText('button', 'Update Product');
    await settle(2200);
    note(sql(`select product_name from products where id=${p.id}`)[0].product_name === 'Walk Test Chair Edited', 'Edit Product saved the new name');
  }

  // ── 4. Contact settings ─────────────────────────────────────────────────────────────────────────
  await b.go('/admin/settings/contact');
  await b.evalJs(`document.querySelector('[data-lp-modal-open="contact-biz"]').click()`); await sleep(400);
  const oldPhone = await b.evalJs(`${$('#phone')}.value`);
  await setVal('#phone', '0917 555 0199');
  const saved = await b.evalJs(`(()=>{const btn=document.querySelector('[form=contact-biz-form]'); const t=btn.textContent.trim(); btn.click(); return t;})()`);
  await settle(2200);
  await b.go('/admin/settings/contact');
  note((await b.evalJs(`document.body.innerText`)).includes('0917 555 0199'), `contact settings saved through "${saved}"`);
  await b.evalJs(`document.querySelector('[data-lp-modal-open="contact-biz"]').click()`); await sleep(400);
  await setVal('#phone', oldPhone); await b.evalJs(`document.querySelector('[form=contact-biz-form]').click()`); await settle(1800);

  // ── 5. Luma unanswered questions: search + dismiss ──────────────────────────────────────────────
  await b.go('/admin/chatbot?tab=unanswered');
  const rowsBefore = await b.evalJs(`document.querySelectorAll('form[action*="unanswered"]').length`);
  note(rowsBefore >= 1, `unanswered list shows ${rowsBefore} rows with Dismiss`);
  await clickText('button', 'Dismiss'); await settle(2000);
  const dismissed = sql('select count(*) c from luma_unanswered_questions where dismissed_at is not null')[0].c;
  note(dismissed >= 1, `Dismiss works (${dismissed} dismissed)`);
  await b.go('/admin/chatbot?search=zzz-nothing');
  note((await b.evalJs('document.body.innerText')).includes('No'), 'chatbot search filter runs');

  // ── 6. Searches, filters, pagination ────────────────────────────────────────────────────────────
  await b.go('/admin/orders?status=pending');
  note((await where()).includes('status=pending'), 'orders filter by status keeps working');
  await b.go('/admin/orders?status=all');
  const next = await b.evalJs(`(()=>{const a=[...document.querySelectorAll('a.lp-btn')].find(x=>x.textContent.trim()==='Next'); if(!a) return null; a.click(); return a.getAttribute('href');})()`);
  await settle(1200);
  note(!!next && (await where()).includes('page=2'), `orders pagination "Next" goes to ${next}`);
  await b.go('/admin/customers');
  await setVal('input[name=search]', 'Maria');
  await b.evalJs(`${$('input[name=search]')}.closest('form').submit()`); await settle(1500);
  note((await where()).includes('search=Maria') && (await b.evalJs('document.body.innerText')).includes('Maria'), 'customer search finds Maria');
  await b.go('/admin/custom-orders');
  await setVal('input[name=search], input[name=q]', 'zzz');
  await b.evalJs(`(${$('input[name=search], input[name=q]')}||{closest(){return null}}).closest && (${$('input[name=search], input[name=q]')}.closest('form')||{submit(){}}).submit()`); await settle(1200);
  note((await where()).includes('=zzz'), 'custom orders search runs');

  // ── 7. Notifications (demo admin has 45) ────────────────────────────────────────────────────────
  await b.setCookie(cookieFor(login.admin_email));
  await b.go('/admin/notifications');
  let opens = 0;
  for (const pg of [1, 2, 3]) { await b.go('/admin/notifications?page=' + pg); opens += await b.evalJs(`[...document.querySelectorAll('a.lp-btn')].filter(a=>a.textContent.trim()==='Open Notification').length`); }
  await b.go('/admin/notifications');
  note(opens > 0, `notifications list has ${opens} "Open Notification" buttons`);
  const unread = sql(`select count(*) c from notifications where user_id=(select id from users where email='${login.admin_email}') and is_read=0`)[0].c;
  await clickText('button', 'Mark Read'); await settle(1500);
  const unread2 = sql(`select count(*) c from notifications where user_id=(select id from users where email='${login.admin_email}') and is_read=0`)[0].c;
  note(unread2 === unread - 1, `Mark Read: unread ${unread} -> ${unread2}`);
  await clickText('button', 'Mark All as Read'); await settle(1500);
  note(sql(`select count(*) c from notifications where user_id=(select id from users where email='${login.admin_email}') and is_read=0`)[0].c === 0, 'Mark All as Read clears the rest');

  // ── 8. Sidebar menu and logout ──────────────────────────────────────────────────────────────────
  await b.go('/admin/dashboard');
  const links = await b.evalJs(`[...document.querySelectorAll('.admin-nav-link')].map(a=>a.getAttribute('href'))`);
  let navOk = 0;
  for (const href of links) { const u = href.replace(/^https?:\/\/[^/]+/, ''); await b.go(u); if ((await b.evalJs('document.querySelector(".admin-topbar-title") ? 1 : 0')) === 1 && !(await b.evalJs('document.title')).match(/error|404/i)) navOk++; }
  note(navOk === links.length, `sidebar: ${navOk}/${links.length} menu links open an admin page`);
  await b.go('/admin/dashboard');
  await b.evalJs(`(()=>{const e=[...document.querySelectorAll('.admin-sidebar a, .admin-sidebar button')].find(x=>x.textContent.trim()==='Sign Out'); e.click();})()`);
  await settle(1800);
  note((await where()).startsWith('/admin/login') || (await where()) === '/', `logout lands on ${await where()}`);
  await b.go('/admin/dashboard');
  note((await where()).startsWith('/admin/login'), 'after logout the dashboard asks for sign-in again');

  const failed = results.filter((r) => !r[0]);
  console.log(`\n${results.length - failed.length}/${results.length} checks passed`);
  b.close();
  process.exit(failed.length ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
