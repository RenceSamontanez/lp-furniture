/*
 * Admin Service Done and Approve-as-exception walk (SCRATCH DATABASE ONLY, server on 127.0.0.1:8011).
 * Needs the typography seeds, with ticket 16 marked as work started (refund request 8 then offers the exception path).
 *
 *   node --experimental-websocket tests/UiLinks/browser-admin-service-paths.cjs
 */
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');
const sql = (q) => JSON.parse(execSync(`php tests/UiLinks/sql-scratch.php "${q}"`, { cwd: ROOT }).toString());
const out = [];
const note = (ok, m) => { out.push(ok); console.log((ok ? 'ok    ' : 'FAIL  ') + m); };

(async () => {
  const b = await start(9395);
  await b.viewport(1440, 1000);
  const cookie = JSON.parse(execSync('php tests/UiLinks/session-cookie-for.php ot-admin@example.test admin', { cwd: ROOT }).toString());
  await b.setCookie(cookie);
  const $ = (s) => `document.querySelector(${JSON.stringify(s)})`;
  const settle = async () => { await sleep(1800); for (let i = 0; i < 30 && (await b.evalJs('document.readyState')) !== 'complete'; i++) await sleep(150); await sleep(300); };
  const textOf = (s) => b.evalJs(`(${$(s)}||{}).textContent ? ${$(s)}.textContent.trim().replace(/\\s+/g,' ') : null`);

  // Service Done: ticket 8 (work started, invoice paid)
  const before = sql('select count(*) c from service_invoices where service_inquiry_id=8 and service_done_at is not null');
  await b.go('/admin/service-inquiries/8');
  const label = await b.evalJs(`(()=>{const e=document.querySelector('[data-lp-modal-open="si-done-modal"]'); return e ? e.textContent.trim() : null})()`);
  note(label === 'Mark Service Done', `ticket 8 shows the button "${label}"`);
  await b.evalJs(`document.querySelector('[data-lp-modal-open="si-done-modal"]').click()`); await sleep(500);
  note((await textOf('#si-done-modal h2, #si-done-modal .lp-modal__title')) === 'Mark the Service as Done?', 'dialog title "Mark the Service as Done?"');
  await b.evalJs(`document.querySelector('#si-done-modal [data-lp-confirm]').click()`); await settle();
  const done = sql('select service_done_at from service_invoices where service_inquiry_id=8')[0];
  note(!!done.service_done_at, `service marked done in the database (${done.service_done_at})`);
  note(!(await b.evalJs(`!!document.querySelector('[data-lp-modal-open="si-done-modal"]')`)), 'the Mark Service Done button is gone after it is recorded');

  // Approve as an exception: refund request 8 (needs more information, work started)
  await b.go('/admin/refund-requests/8');
  const sum = await b.evalJs(`!![...document.querySelectorAll('summary')].find(s=>s.textContent.trim()==='Approve as an Exception')`);
  note(sum, '"Approve as an Exception" section is offered (work already started)');
  await b.evalJs(`[...document.querySelectorAll('summary')].find(s=>s.textContent.trim()==='Approve as an Exception').click()`);
  await b.evalJs(`(()=>{const t=document.querySelector('#rf-exc'); t.value='Customer cancelled the same day; shop agrees to refund.'; t.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  await b.evalJs(`document.querySelector('#rf-exc-form button[type=submit]').click()`); await sleep(600);
  note((await b.evalJs(`document.querySelector('#rf-exc-modal') && document.querySelector('#rf-exc-modal').getAttribute('open') !== null || (document.querySelector('#rf-exc-modal')||{}).open === true || getComputedStyle(document.querySelector('#rf-exc-modal')).display !== 'none'`)), 'confirm dialog opens before anything is saved');
  note(sql('select approved_as_exception a from service_refund_requests where id=8')[0].a == 0, 'nothing saved yet');
  await b.evalJs(`document.querySelector('#rf-exc-modal [data-lp-confirm]').click()`); await settle();
  const r = sql('select status,approved_as_exception a,exception_reason from service_refund_requests where id=8')[0];
  note(r.status === 'approved' && r.a == 1 && /same day/.test(r.exception_reason), `request 8 is ${r.status}, flagged as exception, reason saved`);
  note((await b.evalJs('document.body.innerText')).includes('Approved as an exception'), 'page shows the "Approved as an exception" notice');

  const bad = out.filter((x) => !x).length;
  console.log(`\n${out.length - bad}/${out.length} checks passed`);
  b.close(); process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
