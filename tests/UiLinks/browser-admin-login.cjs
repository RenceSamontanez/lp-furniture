/*
 * Admin sign-in page: screenshots at 1366x768, 1832x974, tablet and phone, plus an error banner; checks that the
 * intro tiles hide under 860px, nothing overflows, the Show/Hide toggle works and the button sits at >= 44px.
 * SCRATCH DATABASE ONLY (server on 127.0.0.1:8011 started with DB_DATABASE=furniture_db_test).
 * Screenshots go to docs/stage-admin-login-screenshots/. Run tests/ButtonContrast/measure.js separately (results in the report).
 */
const fs = require('fs');
const path = require('path');
const { start, ROOT, sleep } = require('./cdp-lib.cjs');

(async () => {
  const b = await start(9353);
  const OUT = path.join(ROOT, 'docs', 'stage-admin-login-screenshots');
  fs.mkdirSync(OUT, { recursive: true });
  const shot = async (name) => { const r = await b.send('Page.captureScreenshot', { format: 'png' }); fs.writeFileSync(path.join(OUT, name + '.png'), Buffer.from(r.data, 'base64')); };
  const measure = fs.readFileSync(path.join(ROOT, 'tests', 'ButtonContrast', 'measure.js'), 'utf8');
  const bad = [];
  const note = (ok, msg) => { console.log((ok ? 'ok    ' : 'CHECK ') + msg); if (!ok) bad.push(msg); };
  const contrast = {};

  const sizes = [['1366x768', 1366, 768], ['1832x974', 1832, 974], ['tablet-768x1024', 768, 1024], ['phone-390x844', 390, 844]];
  for (const [name, w, h] of sizes) {
    await b.viewport(w, h);
    await b.setCookie(null);
    await b.go('/admin/login');
    const m = await b.evalJs(`(() => {
      const vis = (s) => { const e = document.querySelector(s); return !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().width > 0; };
      const card = document.querySelector('.al-card').getBoundingClientRect();
      const btn = document.querySelector('.al-submit').getBoundingClientRect();
      return { sw: document.documentElement.scrollWidth, iw: innerWidth, tiles: vis('.al-tiles'), intro: vis('.al-intro'), cardL: card.left, cardR: card.right, btnH: btn.height,
               font: getComputedStyle(document.querySelector('.al-heading')).fontFamily.split(',')[0] };
    })()`);
    note(m.sw <= m.iw, `${name}: no horizontal overflow (${m.sw} <= ${m.iw})`);
    note(w < 860 ? !m.intro && !m.tiles : m.intro && m.tiles, `${name}: intro tiles ${w < 860 ? 'hidden' : 'shown'}`);
    note(m.cardL >= 0 && m.cardR <= m.iw, `${name}: login box inside the viewport`);
    note(m.btnH >= 44, `${name}: Sign in button ${Math.round(m.btnH)}px tall`);
    note(/DM Sans/.test(m.font), `${name}: heading font ${m.font}`);
    await shot(`admin-login-${name}`);
    const c = await b.evalJs(`(${measure.replace(/;\s*$/, '')})`).catch(async () => b.evalJs(measure));
    contrast[name] = c && { count: c.count, failures: c.failures };
    note(c && c.failures.length === 0, `${name}: button contrast failures = ${c ? c.failures.length : 'n/a'}`);
  }

  // Show / Hide
  await b.viewport(1366, 768);
  await b.go('/admin/login');
  await b.evalJs(`document.getElementById('admin-password').value = 'abc'; document.querySelector('.password-toggle').click();`);
  const shown = await b.evalJs(`document.getElementById('admin-password').type`);
  await b.evalJs(`document.querySelector('.password-toggle').click();`);
  const hidden = await b.evalJs(`document.getElementById('admin-password').type`);
  note(shown === 'text' && hidden === 'password', `Show/Hide toggles the field (${shown} -> ${hidden})`);

  // Error banner: wrong credentials for an unknown account
  for (const [name, w, h] of [['error-1366x768', 1366, 768], ['error-phone-390x844', 390, 844]]) {
    await b.viewport(w, h);
    await b.setCookie(null);
    await b.go('/admin/login');
    await b.evalJs(`document.getElementById('admin-email').value = 'nobody@example.test'; document.getElementById('admin-password').value = 'wrong-password'; document.getElementById('admin-remember').checked = true; document.querySelector('form[action$="/admin/login"]').submit();`);
    for (let i = 0; i < 30; i++) { await sleep(500); if (await b.evalJs(`!!document.getElementById("admin-email") && location.pathname === "/admin/login" && !!document.querySelector(".lp-notice")`)) break; }
    const e = await b.evalJs(`(() => { const n = document.querySelector('.al-card .lp-notice--problem'); return { has: !!n, text: n ? n.innerText : '', email: document.getElementById('admin-email').value, sw: document.documentElement.scrollWidth, iw: innerWidth }; })()`);
    note(e.has && /Incorrect email or password/.test(e.text), `${name}: error banner shown in the card`);
    note(e.email === 'nobody@example.test', `${name}: email kept after the error`);
    note(e.sw <= e.iw, `${name}: no overflow with the banner`);
    await shot(`admin-login-${name}`);
    if (w === 1366) {
      const c = await b.evalJs(measure);
      note(c.failures.length === 0, `${name}: button contrast failures = ${c.failures.length}`);
    }
  }

  fs.writeFileSync(path.join(OUT, '_checks.json'), JSON.stringify({ contrast, failures: bad }, null, 2));
  b.close();
  console.log(bad.length ? `\n${bad.length} CHECK(S) FAILED` : '\nall ok');
  process.exit(bad.length ? 1 : 0);
})();
