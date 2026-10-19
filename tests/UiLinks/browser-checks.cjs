/*
 * Stage 21h browser walk: screenshots + automatic checks for every page touched in this stage.
 * SCRATCH DATABASE ONLY. Prerequisites:
 *
 *   DB_DATABASE=furniture_db_test php artisan migrate:fresh      (scratch database)
 *   DB_DATABASE=furniture_db_test php tests/Rbac/seed_screenshots.php
 *   powershell -File tests/Rbac/serve_test.ps1                   (serves on http://127.0.0.1:8011, refuses non-_test databases)
 *   node --experimental-websocket tests/UiLinks/browser-checks.cjs
 *
 * Drives a headless Chrome over the DevTools protocol (no extra packages): signs in as the demo customer and admin from
 * storage/app/screenshot-login.json (git-ignored scratch file), visits each scenario at 390px and 1280px, saves a JPEG into
 * docs/stage21h-screenshots/ and runs, on every page: horizontal overflow, 44px tap targets at 390px, button contrast
 * (tests/ButtonContrast/measure.js), the link audit and contrast (tests/UiLinks/measure-links.js) and, where a stepper is
 * present, the stepper alignment check (tests/UiLinks/stepper-align.js). Writes docs/stage21h-screenshots/_checks.json.
 */
const fs = require('fs');
const path = require('path');
const http = require('http');
const { spawn } = require('child_process');

const ROOT = path.resolve(__dirname, '..', '..');
const OUT = path.join(ROOT, 'docs', 'stage21h-screenshots');
const BASE = process.env.BASE || 'http://127.0.0.1:8011';
const CHROME = process.env.CHROME || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const login = JSON.parse(fs.readFileSync(path.join(ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
const ids = login.ids;
const read = (f) => fs.readFileSync(path.join(__dirname, f), 'utf8');
const BUTTONS = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
const LINKS = read('measure-links.js');
const STEPPER = read('stepper-align.js');

fs.mkdirSync(OUT, { recursive: true });
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const getJson = (url) => new Promise((res, rej) => http.get(url, (r) => { let d = ''; r.on('data', (c) => (d += c)); r.on('end', () => { try { res(JSON.parse(d)); } catch (e) { rej(e); } }); }).on('error', rej));

async function main() {
  const profile = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-chrome-'));
  const chrome = spawn(CHROME, ['--headless=new', '--remote-debugging-port=9333', `--user-data-dir=${profile}`, '--no-first-run', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });
  let target;
  for (let i = 0; i < 50 && !target; i++) {
    await sleep(200);
    try { target = (await getJson('http://127.0.0.1:9333/json')).find((t) => t.type === 'page'); } catch (e) { /* not up yet */ }
  }
  if (!target) throw new Error('Chrome did not start');

  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise((r) => (ws.onopen = r));
  let n = 0;
  const pending = new Map();
  const events = [];
  ws.onmessage = (m) => {
    const d = JSON.parse(m.data);
    if (d.id && pending.has(d.id)) { const { res, rej } = pending.get(d.id); pending.delete(d.id); d.error ? rej(new Error(JSON.stringify(d.error))) : res(d.result); } else if (d.method) events.push(d.method);
  };
  const send = (method, params = {}) => new Promise((res, rej) => { const id = ++n; pending.set(id, { res, rej }); ws.send(JSON.stringify({ id, method, params })); });
  const evalJs = async (expr) => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    if (r.exceptionDetails) throw new Error('JS error: ' + (r.exceptionDetails.exception?.description || r.exceptionDetails.text));
    return r.result.value;
  };
  await send('Page.enable');
  await send('Runtime.enable');

  const { execFileSync } = require('child_process');
  const tmp = fs.mkdtempSync(path.join(require('os').tmpdir(), 'lp-pages-'));
  let who = 'admin';
  const go = async (url) => {
    events.length = 0;
    let target = BASE + url;
    if (who === 'customer') {
      // The customer sign-in form is protected by reCAPTCHA, which must not be bypassed: render the page through the app kernel instead.
      const file = path.join(tmp, 'page-' + Buffer.from(url).toString('hex').slice(0, 40) + '.html');
      const status = execFileSync('php', [path.join(__dirname, 'render-customer.php'), url, file], { cwd: ROOT, encoding: 'utf8' });
      if (status.trim() !== '200') console.log('  (render status ' + status.trim() + ' for ' + url + ')');
      target = 'file:///' + file.replace(/\\/g, '/');
    }
    await send('Page.navigate', { url: target });
    for (let i = 0; i < 100; i++) { await sleep(100); if (events.includes('Page.loadEventFired')) break; }
    await sleep(350);
  };

  // sign in both guards (same cookie jar)
  await go('/login');
  const signIn = async (path, email, password) => evalJs(`(async () => {
    const page = await (await fetch('${path}')).text();
    const tok = page.match(/name="_token" value="([^"]+)"/)[1];
    const r = await fetch('${path}', { method: 'POST', body: new URLSearchParams({ _token: tok, email: '${email}', password: '${password}' }), redirect: 'manual', credentials: 'same-origin' });
    return r.type + r.status;
  })()`);
  // Signing in on one guard can end the other guard's session, so each actor is signed in right before its own pages.
  const as = async (actor) => {
    who = 'admin';
    await go('/login');
    if (actor === 'admin') console.log('admin sign-in:', await signIn('/admin/login', login.admin_email, login.admin_password));
    who = actor;
  };

  const t = (k) => ids[k];
  const A = '/admin';
  const scenarios = [
    // admin Work panel, the four states, plus the modal
    { name: 'admin-work-no-invoice', url: `${A}/service-inquiries/${t('work_no_invoice').ticket}` },
    { name: 'admin-work-waiting-for-payment', url: `${A}/service-inquiries/${t('work_waiting').ticket}` },
    { name: 'admin-work-ready-to-start', url: `${A}/service-inquiries/${t('work_ready').ticket}` },
    { name: 'admin-work-started', url: `${A}/service-inquiries/${t('started').ticket}` },
    { name: 'admin-start-work-modal', url: `${A}/service-inquiries/${t('work_ready').ticket}`, pre: `document.querySelector('[data-lp-modal-open="si-start-modal"]').click()`, modal: true },
    { name: 'admin-list-ready-to-start', url: `${A}/service-inquiries?ready=1` },
    // half and half control
    { name: 'admin-half-half-disabled', url: `${A}/service-inquiries/${t('work_no_invoice').ticket}/invoices/create`, pre: `(() => { const c = document.getElementById('service_cost'); c.value = '1200'; c.dispatchEvent(new Event('input', { bubbles: true })); })()` },
    { name: 'admin-half-half-enabled', url: `${A}/service-inquiries/${t('work_no_invoice').ticket}/invoices/create`, pre: `(() => { const c = document.getElementById('service_cost'); c.value = '3500'; c.dispatchEvent(new Event('input', { bubbles: true })); })()` },
    // admin refund detail and the mark-sent modal with the override
    { name: 'admin-refund-detail-new', url: `${A}/refund-requests/${t('new').req}` },
    { name: 'admin-refund-detail-approved', url: `${A}/refund-requests/${t('approved').req}` },
    { name: 'admin-mark-sent-modal-override', url: `${A}/refund-requests/${t('approved').req}`,
      pre: `(() => { const tx = document.getElementById('mr-tx'); tx.value = 'GCASH-ABC'; tx.dispatchEvent(new Event('input', { bubbles: true }));
        const b = document.getElementById('mr-override'); b.checked = true; const r = document.getElementById('mr-override-reason'); r.value = 'The receipt shows this number clearly';
        document.querySelector('#mr-form button[type=submit]').click(); })()`, modal: true },
    // customer: notification details
    { name: 'customer-notification-cancelled', url: `/notifications/${t('notifications').cancelled}` },
    { name: 'customer-notification-refund', url: `/notifications/${t('notifications').refund}` },
    { name: 'customer-notification-declined', url: `/notifications/${t('notifications').declined}` },
    { name: 'customer-notification-list', url: '/notifications' },
    // customer: the form with and without GCash, the confirm modals
    { name: 'customer-form-without-gcash', url: `/services/requests/${t('half_waiting').ticket}/cancellation` },
    { name: 'customer-form-with-gcash', url: `/services/requests/${t('half_waiting').ticket}/cancellation`, pre: `document.querySelector('input[name=refund_method][value=gcash]').click()` },
    { name: 'customer-confirm-modal-gcash', url: `/services/requests/${t('half_waiting').ticket}/cancellation`,
      pre: `(() => { document.getElementById('rq-reason').value = 'change_of_mind'; document.querySelector('input[name=refund_method][value=gcash]').click();
        document.getElementById('rq-gnum').value = '09181112222'; document.getElementById('rq-gname').value = 'Maria S.'; document.getElementById('rq-terms').checked = true;
        document.querySelector('#rq-form button[type=submit]').click(); })()`, modal: true },
    { name: 'customer-confirm-modal-ticked', url: `/services/requests/${t('half_waiting').ticket}/cancellation`,
      pre: `(() => { document.getElementById('rq-reason').value = 'change_of_mind'; document.querySelector('input[name=refund_method][value=cash]').click(); document.getElementById('rq-terms').checked = true;
        document.querySelector('#rq-form button[type=submit]').click(); setTimeout(() => { const c = document.getElementById('rq-checked'); c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); }, 150); })()`, modal: true, wait: 400 },
    { name: 'customer-instant-cancel-modal', url: `/services/requests/${t('unpaid').ticket}/cancellation`,
      pre: `(() => { document.getElementById('rq-reason').value = 'change_of_mind'; document.getElementById('rq-terms').checked = true; document.querySelector('#rq-form button[type=submit]').click(); })()`, modal: true },
    { name: 'customer-confirmation-page', url: `/services/refunds/${t('new').ref}/sent` },
    // customer: the status page, every stepper variant
    { name: 'customer-status-under-review', url: `/services/refunds/${t('new').ref}`, stepper: true },
    { name: 'customer-status-needs-info', url: `/services/refunds/${t('needs_info').ref}`, stepper: true },
    { name: 'customer-status-approved', url: `/services/refunds/${t('approved').ref}`, stepper: true },
    { name: 'customer-status-refund-problem', url: `/services/refunds/${t('problem').ref}`, stepper: true },
    { name: 'customer-status-refunded', url: `/services/refunds/${t('refunded').ref}`, stepper: true },
    { name: 'customer-status-declined', url: `/services/refunds/${t('declined').ref}`, stepper: true },
    { name: 'customer-status-cancelled-instantly', url: `/services/refunds/${t('instant_closed').ref}`, stepper: true },
    // customer: My requests
    { name: 'customer-my-requests', url: '/services/request' },
  ];

  const results = [];
  for (const actor of ['admin', 'customer']) {
  await as(actor);
  for (const vp of [1280, 390]) {
    await send('Emulation.setDeviceMetricsOverride', { width: vp, height: vp === 390 ? 844 : 900, deviceScaleFactor: 1, mobile: vp < 500 });
    for (const s of scenarios.filter((x) => x.name.startsWith('admin') === (actor === 'admin'))) {
      await go(s.url);
      if (s.pre) {
        for (let i = 0; i < 30 && (await evalJs('document.readyState')) !== 'complete'; i++) await sleep(100);
        try { await evalJs(s.pre); } catch (e) { console.log('  pre failed for ' + s.name + ': ' + String(e.message).slice(0, 120) + ' @ ' + (await evalJs('location.pathname'))); }
        await sleep(s.wait || 350);
      }
      if (s.modal) {
        const open = await evalJs(`!!document.querySelector('.lp-modal:not([hidden])')`);
        if (!open) console.log('  (modal did not open for ' + s.name + ')');
      }
      const check = await evalJs(`(() => {
        const over = document.documentElement.scrollWidth - document.documentElement.clientWidth;
        const small = [...document.querySelectorAll('.lp-btn, button, a.lp-link--tap, .lp-modal__x, .lp-modal__check, .nd-chip')].filter((e) => e.getClientRects().length && !e.closest('[hidden]') && !e.closest('nav, header, footer, .chatbot-window, .admin-sidebar') && !e.matches('.lp-btn--chip:not(.nd-copy)'))
          .map((e) => ({ t: (e.innerText || e.getAttribute('aria-label') || '').trim().slice(0, 30), h: Math.round(e.getBoundingClientRect().height) })).filter((x) => x.h < 44);
        return { title: document.title, overflow: over, short: small, modalOpen: !!document.querySelector('.lp-modal:not([hidden])'), details: !!document.querySelector('details[open]') };
      })()`);
      const buttons = await evalJs(BUTTONS);
      const links = await evalJs(LINKS);
      let stepper = null;
      if (s.stepper) stepper = await evalJs(STEPPER);
      const metrics = await send('Page.getLayoutMetrics');
      const h = Math.min(Math.ceil(metrics.cssContentSize.height), 5000);
      const shot = await send('Page.captureScreenshot', { format: 'jpeg', quality: 78, captureBeyondViewport: true, clip: { x: 0, y: 0, width: vp, height: h, scale: 1 } });
      const file = `${s.name}-${vp}.jpg`;
      fs.writeFileSync(path.join(OUT, file), Buffer.from(shot.data, 'base64'));
      results.push({
        file, title: check.title, overflow: check.overflow, short: vp === 390 ? check.short : [],
        buttons: buttons.count, buttonFailures: buttons.failures.filter((f) => f.text !== 'Close alert').map((f) => ({ text: f.text, min: f.min })),
        linkFailures: links.withoutStyle.length + links.contrast.filter((c) => !c.ok).length, links: links.links, linkContrastMin: links.contrast.length ? Math.min(...links.contrast.map((c) => Math.min(c.default, c.hover))) : null,
        stepper: stepper ? { pass: stepper.pass, checked: stepper.checked, failures: stepper.failures } : undefined,
      });
      const r = results[results.length - 1];
      const bad = r.overflow > 0 || r.short.length || r.buttonFailures.length || r.linkFailures || (r.stepper && !r.stepper.pass);
      console.log((bad ? 'CHECK ' : 'ok    ') + file, bad ? JSON.stringify({ o: r.overflow, s: r.short, b: r.buttonFailures, l: r.linkFailures, st: r.stepper && r.stepper.failures.slice(0, 2) }) : '');
    }
  }
  }
  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify(results, null, 2));
  ws.close();
  chrome.kill();
}

main().catch((e) => { console.error(e); process.exit(1); });
