/*
 * Navbar stays visible while Luma's chat is open (SCRATCH DATABASE ONLY, http://127.0.0.1:8011). Screenshots: docs/navbar/screenshots/luma/.
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh --force
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   php tests/UiLinks/seed_notif_nav.php
 *   powershell -File tests/Rbac/serve_test.ps1
 *   node --experimental-websocket tests/UiLinks/browser-luma-nav.cjs
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const { start, ROOT } = require('./cdp-lib.cjs');

const OUT = path.join(ROOT, 'docs', 'navbar', 'screenshots', 'luma');
fs.mkdirSync(OUT, { recursive: true });
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const logins = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
const cookie = JSON.parse(execFileSync('php', [path.join(ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), logins.customer_email, 'web'], { cwd: ROOT, encoding: 'utf8' }));
const bad = [];
const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };

(async () => {
  const c = await start(9353);
  const { send, evalJs, go, viewport, setCookie, sleep } = c;
  const shot = async (name) => { const r = await send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const click = async (sel, wait = 1100) => {
    const p = await evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return null; const b = e.getBoundingClientRect(); return { x: b.left + b.width / 2, y: b.top + b.height / 2 }; })()`);
    if (!p) throw new Error('no element ' + sel);
    await send('Input.dispatchMouseEvent', { type: 'mouseMoved', x: p.x, y: p.y });
    await send('Input.dispatchMouseEvent', { type: 'mousePressed', x: p.x, y: p.y, button: 'left', clickCount: 1 });
    await send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: p.x, y: p.y, button: 'left', clickCount: 1 });
    await sleep(wait);
  };
  const key = async (k, code, vk) => { await send('Input.dispatchKeyEvent', { type: 'keyDown', key: k, code, windowsVirtualKeyCode: vk }); await send('Input.dispatchKeyEvent', { type: 'keyUp', key: k, code, windowsVirtualKeyCode: vk }); await sleep(300); };
  const vis = (sel) => evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return false; const s = getComputedStyle(e); return s.display !== 'none' && s.visibility !== 'hidden' && e.getBoundingClientRect().width > 0; })()`);
  const convo = () => evalJs(`[...document.querySelectorAll('#chat-body .chat-msg')].map(m => m.textContent.trim().slice(0, 40)).join('|')`);
  const navState = () => evalJs(`(() => { const n = document.getElementById('site-nav'), s = getComputedStyle(n), r = n.getBoundingClientRect(); return { opacity: s.opacity, vis: s.visibility, pe: s.pointerEvents, modalOpen: document.body.classList.contains('modal-open'), bottom: Math.round(r.bottom) }; })()`);
  // can a real click reach this element? (the topmost element at its centre is the element itself or inside it)
  const reachable = (sel) => evalJs(`(() => { const e = document.querySelector(${JSON.stringify(sel)}); if (!e) return 'missing'; const b = e.getBoundingClientRect(); if (!b.width) return 'zero'; const t = document.elementFromPoint(b.left + b.width / 2, b.top + b.height / 2); return t && (t === e || e.contains(t)) ? 'ok' : 'covered by ' + (t ? (t.id || t.className) : 'nothing'); })()`);

  await setCookie(cookie);

  async function walk(label, w, h) {
    const phone = w < 700;
    await viewport(w, h);
    await go('/catalog');
    await click('#chatbot-fab-btn', 700);
    await evalJs(`(() => { const i = document.getElementById('chat-input'); i.value = 'do you deliver to Batangas'; sendChat(); })()`);
    await sleep(2800);
    const msgs = await convo();
    note(/do you deliver/.test(msgs), `${label}: message typed in Luma (${msgs.split('|').length} messages)`);

    const ns = await navState();
    note(ns.opacity === '1' && ns.vis === 'visible' && ns.pe !== 'none' && !ns.modalOpen, `${label}: navbar visible with the chat open ${JSON.stringify(ns)}`);
    const geo = await evalJs(`(() => { const w = document.getElementById('chatbot-window').getBoundingClientRect(), n = document.getElementById('site-nav').getBoundingClientRect(); return { chatTop: Math.round(w.top), navBottom: Math.round(n.bottom), chatBottom: Math.round(w.bottom), vh: innerHeight }; })()`);
    note(geo.chatTop >= geo.navBottom + 8, `${label}: chat panel does not cover the navbar ${JSON.stringify(geo)}`);
    await shot(`${label}-1-chat-open-navbar-visible`);

    if (phone) {
      await click('#sn-burger', 500);
      const items = ['#sn-links a[href$="/orders"]', '#sn-bell', '#sn-links a[href$="/cart"]', '#sn-links a[href$="/profile"]', '#nav-chatbot-reactivate'];
      for (const s of items) note((await reachable(s)) === 'ok', `${label}: ${s} reachable with chat + menu open (${await reachable(s)})`);
      await shot(`${label}-2-chat-open-menu-open`);
      // My Orders really navigates
      await click('#sn-links a[href$="/orders"]', 1500);
      note((await evalJs('location.pathname')) === '/orders', `${label}: My Orders click goes to /orders (${await evalJs('location.pathname')})`);
      return;
    }

    // desktop / tablet: every nav item reachable with the chat open
    for (const s of ['#sn-links a[href$="/orders"]', '#sn-links a[href$="/cart"]', '#sn-bell', '#sn-links a[href$="/catalog"]', '#sn-links a[href$="/profile"]', '#nav-chatbot-reactivate']) {
      note((await reachable(s)) === 'ok', `${label}: ${s} reachable (${await reachable(s)})`);
    }

    // chat OPEN, click the bell with a real mouse
    await click('#sn-bell');
    note(await evalJs(`!document.getElementById('notif-dd').hidden`), `${label}: bell click opens the dropdown while the chat is open`);
    note(!(await vis('#chatbot-window')) && !(await vis('#chatbot-fab-btn')), `${label}: Luma (chat and launcher) hidden while the dropdown is open`);
    const ov = await evalJs(`(() => { const d = document.getElementById('notif-dd').getBoundingClientRect(), n = document.getElementById('site-nav').getBoundingClientRect(); return { top: Math.round(d.top), navBottom: Math.round(n.bottom) }; })()`);
    note(ov.top >= ov.navBottom, `${label}: dropdown sits under the bar ${JSON.stringify(ov)}`);
    await shot(`${label}-3-chat-open-bell-clicked-luma-hidden`);
    const audit = await evalJs(`(${BUTTONS.trim().replace(/;\s*$/, '')})`).catch(async () => evalJs(BUTTONS));
    note(((audit && audit.failures) || []).length === 0, `${label}: button contrast audit with the dropdown open: ${audit ? audit.count : '?'} buttons, ${((audit && audit.failures) || []).length} failures`);
    await key('Escape', 'Escape', 27);
    note(await evalJs(`document.getElementById('notif-dd').hidden`) && (await evalJs(`document.activeElement.id`)) === 'sn-bell', `${label}: Escape closes the dropdown, focus on the bell`);
    note(await evalJs(`document.getElementById('chatbot-window').classList.contains('open')`) && (await vis('#chatbot-window')), `${label}: chat is back OPEN (it was open)`);
    note((await convo()) === msgs, `${label}: same conversation after the dropdown closed`);
    await shot(`${label}-4-bell-closed-luma-back`);

    // chat CLOSED: bell, launcher hidden, then back and still closed, conversation kept
    await evalJs('closeChatbot()'); await sleep(400);
    await click('#sn-bell');
    note(!(await vis('#chatbot-fab-btn')) && !(await vis('#chatbot-window')), `${label}: launcher hidden while the dropdown is open (chat was closed)`);
    await click('#sn-bell');   // the bell toggles it shut
    note(await evalJs(`document.getElementById('notif-dd').hidden`), `${label}: bell click closes the dropdown`);
    note((await vis('#chatbot-fab-btn')) && !(await evalJs(`document.getElementById('chatbot-window').classList.contains('open')`)), `${label}: launcher back, chat still closed`);
    await evalJs('openChatbot()'); await sleep(500);
    note((await convo()) === msgs, `${label}: reopening shows the same conversation`);

    // chat open, click My Orders with a real mouse
    await click('#sn-links a[href$="/orders"]', 1500);
    note((await evalJs('location.pathname')) === '/orders', `${label}: My Orders click (chat open) goes to /orders`);
  }

  await walk('d1440', 1440, 900);
  await walk('d1440-short', 1440, 660);
  await walk('tablet1001', 1001, 800);
  await walk('phone390', 390, 844);
  await walk('phone390-short', 390, 667);

  console.log(bad.length ? `\n${bad.length} CHECK(S) FAILED` : '\nall checks passed');
  c.close();
  process.exitCode = bad.length ? 1 : 0;
})().catch((e) => { console.error(e); process.exit(2); });
