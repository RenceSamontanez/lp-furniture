/*
 * Installments walk (SCRATCH DATABASE ONLY): checkout eligibility and consent boxes, the order-page plan, the admin
 * Installments page (tabs, Details, Simulate/Mark Paid modals) and the admin Customer Profile (Adjust Standing reopen-on-error
 * and discard guard).
 *   php tests/Rbac/seed_screenshots.php ; php tests/UiLinks/seed_installments.php        (run phpunit BEFORE seeding: it wipes the scratch DB)
 *   powershell -File tests/Rbac/serve_test.ps1                                          (serves the scratch DB on :8011)
 *   BASE=http://127.0.0.1:8011 node --experimental-websocket tests/UiLinks/browser-installments.cjs [functional|shots|all]
 * With the sandbox gateway (default while the key is sk_test_) the walk presses "Run It" with each outcome: the simulated
 * charge never calls PayMongo. With INSTALLMENT_GATEWAY=paymongo the server shows no outcome choice and the walk checks that.
 * The customer order page check also looks for the "Sandbox: simulated charge" label.
 */
const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const lib = require('./cdp-lib.cjs');

const MODE = process.argv[2] || 'all';
const OUT = path.join(lib.ROOT, 'docs', 'admin', 'screenshots-installments');
fs.mkdirSync(OUT, { recursive: true });

(async () => {
  const c = await lib.start(9397);
  const results = [];
  const note = (name, ok, detail) => { results.push({ name, ok }); console.log((ok ? 'ok    ' : 'CHECK ') + name, detail === undefined ? '' : JSON.stringify(detail)); };
  const shot = async (name) => { const r = await c.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const logins = JSON.parse(fs.readFileSync(path.join(lib.ROOT, 'storage', 'app', 'screenshot-login.json'), 'utf8'));
  const $js = (e) => c.evalJs(e);
  const cookie = (email, guard) => JSON.parse(execFileSync('php', [path.join(lib.ROOT, 'tests', 'UiLinks', 'session-cookie-for.php'), email, guard], { cwd: lib.ROOT, encoding: 'utf8' }));
  const overflow = () => $js('document.documentElement.scrollWidth - document.documentElement.clientWidth');
  const click = async (sel) => { await $js(`document.querySelector(${JSON.stringify(sel)}).click()`); await c.sleep(350); };

  try {
    if (MODE === 'functional' || MODE === 'all') {
      // ── checkout: three kinds of customer ──
      await c.viewport(1440, 900);
      for (const [who, expectLevel, expectBlocked] of [['gina', 'Good', false], ['fely', 'Fair', true], ['susan', 'Suspended', true]]) {
        await c.setCookie(cookie(`${who}@walk-installments.test`, 'web'));
        await c.go('/checkout');
        const state = await $js(`(() => ({ lvl: document.querySelector('.ckn-lvl')?.textContent, off: document.getElementById('pt-installment')?.disabled, note: document.querySelector('.ckn-elig-note')?.textContent }))()`);
        note(`checkout ${who}: standing ${expectLevel}, installments ${expectBlocked ? 'disabled with a plain reason' : 'available'}`, state.lvl === expectLevel && state.off === expectBlocked && (!expectBlocked || /pay in full/i.test(state.note)), state);
        if (!expectBlocked) {
          await $js(`document.getElementById('pt-installment').click()`);
          await c.sleep(200);
          const m = await $js(`(() => ({ stripe: getComputedStyle(document.getElementById('label-stripe')).display, onsite: getComputedStyle(document.getElementById('label-onsite')).display, card: getComputedStyle(document.getElementById('label-card')).display,
            auto: getComputedStyle(document.getElementById('ckn-consent-card')).display, terms: getComputedStyle(document.getElementById('ckn-consent-terms')).display, method: document.querySelector('input[name=payment_method]:checked').value }))()`);
          note('installment: Stripe and Pay On-Site hidden, Card chosen, both consent boxes shown', m.stripe === 'none' && m.onsite === 'none' && m.card !== 'none' && m.auto !== 'none' && m.terms !== 'none' && m.method === 'paymongo_card', m);
          await click('.ckn-linkbtn');
          note('terms summary modal opens and links to the full page', await $js(`!document.getElementById('inst-terms').hidden && !!document.querySelector('#inst-terms a[href$="/installment-terms"]')`));
          await $js(`document.querySelector('#inst-terms [data-lp-modal-close]').click()`);
          await c.sleep(200);
          await $js(`document.querySelector('input[name=payment_type][value=full]').click()`);
          await c.sleep(150);
          note('back to full payment: Stripe and Pay On-Site return, card and consent hide', await $js(`getComputedStyle(document.getElementById('label-stripe')).display !== 'none' && getComputedStyle(document.getElementById('label-card')).display === 'none' && getComputedStyle(document.getElementById('ckn-consent-terms')).display === 'none'`));
        }
      }

      // ── checkout: payment type cards + the "Action needed" banner, at desktop and phone width ──
      for (const w of [1440, 390]) {
        await c.viewport(w, 900);
        await c.setCookie(cookie('gina@walk-installments.test', 'web'));
        await c.go('/checkout');
        const phone = w < 769;
        // Phone: the real route (Preparing -> Review -> Delivery -> Payment Type -> Payment Method); desktop: the accordion.
        const seq = ['review', 'delivery', 'paytype', 'paymethod'];
        let at = -1;
        const openStep = async (name) => {
          if (!phone) { return $js(`(() => { openSection('${name}'); return true; })()`); }
          if (at < 0) { await c.sleep(1800); await $js(`startCheckoutTransition()`); await c.sleep(900); at = 0; }
          const to = seq.indexOf(name);
          while (at < to) { await $js(`pushToScreen('${seq[at]}', '${seq[at + 1]}')`); await c.sleep(900); at++; }
        };
        // is the element really the topmost thing at its centre (not hidden under a panel or overlay)?
        const onTop = (id) => `(() => { const e = document.getElementById('${id}'); const r = e.getBoundingClientRect(); const t = document.elementFromPoint(r.left + r.width / 2, Math.min(Math.max(r.top + r.height / 2, 1), window.innerHeight - 1)); return !!t && e.contains(t); })()`;

        // payment type: pick the installment plan and look at the cards
        await openStep('paytype');
        await $js(`document.getElementById('pt-installment').click()`);
        await c.sleep(250);
        note(`checkout ${w}: no horizontal overflow (installment plan chosen)`, (await overflow()) <= 0, await overflow());
        const geo = await $js(`(() => {
          const card = document.getElementById('label-installment'); const cr = card.getBoundingClientRect();
          const bad = [...card.querySelectorAll('*')].filter((e) => { const r = e.getBoundingClientRect(); return r.width && (r.right > cr.right + 0.5 || r.left < cr.left - 0.5); }).map((e) => e.className || e.tagName);
          const full = document.getElementById('label-full').getBoundingClientRect();
          const freq = [...document.querySelectorAll('.installment-grid--freq .installment-chip')].map((e) => e.getBoundingClientRect().height);
          const rows = [...document.querySelectorAll('.installment-summary-row')].map((r) => { const rr = r.getBoundingClientRect(); const s = r.querySelector('strong').getBoundingClientRect(); return s.right <= rr.right + 0.5; });
          return { bad, sameTop: Math.abs(full.top - cr.top) < 2, stacked: cr.top >= full.bottom - 1, fullH: Math.round(full.height), instH: Math.round(cr.height), freq, rows, summary: document.getElementById('installment-summary-box').innerText.replace(/\\s+/g, ' ') };
        })()`);
        note(`checkout ${w}: nothing in the installment card extends past its border`, geo.bad.length === 0, geo.bad);
        note(`checkout ${w}: summary amounts stay inside their rows ("each" text included)`, geo.rows.length >= 2 && geo.rows.every(Boolean) && /each/.test(geo.summary), geo.summary);
        note(`checkout ${w}: frequency buttons are one line each ("Every 1 Month (30 Days)")`, geo.freq.length === 2 && geo.freq.every((h) => h < 48), geo.freq);
        note(`checkout ${w}: ${phone ? 'the two cards stack' : 'Full Payment card is as short as its content, not stretched'}`, phone ? geo.stacked : geo.fullH < geo.instH, geo);

        await shot(`checkout-paytype-${w}`);
        // Continue without ticking the terms: banner visible + focused, box outlined, message under it
        await openStep('paymethod');
        await $js(`document.querySelector('input[name=payment_method][value=paymongo_card]').click()`);
        await c.sleep(200);
        await $js(`(document.querySelector('#screen-paymethod ${phone ? '.checkout-screen-continue' : '.ckn-continue-btn'}')).click()`);
        await c.sleep(2000);
        const al = await $js(`(() => {
          const a = document.getElementById('ckn-alert-local'); const r = a.getBoundingClientRect(); const box = document.getElementById('accept-installment-terms').closest('.ckn-consent'); const m = document.getElementById('err-accept-terms');
          return { shown: !a.hidden, role: a.getAttribute('role'), focused: document.activeElement === a, inView: r.top >= 0 && r.bottom <= window.innerHeight, top: ${onTop('ckn-alert-local')}, text: a.innerText, boxRed: box.classList.contains('has-error'), under: !m.hidden && /Installment Terms/.test(m.innerText), title: /Action needed/.test(a.innerText), top: Math.round(r.top), bottom: Math.round(r.bottom), vh: window.innerHeight, scrollY: Math.round(window.scrollY), w: Math.round(r.width), vw: window.innerWidth };
        })()`);
        note(`checkout ${w}: Continue without the terms shows the alert, focused and in view`, al.shown && al.role === 'alert' && al.focused && al.inView && al.top && al.title && /accept the Installment Terms/.test(al.text), al);
        note(`checkout ${w}: the unticked box is outlined and the message sits under it`, al.boxRed && al.under);
        note(`checkout ${w}: the banner fits the screen`, al.w <= al.vw && (await overflow()) <= 0, al);
        if (w === 1440 || w === 390) { await shot(`checkout-alert-${w}`); }
        await $js(`document.getElementById('accept-installment-terms').click()`);
        await c.sleep(150);
        note(`checkout ${w}: ticking the terms clears the outline`, await $js(`!document.getElementById('accept-installment-terms').closest('.ckn-consent').classList.contains('has-error')`));
        await $js(`document.getElementById('accept-installment-terms').click()`); // untick again for the server-side round trip
        await c.sleep(100);

        // Server redirect-back: submit past the browser check; the banner comes from the server, focused, box outlined
        await $js(`HTMLFormElement.prototype.submit.call(document.getElementById('checkout-form'))`);
        await c.sleep(2500);
        const sv = await $js(`(() => {
          const a = document.getElementById('${phone ? 'ckn-alert-local' : 'ckn-alert'}'); const r = a.getBoundingClientRect(); const box = document.getElementById('accept-installment-terms').closest('.ckn-consent');
          return { shown: !a.hidden && r.height > 0, role: a.getAttribute('role'), focused: document.activeElement === a, inView: r.top >= -1 && r.bottom <= window.innerHeight + 1, top: ${onTop(phone ? 'ckn-alert-local' : 'ckn-alert')}, text: a.innerText, boxRed: box.classList.contains('has-error'), kept: document.getElementById('pt-installment').checked, w: Math.round(r.width), vw: window.innerWidth };
        })()`);
        note(`checkout ${w}: server error comes back as the same banner, focused and in view`, sv.shown && sv.role === 'alert' && sv.focused && sv.inView && sv.top && /Action needed/.test(sv.text) && /accept the Installment Terms/.test(sv.text), sv);
        note(`checkout ${w}: after the server error the plan choice is kept and the terms box is outlined`, sv.kept && sv.boxRed);
        note(`checkout ${w}: server banner fits the screen`, sv.w <= sv.vw && (await overflow()) <= 0, sv);
      }
      await c.viewport(1440, 900);

      // ── the public terms page ──
      await c.go('/installment-terms');
      note('terms page: public, shows version and the levels', await $js(`/Version/.test(document.body.innerText) && /Excellent/.test(document.body.innerText) && /Suspended/.test(document.body.innerText)`));

      // ── customer order page ──
      await c.setCookie(cookie('maria@walk-installments.test', 'web'));
      const oid = await $js(`fetch('/orders').then(r => r.text()).then(t => (t.match(/orders\\/(\\d+)/) || [])[1])`);
      await c.go(`/orders/${oid}`);
      note('order page: plan, Pay Now, Pay Remaining Balance, Update Card, standing', await $js(`['Payment Plan', 'Pay Now', 'Pay Remaining Balance In Full', 'Update Card', 'Your Installment Standing'].every((t) => document.body.innerText.includes(t))`));
      note('order page: "Sandbox: simulated charge" label on the simulated card', await $js(`/Sandbox: simulated charge/.test(document.body.innerText)`));
      await click('[data-lp-modal-open="inst-paynow"]');
      note('Pay Now modal shows the saved card and GCash', await $js(`!document.getElementById('inst-paynow').hidden && /Visa ending 4345/.test(document.getElementById('inst-paynow').innerText) && /GCash/.test(document.getElementById('inst-paynow').innerText)`));
      note('order page: no horizontal scroll', (await overflow()) <= 0, await overflow());

      // ── admin ──
      await c.setCookie(cookie(logins.admin_email, 'admin'));
      await c.go('/admin/installments');
      note('admin: summary cards and four tabs', (await $js(`document.querySelectorAll('.ia-sumc > div').length`)) === 4 && (await $js(`document.querySelectorAll('.ia-tab').length`)) === 4);
      await c.go('/admin/installments?tab=at');
      note('Needs Attention lists failed, pay-link and overdue rows', await $js(`[...document.querySelectorAll('#ia-rows .ist')].map((s) => s.textContent.trim()).every((t) => ['Failed, Will Retry', 'Pay Link Sent', 'Overdue', 'Processing'].includes(t)) && document.querySelectorAll('#ia-rows tbody tr').length >= 3`));
      await click('[data-ia-detail]');
      note('Details modal shows the attempts log', await $js(`!document.getElementById('ia-det').hidden && /History/.test(document.getElementById('ia-det-body').innerText) && document.querySelectorAll('#ia-det-body .ia-step').length >= 2`));
      await $js(`document.querySelector('#ia-det [data-lp-modal-close]').click()`);
      await c.sleep(200);
      const hasSim = await $js(`!!document.querySelector('[data-ia-sim]')`);
      if (hasSim) { await click('[data-ia-sim]'); note('Simulate modal opens (test keys)', await $js(`!document.getElementById('ia-sim').hidden && /Run It/.test(document.getElementById('ia-sim').innerText)`)); await $js(`document.querySelector('#ia-sim [data-lp-modal-close]').click()`); await c.sleep(200); }
      else { note('no Simulate buttons (live-key mode)', true); }

      // ── sandbox: outcome choice on Simulate Due Date, run through the real engine (never calls PayMongo) ──
      const simRow = async (tab, statusText) => {
        await c.go(`/admin/installments?tab=${tab}`);
        return $js(`(() => { const r = [...document.querySelectorAll('#ia-rows tbody tr')].find((t) => t.textContent.includes('Maria Santos') && t.querySelector('.ist')?.textContent.trim() === ${JSON.stringify(statusText)}); const b = r && r.querySelector('[data-ia-sim]'); if (!b) return false; b.setAttribute('data-walk-sim', '1'); return true; })()`);
      };
      const sandboxOn = await $js(`/Sandbox gateway/.test(document.body.innerText)`);
      if (sandboxOn) {
        note('admin: sandbox banner and "Sandbox: simulated charge" label on the card plan', await $js(`!!document.querySelector('.ia-sbx')`));
        note('Maria pending installment has a Simulate button', await simRow('up', 'Pending'));
        await click('[data-walk-sim]');
        note('Simulate modal shows the three outcomes', await $js(`(() => { const box = document.querySelector('[data-ia-outcome-box]'); return !box.hidden && [...box.querySelectorAll('input[name=outcome]')].map((i) => i.value).join() === 'success,declined,insufficient' && /Charge Fails/.test(box.innerText) && /Insufficient Funds/.test(box.innerText); })()`));
        await $js(`document.querySelector('[data-ia-outcome-box] input[value=declined]').click()`);
        await $js(`document.getElementById('ia-sim-form').submit()`);
        await c.sleep(1800);
        note('Charge Fails: the installment is Failed and will retry', await $js(`/It is now: Failed/i.test(document.body.innerText)`));
        note('Maria Failed row shows the Simulate Next Day button', await simRow('at', 'Failed, Will Retry'));
        await click('[data-walk-sim]');
        await $js(`document.querySelector('[data-ia-outcome-box] input[value=insufficient]').click()`);
        await $js(`document.getElementById('ia-sim-form').submit()`);
        await c.sleep(1800);
        note('Insufficient Funds: second failure sends the Pay Now link', await $js(`/It is now: Pay Link Sent/i.test(document.body.innerText)`));
        await c.go('/admin/installments?tab=at');
        await $js(`(() => { const r = [...document.querySelectorAll('#ia-rows tbody tr')].find((t) => t.textContent.includes('Maria Santos') && t.querySelector('.ist')?.textContent.trim() === 'Pay Link Sent'); r.querySelector('[data-ia-detail]').setAttribute('data-walk-det', '1'); })()`);
        await click('[data-walk-det]');
        note('Details attempts log: SIM label and the insufficient funds message', await $js(`/insufficient funds/i.test(document.getElementById('ia-det-body').innerText) && /Sandbox: simulated charge/.test(document.getElementById('ia-det-body').innerText)`));
        await $js(`document.querySelector('#ia-det [data-lp-modal-close]').click()`);
        await c.sleep(200);
        note('Simulate Overdue button has no outcome choice', await (async () => { await simRow('at', 'Pay Link Sent'); await click('[data-walk-sim]'); return $js(`document.querySelector('[data-ia-outcome-box]').hidden === true`); })());
        await $js(`document.querySelector('#ia-sim [data-lp-modal-close]').click()`);
        await c.sleep(200);
        // success on a fresh pending installment (Maria's #3)
        await c.go('/admin/installments?tab=up');
        note('Charge Succeeds: paid by the simulated card, SIM- reference in the ledger', await (async () => {
          const ok = await $js(`(() => { const r = [...document.querySelectorAll('#ia-rows tbody tr')].find((t) => t.textContent.includes('Maria Santos') && t.querySelector('[data-ia-sim]')); if (!r) return false; r.querySelector('[data-ia-sim]').setAttribute('data-walk-sim', '1'); return true; })()`);
          if (!ok) { return false; }
          await click('[data-walk-sim]');
          await $js(`document.querySelector('[data-ia-outcome-box] input[value=success]').click()`);
          await $js(`document.getElementById('ia-sim-form').submit()`);
          await c.sleep(1800);
          return $js(`/It is now: Paid/i.test(document.body.innerText)`);
        })());
      } else { note('gateway is not the sandbox (live-style key): no outcome choice expected', !(await $js(`!!document.querySelector('[data-ia-outcome-box]')`))); }
      await c.go('/admin/installments?tab=at'); // back to the page the Mark Paid steps below expect
      await click('[data-ia-mark]:not([data-ia-detail])');
      note('Mark Paid modal asks how it was paid', await $js(`!document.getElementById('ia-mark').hidden && !!document.querySelector('#ia-mark select[name=method]')`));
      await $js(`document.querySelector('#ia-mark [data-lp-modal-close]').click()`);
      await c.sleep(200);

      // ── admin Customer Profile ──
      const mid = await $js(`new URL(document.querySelector('.ia-link').href).pathname`);
      await c.go(mid);
      note('profile: level, score gauge, stat cards, both tabs', await $js(`!!document.querySelector('.cpx-gauge') && document.querySelectorAll('.cpx-stats > div').length === 3 && document.querySelectorAll('.cpx-tab').length === 2 && !!document.querySelector('.lvl')`));
      await click('[data-lp-modal-open="ia-adjust"]');
      await $js(`(() => { const r = document.getElementById('ia-reason'); r.value = ''; })()`);
      await click('#ia-adjust button[type=submit]');
      await c.sleep(1200);
      note('Adjust Standing with no reason is refused and the same modal reopens', await $js(`!document.getElementById('ia-adjust').hidden && /reason/i.test(document.getElementById('ia-adjust').innerText)`));
      await c.go(mid + (mid.includes('?') ? '&' : '?') + 'tab=standing');
      note('Standing History tab lists points and score after', await $js(`/Score After/.test(document.body.innerText)`));
      note('profile: no horizontal scroll at 1440', (await overflow()) <= 0, await overflow());
    }

    if (MODE === 'shots' || MODE === 'all') {
      for (const w of [390, 1440]) {
        await c.viewport(w, 900);
        await c.setCookie(cookie(logins.admin_email, 'admin'));
        await c.go('/admin/installments?tab=at'); await shot(`installments-${w}`);
        const href = await $js(`new URL(document.querySelector('.ia-link').href).pathname`);
        await c.go(href); await shot(`profile-${w}`);
        note(`admin pages at ${w}: no horizontal scroll`, (await overflow()) <= 0, await overflow());
        await c.setCookie(cookie('gina@walk-installments.test', 'web'));
        await c.go('/checkout'); await shot(`checkout-${w}`);
        await c.setCookie(cookie('maria@walk-installments.test', 'web'));
        const oid = await $js(`fetch('/orders').then(r => r.text()).then(t => (t.match(/orders\\/(\\d+)/) || [])[1])`);
        await c.go(`/orders/${oid}`); await shot(`order-${w}`);
        note(`order page at ${w}: no horizontal scroll`, (await overflow()) <= 0, await overflow());
      }
    }
  } catch (e) {
    console.log('WALK ERROR', e.message);
    results.push({ name: 'walk', ok: false });
  } finally {
    c.close();
  }
  const bad = results.filter((r) => !r.ok);
  console.log(`\n${results.length - bad.length}/${results.length} checks ok`);
  process.exit(bad.length ? 1 : 0);
})();
