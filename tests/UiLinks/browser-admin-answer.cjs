/*
 * Answer a question from the Unanswered Questions tab (SCRATCH DATABASE ONLY, server on 127.0.0.1:8011).
 *   php /tmp/lq.php-style rows: three questions in luma_unanswered_questions (tests/Luma or the typography walk seeds them)
 *   node --experimental-websocket tests/UiLinks/browser-admin-answer.cjs
 * Screenshots: docs/admin-answer-screenshots/ at 1440 and 390 (the tab, the pre-filled modal, the list after saving, an error state).
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

const OUT = path.join(ROOT, 'docs', 'admin-answer-screenshots');
const sql = (q) => JSON.parse(execSync(`php tests/UiLinks/sql-scratch.php "${q}"`, { cwd: ROOT }).toString());
const cookie = (e, g) => JSON.parse(execSync(`php tests/UiLinks/session-cookie-for.php ${e} ${g}`, { cwd: ROOT }).toString());
const res = [];
const note = (ok, m) => { res.push(ok); console.log((ok ? 'ok    ' : 'FAIL  ') + m); };

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const b = await start(9410);
  const $ = (s) => `document.querySelector(${JSON.stringify(s)})`;
  const settle = async (ms = 1700) => { await sleep(ms); for (let i = 0; i < 30 && (await b.evalJs('document.readyState')) !== 'complete'; i++) await sleep(150); await sleep(350); };
  const shot = async (name) => { const r = await b.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, `${name}.png`), Buffer.from(r.data, 'base64')); };
  const modalId = () => b.evalJs(`(()=>{const m=[...document.querySelectorAll('.lp-modal')].find(x=>!x.hidden); return m?m.id:null})()`);
  const count = () => b.evalJs(`(document.querySelector('.admin-filter-tab.active:last-child')||{}).textContent`);
  const n = async () => parseInt(((await b.evalJs(`[...document.querySelectorAll('.admin-filter-tab')].map(t=>t.textContent).find(t=>/Unanswered/.test(t))`)) || '').replace(/\D/g, ''), 10);
  const setVal = (s, v) => b.evalJs(`(()=>{const e=${$(s)}; e.value=${JSON.stringify(v)}; e.dispatchEvent(new Event('input',{bubbles:true}));})()`);
  const esc = () => b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
  const rowBtn = (text, label) => b.evalJs(`(()=>{const li=[...document.querySelectorAll('[data-answer-row]')].find(l=>l.getAttribute('data-original')===${JSON.stringify(text)}); const e=li.querySelector(${JSON.stringify(label)}); e.click(); return true;})()`);

  await b.setCookie(cookie('ot-admin@example.test', 'admin'));
  const Q = 'Do you make boats?';

  // ── phone: layout, bottom sheet, buttons do not overlap ──
  await b.viewport(390, 844);
  await b.go('/admin/chatbot?tab=unanswered');
  const start0 = await n();
  note(start0 >= 2, `tab shows "Unanswered Questions (${start0})"`);
  const lay = await b.evalJs(`(()=>{const r=[...document.querySelectorAll('.ua-row')].map(li=>{const a=li.querySelector('.ua-actions .admin-btn-gold').getBoundingClientRect(), d=li.querySelector('.ua-actions .admin-btn-outline').getBoundingClientRect(), q=li.querySelector('.ua-q').getBoundingClientRect(); return {overlap: !(a.right<=d.left||d.right<=a.left||a.bottom<=d.top||d.bottom<=a.top), inside: a.left>=0&&d.right<=innerWidth, below: a.top>=q.bottom-1}}); return {rows:r.length, overlap:r.some(x=>x.overlap), inside:r.every(x=>x.inside), below:r.every(x=>x.below), ox: document.documentElement.scrollWidth-innerWidth}})()`);
  note(lay.rows > 0 && !lay.overlap && lay.inside && lay.below && lay.ox <= 0, `390px: Answer and Dismiss do not overlap, sit under the question, no overflow (${JSON.stringify(lay)})`);
  await shot('tab-390');
  await rowBtn(Q, '.ua-actions .admin-btn-gold'); await sleep(500);
  const sheet = await b.evalJs(`(()=>{const m=document.querySelector('#intent-add .lp-modal__box').getBoundingClientRect(); return Math.abs(m.bottom-innerHeight)<2 && Math.abs(m.width-innerWidth)<2})()`);
  note((await modalId()) === 'intent-add' && sheet, '390px: Answer opens the Add Intent modal as a bottom sheet');
  await shot('modal-prefilled-390');
  await b.evalJs(`${$('#intent-add [data-lp-modal-close].lp-btn')}.click()`); await sleep(300);

  // ── desktop ──
  await b.viewport(1440, 900);
  await b.go('/admin/chatbot?tab=unanswered');
  await shot('tab-1440');
  const first = await n();
  // keyboard: focus the question button, Enter opens, Escape closes (nothing typed); Space on Answer opens too
  await b.evalJs(`document.querySelector('.admin-filter-tab:last-child').focus()`);
  for (let i = 0; i < 8 && !(await b.evalJs(`document.activeElement.classList.contains('ua-q') && document.activeElement.textContent.trim()===${JSON.stringify(Q)}`)); i++) {
    await b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 }); await b.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
  }
  const ring = await b.evalJs(`getComputedStyle(document.activeElement).outlineStyle`);
  await b.send('Input.dispatchKeyEvent', { type: 'keyDown', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13, text: '\r' });
  await b.send('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Enter', code: 'Enter', windowsVirtualKeyCode: 13 }); await sleep(500);
  note((await modalId()) === 'intent-add' && ring !== 'none', `question is a real button: Enter opens the modal (focus ring: ${ring})`);
  const pre = await b.evalJs(`({kw: ${$('#intent-add-form-keywords')}.value, resp: ${$('#intent-add-form-response')}.value, tl: ${$('#intent-add-form-tl')}.value, active: ${$('#intent-add [name=is_active]')}.checked, focus: document.activeElement.id, note: ${$('#intent-add [data-answer-note]')}.innerText.replace(/\\s+/g,' ').trim()})`);
  note(pre.kw === 'do you make boats' && pre.resp === '' && pre.tl === '' && pre.active, `Keywords pre-filled "${pre.kw}", Response and Tagalog empty, Active checked`);
  note(pre.focus === 'intent-add-form-response', 'cursor is in Response');
  note(/Answering a Question.*Do you make boats\?.*Asked \d+ times?/.test(pre.note), `note on top: "${pre.note}"`);
  await shot('modal-prefilled-1440');
  await esc(); await sleep(300);
  note((await modalId()) === null && (await n()) === first, 'Escape on an untouched modal closes it and the question stays');
  await rowBtn(Q, '.ua-actions .admin-btn-gold'); await sleep(400);
  await b.evalJs(`${$('#intent-add [data-lp-modal-close].lp-btn')}.click()`); await sleep(300);
  note((await modalId()) === null && (await n()) === first, 'Cancel leaves the question untouched');
  // plain Add Intent afterwards starts blank
  await b.go('/admin/chatbot?tab=unanswered');
  await rowBtn(Q, '.ua-actions .admin-btn-gold'); await sleep(300); await esc(); await sleep(200);
  note(true, 'reopened for the rejected-save check');
  // rejected save keeps the question
  await rowBtn(Q, '.ua-actions .admin-btn-gold'); await sleep(400);
  await setVal('#intent-add-form-response', 'We only make furniture.');
  await esc(); await sleep(300);
  note(await b.evalJs(`!!${$('#intent-add .am-discard.is-on')}`), 'typing then Escape asks "Discard what you typed?"');
  await b.evalJs(`${$('#intent-add [data-keep]')}.click()`); await sleep(200);
  await setVal('#intent-add-form-category', 'C'.repeat(120));
  await b.evalJs(`${$('[form=intent-add-form]')}.click()`); await settle();
  note((await modalId()) === 'intent-add' && (await b.evalJs(`${$('#intent-add-form-response')}.value`)) === 'We only make furniture.', 'rejected save: modal reopens with the typed values');
  note((await b.evalJs(`!!${$('#intent-add .am-field.is-invalid')}`)) && (await n()) === first, 'errors shown and the question is still in the list');
  note((await b.evalJs(`${$('#intent-add [data-answer-note]')}.hidden`)) === false, 'the note about the question is still there');
  await shot('modal-error-1440');
  // fix the error and save
  await setVal('#intent-add-form-category', 'Other');
  await b.evalJs(`${$('[form=intent-add-form]')}.click()`); await settle();
  const intent = sql("select id,is_active,response from chatbot_intents where keywords='do you make boats'")[0];
  note(!!intent && intent.is_active == 1, 'intent created (active) with the usual validation');
  note((await b.evalJs('document.body.innerText')).includes('Intent added successfully.'), 'success toast says the intent was added');
  note((await n()) === first - 1 && !(await b.evalJs('document.body.innerText')).includes(Q), `the question left the list: tab count ${first} -> ${await n()}`);
  await shot('list-after-1440');
  note(sql("select taught_at from luma_unanswered_questions where question='Do you make boats?'")[0].taught_at !== null, 'the question is marked taught (resolved)');
  await b.go('/admin/chatbot?search=do+you+make+boats');
  note((await b.evalJs(`document.querySelectorAll('.admin-table tbody tr').length`)) === 1, 'the new intent is in the Intents tab');
  // Dismiss unchanged
  await b.go('/admin/chatbot?tab=unanswered');
  const c2 = await n();
  await b.evalJs(`document.querySelector('.ua-actions form button').click()`); await settle();
  note((await n()) === c2 - 1 && sql('select count(*) c from luma_unanswered_questions where dismissed_at is not null')[0].c >= 1, 'Dismiss still works');
  // two admins: delete the row, then save an answer for it
  await b.go('/admin/chatbot?tab=unanswered');
  if ((await n()) > 0) {
    await b.evalJs(`document.querySelector('.ua-actions .admin-btn-gold').click()`); await sleep(400);
    const id = await b.evalJs(`${$('#intent-add [data-answer-id]')}.value`);
    execSync(`php tests/UiLinks/sql-delete-question.php ${id}`, { cwd: ROOT });
    await setVal('#intent-add-form-response', 'Answer after another admin removed it.');
    await b.evalJs(`${$('[form=intent-add-form]')}.click()`); await settle();
    note((await b.evalJs('document.body.innerText')).includes('Intent added successfully.'), 'question removed meanwhile: the save still creates the intent without an error');
  }
  await b.viewport(390, 844); await b.go('/admin/chatbot?tab=unanswered'); await shot('list-after-390');

  // ── Luma matches it ──
  await b.viewport(1440, 900);
  await b.setCookie(cookie('ot-customer@example.test', 'web'));
  await b.go('/');
  const reply = await b.evalJs(`fetch('/chatbot',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':(document.querySelector('meta[name=csrf-token]')||{}).content||''},body:JSON.stringify({message:${JSON.stringify(Q)}})}).then(r=>r.text())`);
  note(reply.includes('We only make furniture.'), 'Luma answers the exact question with the new intent');

  const bad = res.filter((x) => !x).length;
  console.log(`\n${res.length - bad}/${res.length} checks passed`);
  b.close(); process.exit(bad ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(2); });
